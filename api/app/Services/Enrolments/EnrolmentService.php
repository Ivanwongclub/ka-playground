<?php

namespace App\Services\Enrolments;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Consent\ConsentSigningService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * S04A: enrolment as INTENT (OD-31). Creation records the acting guardian
 * (2.22); consent gates the pool (OD-34/50a); no seat, no money, no capacity —
 * those belong to 成團 (S05) and the trigger (S04B). Every transition is
 * audited with its actor (BI-8); Completed is terminal (OD-65).
 */
class EnrolmentService
{
    /** @var array<string, string[]> transition map — the only legal moves */
    public const TRANSITIONS = [
        'submitted' => ['pending_consent', 'withdrawn'],
        'pending_consent' => ['in_pool', 'withdrawn'],
        'in_pool' => ['pending_consent', 'teamed', 'withdrawn', 'released'],
        'teamed' => ['in_pool', 'confirmed', 'withdrawn'],
        // S05-4: dissolution (OD-38) re-pools a confirmed member back to the
        // awaiting-a-team pool, keeping their paid order (no re-charge, no refund).
        'confirmed' => ['active', 'withdrawn', 'in_pool'],
        'active' => ['completed', 'withdrawn'],
        'completed' => [], // terminal, OD-65
        'withdrawn' => [],
        'released' => [],
    ];

    public function __construct(private readonly AuditService $audit) {}

    /** Guardian-context creation: BI-4 duplicate submit returns the ORIGINAL. */
    public function create(int $programmeId, int $studentId, User $actingGuardian): object
    {
        $programme = DB::table('programmes')->where('id', $programmeId)->first();
        if ($programme === null || $programme->status !== 'published') {
            throw ValidationException::withMessages(['programme' => ['Programme is not open for enrolment']]);
        }
        $linked = DB::table('guardian_links')->where('guardian_id', $actingGuardian->id)
            ->where('student_id', $studentId)->where('status', 'active')->exists();
        if (! $linked) {
            // the DB WITH CHECK enforces this too; this is the friendly refusal
            throw ValidationException::withMessages(['student' => ['You may only enrol a student you are an active guardian of']]);
        }

        $existing = $this->live($programmeId, $studentId);
        if ($existing !== null) {
            return $existing; // BI-4: the original, never a second one
        }

        $id = (string) Str::uuid7();
        try {
            DB::table('enrolments')->insert([
                'id' => $id, 'programme_id' => $programmeId, 'student_id' => $studentId,
                'acting_guardian_id' => $actingGuardian->id, 'status' => 'submitted',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->live($programmeId, $studentId); // concurrent duplicate → original
        }
        $this->audit->record('enrolment', $id, 'enrolment.submitted',
            toState: 'submitted', programmeId: $programmeId,
            payloadAfter: ['student_id' => $studentId, 'acting_guardian_id' => $actingGuardian->id],
            actor: $actingGuardian);
        // OD-66: raise the event; S09 delivers
        \App\Jobs\IssueConsentRequests::dispatch($id)->afterCommit();

        return DB::table('enrolments')->where('id', $id)->first();
    }

    /** The single guarded mutation path (enr_update policy admits system only). */
    public function transition(string $enrolmentId, string $to, ?User $actor, ?string $reason = null): void
    {
        $enrolment = DB::table('enrolments')->where('id', $enrolmentId)->first()
            ?? throw new \RuntimeException("Enrolment {$enrolmentId} not found");
        if ($enrolment->status === $to) {
            return;
        }
        if (! in_array($to, self::TRANSITIONS[$enrolment->status] ?? [], true)) {
            throw new \RuntimeException("Illegal enrolment transition {$enrolment->status} → {$to}");
        }
        DB::table('enrolments')->where('id', $enrolmentId)->update(['status' => $to, 'updated_at' => now()]);
        $this->audit->record('enrolment', $enrolmentId, "enrolment.{$to}",
            fromState: $enrolment->status, toState: $to, reason: $reason,
            programmeId: (int) $enrolment->programme_id, actor: $actor);
        // OD-66: every transition raises its catalogued event; S09 delivers
        \App\Events\EnrolmentTransitioned::dispatch($enrolmentId, $enrolment->status, $to);
    }

    /**
     * The consent gate (OD-34/50a), BOTH directions: satisfied → In Pool;
     * a supersede/void that breaks satisfaction pulls the enrolment BACK to
     * Pending Consent. Never touches Teamed or beyond (成團 stakes are S05's).
     */
    public function evaluateConsentGate(int $programmeId, int $studentId, ?User $actor, ?string $reason = null): void
    {
        $enrolment = $this->live($programmeId, $studentId);
        if ($enrolment === null || ! in_array($enrolment->status, ['pending_consent', 'in_pool'], true)) {
            return;
        }
        $satisfied = app(ConsentSigningService::class)->consentSatisfied($programmeId, $studentId);
        $target = $satisfied ? 'in_pool' : 'pending_consent';
        if ($enrolment->status !== $target) {
            $this->transition($enrolment->id, $target, $actor, $reason ?? 'consent gate re-evaluated');
        }
    }

    private function live(int $programmeId, int $studentId): ?object
    {
        return DB::table('enrolments')->where('programme_id', $programmeId)
            ->where('student_id', $studentId)
            ->whereNotIn('status', ['completed', 'withdrawn', 'released'])->first();
    }
}
