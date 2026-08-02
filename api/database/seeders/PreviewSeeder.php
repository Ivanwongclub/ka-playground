<?php

namespace Database\Seeders;

use App\Models\Programme;
use App\Models\User;
use App\Services\Consent\ConsentSigningService;
use App\Services\Consent\ConsentTemplateService;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Money\OrderService;
use App\Services\Money\PaymentLinkService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Local PREVIEW dataset (not part of the build). Drives the REAL S04A/S04B
 * services end to end so the money surfaces have live data. Runs in the
 * console's system context. Idempotent by the DEMO-STEM programme marker;
 * always mints a fresh live payment link for the "Zoe" order so the /pay page
 * can be previewed. All accounts use password 'password'; all data synthetic.
 */
class PreviewSeeder extends Seeder
{
    public function run(): void
    {
        // HARD local-only guard: this tool seeds accounts with a known password and
        // must be physically incapable of running anywhere but a developer's machine.
        if (! app()->environment('local')) {
            throw new \RuntimeException('PreviewSeeder is local-only — it seeds a known password and must never run outside the local environment.');
        }

        $acct = function (string $email, string $name, string $role): User {
            $u = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'role' => $role, 'password' => Hash::make('password'), 'email_verified_at' => now()],
            );
            // account.provenance (S04C-4): every account traces to an audited origin
            // — even seeded ones, so the demo is as honest as the real paths.
            if (! DB::table('audit_events')->where('entity_type', 'user')->where('entity_id', (string) $u->id)->whereIn('action', ['user.created', 'bootstrap.super_admin'])->exists()) {
                DB::table('audit_events')->insert([
                    'event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'entity_type' => 'user',
                    'entity_id' => (string) $u->id, 'action' => 'user.created', 'to_state' => 'registered',
                    'actor_role' => 'system', 'request_id' => (string) Str::uuid7(),
                    'payload_after' => json_encode(['origin' => 'seed_demo', 'role' => $role]),
                ]);
            }

            return $u;
        };
        $cap = function (User $u, string $cap): void {
            if (! DB::table('admin_capabilities')->where('user_id', $u->id)->where('capability', $cap)->exists()) {
                DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => $cap, 'granted_by' => $u->id, 'granted_at' => now()]);
            }
        };

        // ── accounts ──
        $super = $acct('super@demo.ka', 'Ada Super (demo)', 'academy_admin');
        $cap($super, 'super_admin');
        $ops = $acct('ops@demo.ka', 'Otis Ops (demo)', 'academy_admin');
        $cap($ops, 'configuration');
        $cap($ops, 'operations');
        $fin1 = $acct('finance1@demo.ka', 'Fiona Finance (demo)', 'academy_admin');
        $fin2 = $acct('finance2@demo.ka', 'Frank Finance (demo)', 'academy_admin');
        $cap($fin1, 'finance');
        $cap($fin2, 'finance');
        $audit = $acct('audit@demo.ka', 'Aria Audit (demo)', 'academy_admin');
        $cap($audit, 'audit_read');
        $guardian = $acct('wendy@demo.ka', 'Wendy Chan (demo)', 'guardian');
        $students = [
            'sam' => $acct('sam@demo.ka', 'Sam Chan (demo)', 'student'),
            'mia' => $acct('mia@demo.ka', 'Mia Chan (demo)', 'student'),
            'kai' => $acct('kai@demo.ka', 'Kai Chan (demo)', 'student'),
            'zoe' => $acct('zoe@demo.ka', 'Zoe Chan (demo)', 'student'),
        ];
        foreach ($students as $s) {
            DB::table('guardian_links')->updateOrInsert(
                ['student_id' => $s->id, 'guardian_id' => $guardian->id],
                ['id' => (string) Str::uuid7(), 'status' => 'active', 'origin' => 'onboarding', 'updated_at' => now(), 'created_at' => now()],
            );
            // FLAG #2 (S04C-3): every active guardian_link — even seeded — must
            // carry its to_state='active' activation audit, so the demo DB is as
            // honest as the real paths and links.activation_audited holds.
            $linkId = DB::table('guardian_links')->where('student_id', $s->id)->where('guardian_id', $guardian->id)->value('id');
            if (! DB::table('audit_events')->where('entity_type', 'guardian_link')->where('entity_id', $linkId)->where('to_state', 'active')->exists()) {
                DB::table('audit_events')->insert([
                    'event_id' => (string) Str::uuid7(), 'occurred_at' => now(),
                    'entity_type' => 'guardian_link', 'entity_id' => $linkId,
                    'action' => 'guardian_link.created', 'to_state' => 'active',
                    'actor_role' => 'system', 'request_id' => (string) Str::uuid7(),
                ]);
            }
        }

        // ── published programme + consent template + fee (HKD 2,500) ──
        $templates = app(ConsentTemplateService::class);
        $programme = Programme::query()->firstOrCreate(
            ['code' => 'DEMO-STEM'],
            ['name_en' => 'Summer STEM 2026', 'name_tc' => '2026 夏季 STEM', 'name_sc' => '2026 夏季 STEM', 'jurisdiction' => 'HK', 'status' => 'draft'],
        );
        $firstRun = $programme->status !== 'published';
        if ($firstRun) {
            $templateId = $templates->createTemplate(['name_en' => 'STEM Consent', 'name_tc' => 'STEM 同意書', 'name_sc' => 'STEM 同意书'], $ops);
            foreach ([
                'en' => '<p>I consent to {{student_name}} joining {{programme_name}} (fee {{fee_total}}). {{signature}} {{signature_date}}</p>',
                'zh-TC' => '<p>本人同意 {{student_name}} 參加 {{programme_name}}(費用 {{fee_total}})。{{signature}} {{signature_date}}</p>',
                'zh-SC' => '<p>本人同意 {{student_name}} 参加 {{programme_name}}(费用 {{fee_total}})。{{signature}} {{signature_date}}</p>',
            ] as $lang => $body) {
                $vid = $templates->draftVersion($templateId, $lang, $body, $ops);
                $templates->publishVersion($vid, $ops);
            }
            foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $key) {
                $data = match ($key) {
                    'fees' => ['has_fee_items' => true],
                    'consent' => ['template_ref' => $templateId],
                    'basics' => ['enrolment_closes_on' => '2026-06-01', 'starts_on' => '2026-07-15'],
                    'team_rules' => ['formation_deadline_on' => '2026-06-20'],
                    default => ['x' => 1],
                };
                DB::table('wizard_sections')->updateOrInsert(
                    ['programme_id' => $programme->id, 'section_key' => $key],
                    ['id' => (string) Str::uuid7(), 'status' => 'complete', 'data' => json_encode($data), 'updated_by' => $ops->id, 'updated_at' => now(), 'created_at' => now()],
                );
            }
            DB::table('fee_items')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $programme->id,
                'name_en' => 'Programme fee', 'name_tc' => '課程費用', 'name_sc' => '课程费用',
                'amount_minor' => 250000, 'currency' => 'HKD', 'created_at' => now(), 'updated_at' => now()]);
            $programme->update(['status' => 'published']);
        }

        $enrolments = app(EnrolmentService::class);
        $signing = app(ConsentSigningService::class);
        $orders = app(OrderService::class);
        $links = app(PaymentLinkService::class);

        $enrol = function (User $student) use ($enrolments, $programme, $guardian): string {
            $existing = DB::table('enrolments')->where('programme_id', $programme->id)->where('student_id', $student->id)
                ->whereNotIn('status', ['withdrawn', 'released'])->value('id');
            if ($existing) {
                return $existing;
            }
            $id = $enrolments->create($programme->id, $student->id, $guardian)->id;
            dispatch_sync(new \App\Jobs\IssueConsentRequests($id)); // issue requests + → pending_consent
            return $id;
        };
        $sign = function (User $student) use ($signing, $enrolments, $programme, $guardian): void {
            $req = DB::table('consent_requests')->where('programme_id', $programme->id)
                ->where('student_id', $student->id)->where('signer_id', $guardian->id)
                ->whereIn('status', ['sent', 'viewed'])->first();
            if (! $req) {
                return;
            }
            $signing->renderForSigner($req, 'en', $guardian);
            $req = DB::table('consent_requests')->where('id', $req->id)->first();
            $signing->recordScrolledToEnd($req, $guardian);
            $req = DB::table('consent_requests')->where('id', $req->id)->first();
            $signing->sign($req, ['affirmed' => true, 'method' => 'typed', 'typed_name' => 'Wendy Chan'], $guardian, '127.0.0.1', 'preview-seed');
            $enrolments->evaluateConsentGate($programme->id, $student->id, $guardian); // → in_pool
        };
        $confirmOrder = function (User $student) use ($enrolments, $orders, $ops): object {
            $id = DB::table('enrolments')->where('student_id', $student->id)->orderByDesc('created_at')->value('id');
            foreach (['teamed', 'confirmed'] as $to) { // fixture 成團 (real transaction is S05)
                if (in_array(DB::table('enrolments')->where('id', $id)->value('status'), ['in_pool', 'teamed'], true)) {
                    $enrolments->transition($id, $to, $ops, 'preview fixture 成團');
                }
            }
            return $orders->issueForEnrolment($id, 'guardian', null, $ops);
        };

        // Sam — awaiting consent (the gate, not yet signed)
        $enrol($students['sam']);
        // Mia — consent signed → awaiting a team (in the pool)
        $enrol($students['mia']);
        $sign($students['mia']);
        // Kai — confirmed → order → PAID via a link → receipt (real money path)
        $enrol($students['kai']);
        $sign($students['kai']);
        $kaiOrder = $confirmOrder($students['kai']);
        if ($kaiOrder->status === 'issued') {
            $mint = $links->mint($kaiOrder->id, $guardian);
            $links->confirmPayment(basename(parse_url($mint['url'], PHP_URL_PATH))); // pay → receipt, link dies
        }
        // Zoe — confirmed → order → LIVE payment link for preview
        $enrol($students['zoe']);
        $sign($students['zoe']);
        $zoeOrder = $confirmOrder($students['zoe']);
        DB::table('payment_links')->where('order_id', $zoeOrder->id)->where('status', 'active')->update(['status' => 'expired']); // retire stale demo links
        $zoeLink = $links->mint($zoeOrder->id, $guardian);

        // ── S-UX3-1: pending items for the approval queues (fresh → under the 7-day escalation
        // threshold, so no open exception is required; all satisfy the provenance assertions) ──
        // Pending registration (a student self-registering, naming Wendy as counterpart guardian).
        if (! DB::table('registration_requests')->where('applicant_email', 'nina.pending@demo.ka')->exists()) {
            DB::table('registration_requests')->insert([
                'id' => (string) Str::uuid7(), 'kind' => 'student', 'applicant_name' => 'Nina Ng (demo)',
                'applicant_email' => 'nina.pending@demo.ka', 'preferred_language' => 'en', 'routing' => 'academy',
                'school_id' => null, 'counterpart_email' => 'wendy@demo.ka', 'counterpart_name' => 'Wendy Chan (demo)',
                'status' => 'submitted', 'reference' => 'DEMO-'.Str::upper(Str::random(8)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Pending guardian link: a guardian awaiting approval on a NEW student with NO enrolment —
        // so approving it violates no invariant (coverage needs an active guardian only for students
        // WITH a non-terminal enrolment; consent issuance-completeness is N/A without an enrolment).
        // NB: approving a 2nd guardian on a student who ALREADY has a pre-team enrolment would red
        // consent.issuance_completeness — approveLink activates the link but does not re-issue consent
        // to the new guardian (server behaviour flagged for a ruling); the seed avoids that scenario.
        $gordon = $acct('gordon@demo.ka', 'Gordon Chan (demo)', 'guardian');
        $theo = $acct('theo@demo.ka', 'Theo Ng (demo)', 'student');
        DB::table('guardian_links')->updateOrInsert(
            ['student_id' => $theo->id, 'guardian_id' => $gordon->id],
            ['id' => (string) Str::uuid7(), 'status' => 'pending_approval', 'origin' => 'onboarding', 'updated_at' => now(), 'created_at' => now()],
        );
        // Pending withdrawal — driven through the REAL WithdrawalService (genuine provenance + audit);
        // pending only, so the enrolment/receipt are untouched until an admin decides.
        $withdrawals = app(\App\Services\Enrolments\WithdrawalService::class);
        $kaiEnrolmentId = DB::table('enrolments')->where('student_id', $students['kai']->id)->orderByDesc('created_at')->value('id');
        if ($kaiEnrolmentId !== null && ! DB::table('withdrawal_requests')->where('enrolment_id', $kaiEnrolmentId)->exists()) {
            $withdrawals->request($kaiEnrolmentId, 'Family relocating overseas (demo)', $guardian);
        }

        $this->command->info('');
        $this->command->info('════ PREVIEW SEED READY ════');
        $this->command->info('Password for every account: password');
        $this->command->info('Live payment link (Zoe, initials-only page): '.$zoeLink['url']);
        $this->command->info('Zoe order ref: '.Str::upper(Str::substr($zoeOrder->id, -12)));
    }
}
