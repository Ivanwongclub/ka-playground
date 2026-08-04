<?php

namespace Database\Seeders;

use App\Models\Programme;
use App\Models\User;
use App\Services\Consent\ConsentSigningService;
use App\Services\Consent\ConsentTemplateService;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Money\ManualPaymentService;
use App\Services\Money\OrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * PUBLIC-DEMO dataset for the GCP deploy (docs/deploy/GCP-MIGRATION.md §6/§7).
 *
 * DIFFERS from PreviewSeeder (which is local-only + password 'password' and must
 * stay that way) on three deliberate points:
 *   1. Runs under APP_ENV in {local, demo} — NEVER production. (Dry-run it under
 *      `local`; the CD's kap-seed job runs it under `demo`.)
 *   2. STRONG credentials from DEMO_SEED_PASSWORD (Secret Manager) — refuses a
 *      weak/absent password. No account ever ships with a guessable secret.
 *   3. SYNTHETIC-ONLY, enforced: every account email must be @demo.ka or the
 *      seeder throws. No real PII can enter a public, child-data-shaped app.
 *
 * It also seeds a SECOND, unrelated guardian family so the RLS-enforcement proof
 * (deploy/gcp/rls-proof.sh) has a genuine cross-family / cross-child boundary to
 * test, and emits the fixture ids on a machine-readable line the CD parses:
 *      RLS-PROOF-FIXTURES=<guardianA>,<guardianB>,<childB>
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * REVIEWER / LEO — BEFORE FIRST DEPLOY, TWO THINGS:
 *   (a) DRY-RUN LOCALLY and read the output:
 *         APP_ENV=local DEMO_SEED_PASSWORD='<≥16 chars>' \
 *           php artisan migrate:fresh --seed --seeder=DemoSeeder
 *       then `php artisan reconcile:run` must be 58/58 on the seeded DB.
 *   (b) The four GAP surfaces below (member invite→accept, teams 成團, teacher,
 *       school_admin) are NOT yet authored — they print a COVERAGE-GAP warning
 *       and are listed in the report. Author them (they need their real service
 *       flows, out of what this scaffold verified) before the demo is "complete".
 *       They do NOT block the RLS proof or the money/consent/guardian demo.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class DemoSeeder extends Seeder
{
    private string $password;

    public function run(): void
    {
        // ── Guard 1: never production. ──
        if (app()->environment('production')) {
            throw new \RuntimeException('DemoSeeder must never run in production.');
        }
        if (! app()->environment(['local', 'demo'])) {
            throw new \RuntimeException("DemoSeeder runs only under APP_ENV local|demo (got '".app()->environment()."').");
        }
        // ── Guard 2: strong credentials only. ──
        $pw = (string) env('DEMO_SEED_PASSWORD', '');
        if (strlen($pw) < 16 || $pw === 'password') {
            throw new \RuntimeException('DEMO_SEED_PASSWORD must be set to a strong value (≥16 chars) — a public demo ships no guessable passwords.');
        }
        $this->password = $pw;

        // ── Family A (Wendy): the primary demo family + the money/BI-9/consent flow. ──
        $ops = $this->admin('ops@demo.ka', 'Otis Ops (demo)', ['configuration', 'operations']);
        $fin1 = $this->admin('finance1@demo.ka', 'Fiona Finance (demo)', ['finance']);
        $this->admin('finance2@demo.ka', 'Frank Finance (demo)', ['finance']); // BI-9 needs a 2nd finance
        $this->admin('super@demo.ka', 'Ada Super (demo)', ['super_admin']);
        $this->admin('audit@demo.ka', 'Aria Audit (demo)', ['audit_read']);

        $guardianA = $this->account('wendy@demo.ka', 'Wendy Chan (demo)', 'guardian');
        $sam = $this->account('sam@demo.ka', 'Sam Chan (demo)', 'student');
        $quinn = $this->account('quinn@demo.ka', 'Quinn Chan (demo)', 'student');
        $this->activeGuardianLink($guardianA, $sam);
        $this->activeGuardianLink($guardianA, $quinn);

        // ── Family B (Priya): a SEPARATE family — the cross-family RLS boundary. ──
        $guardianB = $this->account('priya@demo.ka', 'Priya Rao (demo)', 'guardian');
        $bella = $this->account('bella@demo.ka', 'Bella Rao (demo)', 'student');
        $this->activeGuardianLink($guardianB, $bella);

        // ── Published programme + trilingual consent template + fee (real services). ──
        $programme = $this->publishProgramme($ops);

        $enrolments = app(EnrolmentService::class);
        $signing = app(ConsentSigningService::class);
        $orders = app(OrderService::class);

        // Sam — enrolled, awaiting consent (the consent-gate demo).
        $this->enrol($enrolments, $programme, $guardianA, $sam);

        // Quinn — enrolled → consented → confirmed → order → a manual payment RECORDED
        // by finance1 (pending_confirmation): the live BI-9 second-officer moment.
        $this->enrol($enrolments, $programme, $guardianA, $quinn);
        $this->sign($signing, $enrolments, $programme, $guardianA, $quinn);
        $quinnOrder = $this->confirmOrder($enrolments, $orders, $ops, $quinn);
        $this->recordManualPayment($quinnOrder, $fin1);

        // Bella (family B) — enrolled + consented so she owns an enrolment row that
        // guardian A must be PROVABLY unable to read (the cross-child RLS assertion).
        $this->enrol($enrolments, $programme, $guardianB, $bella);
        $this->sign($signing, $enrolments, $programme, $guardianB, $bella);

        // R3 activation for the started programme (as the scheduled job does).
        app(\App\Services\Enrolments\EnrolmentActivationService::class)->run();

        // ── NOT-YET-AUTHORED coverage (flagged, not faked). ──
        $gaps = $this->reportGaps();

        // ── Machine-readable fixtures for deploy/gcp/rls-proof.sh ──
        $this->command->info('');
        $this->command->info('════ DEMO SEED READY (synthetic; strong creds) ════');
        $this->command->line("RLS-PROOF-FIXTURES={$guardianA->id},{$guardianB->id},{$bella->id}");
        $this->command->info("Family A guardian: wendy@demo.ka · Family B guardian: priya@demo.ka");
        if ($gaps) {
            $this->command->warn('COVERAGE GAPS (author before the demo is complete): '.implode(', ', $gaps));
        }
    }

    /** Create a synthetic account with an audited origin (provenance). */
    private function account(string $email, string $name, string $role): User
    {
        if (! str_ends_with($email, '@demo.ka')) {
            throw new \RuntimeException("DemoSeeder is synthetic-only: '{$email}' is not a @demo.ka address.");
        }
        $u = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'role' => $role, 'password' => Hash::make($this->password), 'email_verified_at' => now()],
        );
        if (! DB::table('audit_events')->where('entity_type', 'user')->where('entity_id', (string) $u->id)->whereIn('action', ['user.created', 'bootstrap.super_admin'])->exists()) {
            DB::table('audit_events')->insert([
                'event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'entity_type' => 'user',
                'entity_id' => (string) $u->id, 'action' => 'user.created', 'to_state' => 'registered',
                'actor_role' => 'system', 'request_id' => (string) Str::uuid7(),
                'payload_after' => json_encode(['origin' => 'seed_demo', 'role' => $role]),
            ]);
        }

        return $u;
    }

    /** @param string[] $caps */
    private function admin(string $email, string $name, array $caps): User
    {
        $u = $this->account($email, $name, 'academy_admin');
        foreach ($caps as $cap) {
            if (! DB::table('admin_capabilities')->where('user_id', $u->id)->where('capability', $cap)->exists()) {
                DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => $cap, 'granted_by' => $u->id, 'granted_at' => now()]);
            }
        }

        return $u;
    }

    private function activeGuardianLink(User $guardian, User $student): void
    {
        DB::table('guardian_links')->updateOrInsert(
            ['student_id' => $student->id, 'guardian_id' => $guardian->id],
            ['id' => (string) Str::uuid7(), 'status' => 'active', 'origin' => 'onboarding', 'updated_at' => now(), 'created_at' => now()],
        );
        $linkId = DB::table('guardian_links')->where('student_id', $student->id)->where('guardian_id', $guardian->id)->value('id');
        if (! DB::table('audit_events')->where('entity_type', 'guardian_link')->where('entity_id', $linkId)->where('to_state', 'active')->exists()) {
            DB::table('audit_events')->insert([
                'event_id' => (string) Str::uuid7(), 'occurred_at' => now(),
                'entity_type' => 'guardian_link', 'entity_id' => $linkId,
                'action' => 'guardian_link.created', 'to_state' => 'active',
                'actor_role' => 'system', 'request_id' => (string) Str::uuid7(),
            ]);
        }
    }

    private function publishProgramme(User $ops): Programme
    {
        $templates = app(ConsentTemplateService::class);
        $programme = Programme::query()->firstOrCreate(
            ['code' => 'DEMO-STEM'],
            ['name_en' => 'Summer STEM 2026', 'name_tc' => '2026 夏季 STEM', 'name_sc' => '2026 夏季 STEM', 'jurisdiction' => 'HK', 'status' => 'draft'],
        );
        if ($programme->status === 'published') {
            return $programme;
        }
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

        return $programme;
    }

    private function enrol(EnrolmentService $enrolments, Programme $programme, User $guardian, User $student): void
    {
        $existing = DB::table('enrolments')->where('programme_id', $programme->id)->where('student_id', $student->id)
            ->whereNotIn('status', ['withdrawn', 'released'])->exists();
        if ($existing) {
            return;
        }
        $id = $enrolments->create($programme->id, $student->id, $guardian)->id;
        dispatch_sync(new \App\Jobs\IssueConsentRequests($id));
    }

    private function sign(ConsentSigningService $signing, EnrolmentService $enrolments, Programme $programme, User $guardian, User $student): void
    {
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
        $signing->sign($req, ['affirmed' => true, 'method' => 'typed', 'typed_name' => $guardian->name], $guardian, '127.0.0.1', 'demo-seed');
        $enrolments->evaluateConsentGate($programme->id, $student->id, $guardian);
    }

    private function confirmOrder(EnrolmentService $enrolments, OrderService $orders, User $ops, User $student): object
    {
        $id = DB::table('enrolments')->where('student_id', $student->id)->orderByDesc('created_at')->value('id');
        foreach (['teamed', 'confirmed'] as $to) { // fixture 成團 (the real formation transaction is S05)
            if (in_array(DB::table('enrolments')->where('id', $id)->value('status'), ['in_pool', 'teamed'], true)) {
                $enrolments->transition($id, $to, $ops, 'demo fixture 成團');
            }
        }

        return $orders->issueForEnrolment($id, 'guardian', null, $ops);
    }

    private function recordManualPayment(object $order, User $fin1): void
    {
        if ($order->status !== 'issued' || DB::table('payments')->where('order_id', $order->id)->exists()) {
            return;
        }
        // A real clean image → the real ClamAV scan pipeline (BI-10); a genuine photo scans clean.
        $src = base_path('../web/public/assets/auth/featured-sc5.jpg');
        $tmp = tempnam(sys_get_temp_dir(), 'ev').'.jpg';
        copy($src, $tmp);
        $evidence = new \Illuminate\Http\UploadedFile($tmp, 'evidence.jpg', 'image/jpeg', null, true);
        app(ManualPaymentService::class)->record($order->id, (int) $order->total_amount_minor, $order->currency, [$evidence], 'Bank transfer received (demo)', $fin1);
    }

    /**
     * The surfaces this scaffold does NOT yet seed. Each needs its real service
     * flow authored + a local dry-run. Flagged loudly rather than faked.
     *
     * @return string[]
     */
    private function reportGaps(): array
    {
        return [
            'member(invite→accept)',   // §7: Community/directory surfaces empty until seeded
            'teams(成團)',              // real formation transaction (S05) — the fixture above only confirms enrolments
            'teacher(attendance/My Students)',
            'school_admin',
        ];
    }
}
