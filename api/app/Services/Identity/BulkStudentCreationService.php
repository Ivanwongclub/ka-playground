<?php

namespace App\Services\Identity;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S04D STEP 4 (OD-23 pt 5 · OD-50) — a school creates the students on its OWN
 * roll in bulk. Rows, not CSV (batches are S04E).
 *
 * Guarantees the review demands:
 *  - ROW-BY-ROW with a full report — never a silent partial. Every row lands in
 *    exactly one of created / skipped / rejected; a mid-batch failure rejects
 *    that row and the batch continues, so state is always explained.
 *  - ROLL AUTHORITY per row: a row whose school the admin does not administer is
 *    rejected (not just an endpoint-level check).
 *  - IDEMPOTENT: a re-uploaded batch matches existing accounts by email and skips
 *    them — no duplicates.
 *  - The account is minted by AccountMintingService::mintPendingActivation (the
 *    ONE born-unverified + activation-token primitive, D-i-2) — bulk students
 *    carry no usable password until activation, exactly like the online route.
 *  - The active school_links write + its to_state='active' audit run in the
 *    system context (the elevation) — the S04D hardening requires it, and
 *    no_active_without_approval / account.provenance stay green.
 */
class BulkStudentCreationService
{
    public function __construct(
        private readonly AccountMintingService $minting,
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    /**
     * @param  list<array{name:string,email:string,school_id:int}>  $rows
     * @return array{created: list<array<string,mixed>>, skipped: list<array<string,string>>, rejected: list<array<string,string>>}
     */
    public function create(User $admin, array $rows): array
    {
        return $this->scope->asSystem(
            'Bulk student creation (OD-23 pt 5 / OD-50): a school admin creates the students on its OWN roll. The accounts and their school affiliations are outside the admin\'s derived scope until they exist, and the active school_links write is system-context by construction (S04D hardening). Per-row roll authority is enforced from the admin\'s own school_admin_links; mints born-unverified via mintPendingActivation; audits user.created + school_link.created(active) to the admin.',
            function () use ($rows, $admin): array {
                // The admin's roll authority: the schools they actively administer.
                $adminSchools = DB::table('school_admin_links')
                    ->where('school_admin_id', $admin->id)->where('status', 'active')
                    ->pluck('school_id')->map(fn ($x) => (int) $x)->all();

                $report = ['created' => [], 'skipped' => [], 'rejected' => []];

                foreach ($rows as $row) {
                    $email = $row['email'];
                    // roll authority — per row
                    if (! in_array((int) $row['school_id'], $adminSchools, true)) {
                        $report['rejected'][] = ['email' => $email, 'reason' => 'not a school you administer (OD-30 roll authority)'];

                        continue;
                    }
                    // idempotency — an existing account is matched and skipped
                    if (DB::table('users')->where('email', $email)->exists()) {
                        $report['skipped'][] = ['email' => $email, 'reason' => 'account already exists'];

                        continue;
                    }
                    // create the row in its OWN transaction — a failure rejects only this row
                    try {
                        $student = DB::transaction(function () use ($row, $email, $admin): User {
                            $student = $this->minting->mintPendingActivation($row['name'], $email, 'student')['user'];
                            $linkId = (string) Str::uuid7();
                            DB::table('school_links')->insert([
                                'id' => $linkId, 'student_id' => $student->id, 'school_id' => $row['school_id'],
                                'status' => 'active', 'origin' => 'bulk', 'created_at' => now(), 'updated_at' => now(),
                            ]);
                            $this->audit->record('school_link', $linkId, 'school_link.created',
                                toState: 'active',
                                payloadAfter: ['student_id' => $student->id, 'school_id' => $row['school_id'], 'origin' => 'bulk'],
                                actor: $admin);
                            $this->audit->record('user', $student->id, 'user.created',
                                toState: 'registered',
                                payloadAfter: ['origin' => 'bulk_creation', 'role' => 'student', 'verified' => false],
                                actor: $admin);

                            return $student;
                        });
                        $report['created'][] = ['email' => $email, 'student_id' => $student->id];
                    } catch (\Throwable $e) {
                        $report['rejected'][] = ['email' => $email, 'reason' => 'creation failed'];
                    }
                }

                // batch-level record — the persistent proof of what happened.
                $this->audit->record('bulk_student_import', (string) Str::uuid7(), 'bulk.students_created',
                    payloadAfter: [
                        'created' => count($report['created']),
                        'skipped' => count($report['skipped']),
                        'rejected' => count($report['rejected']),
                    ],
                    actor: $admin);

                return $report;
            },
        );
    }
}
