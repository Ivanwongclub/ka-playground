<?php

namespace Database\Seeders;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Consent\ConsentSigningService;
use App\Services\Consent\ConsentTemplateService;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Identity\BulkStudentCreationService;
use App\Services\Identity\InvitationService;
use App\Services\Money\ManualPaymentService;
use App\Services\Money\OrderService;
use App\Services\Teams\FormationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * PUBLIC-DEMO dataset for the GCP client-review URL (docs/deploy/GCP-MIGRATION.md §6/§7).
 * SYNTHETIC ONLY — this feeds a PUBLIC url, so every record is provably fake.
 *
 * Safety posture (the linchpin of the public demo):
 *   • Runs under APP_ENV in {local, demo} — NEVER production.
 *   • STRONG credentials from DEMO_SEED_PASSWORD (Secret Manager) — refuses weak/absent.
 *   • Every email is @example.com (RFC-2606 reserved, cannot be a real address);
 *     assertSynthetic() throws on anything else. Every name is "Demo …". No real
 *     person, address, phone, or payment datum can enter the seed.
 *
 * Built from REAL service flows (not raw inserts) wherever a service exists, so the
 * RLS proof runs against realistically-created data:
 *   guardian families + enrolments ....... EnrolmentService / ConsentSigningService
 *   payment mid-flow (BI-9) .............. ManualPaymentService::record (finance1 records; finance2 confirms)
 *   member ............................... InvitationService::issue → accept (invite→accept)
 *   teacher + teacher_links(active) ...... InvitationService::issue(role=teacher,schoolId) → accept
 *   students on a roll + school_links .... BulkStudentCreationService::create
 *   teams (/my/team) ..................... FormationService::create + ::join
 *
 * TWO fixtures have NO service anywhere in app/ (production code only READS them);
 * they are raw-inserted, matching the codebase's own tests/seeders, and FLAGGED here:
 *   (F1) school_admin_links ACTIVE — no service inserts it (SchoolAdminController etc.
 *        only read it). NOT scanned by links.no_active_without_approval, so reconcile-safe.
 *   (F2) team_categories (lobby) — ProgrammeConfigController itself raw-inserts it.
 * Neither is on the RLS-proof path (that path is guardian_links + enrolments, all real-service).
 *
 * RLS-proof fixtures (two SEPARATE families, so cross-family/cross-child denial has
 * real rows) are emitted on a machine-readable line the CD parses:
 *      RLS-PROOF-FIXTURES=<guardianA>,<guardianB>,<childB>
 */
class DemoSeeder extends Seeder
{
    private string $password;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('DemoSeeder must never run in production.');
        }
        if (! app()->environment(['local', 'demo'])) {
            throw new \RuntimeException("DemoSeeder runs only under APP_ENV local|demo (got '".app()->environment()."').");
        }
        $pw = (string) env('DEMO_SEED_PASSWORD', '');
        if (strlen($pw) < 16 || $pw === 'password') {
            throw new \RuntimeException('DEMO_SEED_PASSWORD must be a strong value (≥16 chars) — a public demo ships no guessable passwords.');
        }
        $this->password = $pw;

        // Establish the platform (system) RLS context for this seed run. On Cloud SQL
        // the seed connects as kap_migrate — NOSUPERUSER, so FORCE RLS applies and the
        // system-bootstrap inserts (admin_capabilities, guardian_links, team lobbies,
        // school_admin_links) are gated by each table's *_insert policy, whose system
        // branch is current_setting('app.context') = 'system'. The console lifecycle
        // sets this at command start, but `migrate:fresh` re-establishes the connection
        // before the seeder runs, dropping it — so set it explicitly here, on the
        // seed-time connection. This does NOT weaken RLS: 'system' is the sanctioned
        // console/seed context the policies already trust; HTTP requests never get it,
        // and the rls_proof gate still verifies cross-family/child enforcement holds.
        app(ScopeContext::class)->setSystem();

        // ── Academy staff ──
        $ops = $this->admin('ops@example.com', 'Demo Ops Admin', ['configuration', 'operations']);
        $fin1 = $this->admin('finance1@example.com', 'Demo Finance One', ['finance']);
        $fin2 = $this->admin('finance2@example.com', 'Demo Finance Two', ['finance']); // BI-9 needs a 2nd finance officer
        $this->admin('super@example.com', 'Demo Super Admin', ['super_admin']);
        $this->admin('audit@example.com', 'Demo Audit Reader', ['audit_read']);

        // ── Three published programmes, ALL through WizardService::publish (SEED-CONTRACT-1) ──
        //    stem = running (enrolment closed) · ai = enrolment OPEN now · soon = not yet open.
        $progs = $this->publishDemoProgrammes($ops);
        $programme = $progs['stem'];
        $enrolments = app(EnrolmentService::class);
        $signing = app(ConsentSigningService::class);
        $orders = app(OrderService::class);

        // ── Family A — one guardian, six children in VARIED states ──
        $guardianA = $this->account('guardianA@example.com', 'Demo Guardian A', 'guardian');
        $a = [];
        foreach (['a1', 'a2', 'a3', 'a4', 'a5', 'a6'] as $k) {
            $a[$k] = $this->account("student.{$k}@example.com", 'Demo Student '.strtoupper($k), 'student');
            $this->activeGuardianLink($guardianA, $a[$k]);
        }
        // A1 — pending-consent (enrolled, not signed)
        $this->enrol($enrolments, $programme, $guardianA, $a['a1']);
        // A2 — in-pool (enrolled + signed, awaiting a team)
        $this->enrol($enrolments, $programme, $guardianA, $a['a2']);
        $this->sign($signing, $enrolments, $programme, $guardianA, $a['a2']);
        // A3 — active + a payment MID-FLOW recorded by finance1 (the live BI-9 moment)
        $this->enrol($enrolments, $programme, $guardianA, $a['a3']);
        $this->sign($signing, $enrolments, $programme, $guardianA, $a['a3']);
        $a3Order = $this->confirmOrder($enrolments, $orders, $ops, $a['a3']);
        $this->recordManualPayment($a3Order, $fin1);
        // A4 + A5 — in-pool, then placed into a REAL team (→ teamed)
        foreach (['a4', 'a5'] as $k) {
            $this->enrol($enrolments, $programme, $guardianA, $a[$k]);
            $this->sign($signing, $enrolments, $programme, $guardianA, $a[$k]);
        }
        // A6 (R1-G) — confirmed with its order ISSUED but UNPAID, via the real order service (no
        // recordManualPayment). guardianA thus carries one payable order, so the guardian-home payment
        // TaskCard + outstanding StatCard demo populated. OD-43 green (the order artifact exists); a3's
        // recorded payment (the only receipt/BI-9 moment) is left intact.
        $this->enrol($enrolments, $programme, $guardianA, $a['a6']);
        $this->sign($signing, $enrolments, $programme, $guardianA, $a['a6']);
        $this->confirmOrder($enrolments, $orders, $ops, $a['a6']);

        // (a) A3 also enrols in the OPEN programme — the only child with 2+ enrolments, so the child hub's
        // multi-enrolment list and the "which enrolment?" drill are exercised rather than assumed.
        $this->enrol($enrolments, $progs['ai'], $guardianA, $a['a3']);

        // (g) A7 — an ACTIVE link and NO enrolment. This is the S-READ-3 picker case and the register→link→
        // enrol dead-end: the Marketplace child picker derives children from ENROLMENT rows, so before this
        // child existed the bug was invisible in demo data. GET /my/children now has something to prove.
        $a7 = $this->account('student.a7@example.com', 'Demo Student A7', 'student');
        $this->activeGuardianLink($guardianA, $a7);

        // ── Family B — a SEPARATE guardian + child (the cross-family RLS boundary) ──
        $guardianB = $this->account('guardianB@example.com', 'Demo Guardian B', 'guardian');
        $childB = $this->account('student.b1@example.com', 'Demo Student B1', 'student');
        $this->activeGuardianLink($guardianB, $childB);
        $this->enrol($enrolments, $programme, $guardianB, $childB);
        $this->sign($signing, $enrolments, $programme, $guardianB, $childB);

        // ── A REAL team for /my/team + formation surfaces (FormationService, not raw) ──
        $this->formTeam($programme, $a['a4'], $a['a5']);
        // R1-P360 (3c): pass TWO Activity-Tracker gates on Demo Team Alpha via the REAL approveGate service
        // (ops = the OD-39 approver) so a4/a5's rail and the ops Stages tab demo 2/5 mid-progress. NB: the
        // mapped "Plan+Design" pair BAILED OUT — the Plan gate's hard precondition (an ACTIVE team_budget,
        // Spec:210) needs a 4-call / 2-actor BudgetService chain (createDraft→addLine→submit→approve), which
        // trips the ruling's ">~3 calls / multi-actor" tripwire. Per the pre-authorised fallback we approve
        // Design + Pitch instead (both precondition-free for this team) — zero other seed changes.
        $this->approveDemoGates($programme, $ops);

        // R3 activation for the started programme (as the scheduled job does).
        app(\App\Services\Enrolments\EnrolmentActivationService::class)->run();

        // ── Member via the REAL invite→accept flow (InvitationService) ──
        $member = $this->inviteAndAccept($ops, 'member@example.com', 'member', null);

        // ── School: school_admin (invite→accept), teacher (school-vouched invite→accept),
        //    two students on the roll (bulk service). F1: the school_admin_links active
        //    binding is raw-inserted (no service exists) — flagged above. ──
        $this->provisionSchool($ops);
        $teacher = User::query()->where('email', 'teacher@example.com')->first();
        $this->linkDemoMentor($programme, $teacher, $ops); // S-MENTOR-1: enable the flag + link teacher@ → Demo Team Alpha
        // (c) + (f) — the full OD-23/OD-28 two-decision chain, end to end, through the real services.
        $this->seedRegistrationChain($ops, $guardianA, $programme, $enrolments);

        // ── Populate the surfaces that would otherwise render EMPTY (demo-quality). All via
        //    REAL service flows; @example.com synthetic; the RLS fixtures (B1) stay untouched. ──
        $this->seedSessions($programme, $ops, $teacher, $enrolments, $orders, $signing, $guardianB, $a['a3'], $fin1, $fin2); // attendance + RosterMark + a3 showcase booking + the settled payment (b)
        $this->seedMemberCommunity($ops, $member);                                           // Events / Directory / Profile / RSVP
        $this->seedAdminQueues($guardianA, $a['a2']);                                         // Withdrawals + onboarding/Approvals queue
        app(\App\Services\Enrolments\EnrolmentActivationService::class)->run();               // activate the session cohort (confirmed → active)
        $this->seedAssessments($programme, $ops, $a['a3']);                                   // R2-ASSESS: one Released (scored) + one mid-flight

        // ── Machine-readable fixtures for deploy/gcp/rls-proof.sh ──
        // CD-FIX-1: emitted BEFORE seedBanner() (below). The RLS-proof fixtures are the deploy gate's
        // only required output; a later cosmetic step (banner scan) must never gate their emission.
        $this->command->info('');
        $this->command->info('════ DEMO SEED READY (synthetic; @example.com; strong creds) ════');
        $this->command->line("RLS-PROOF-FIXTURES={$guardianA->id},{$guardianB->id},{$childB->id}");
        $this->command->info('Family A: guardianA (6 children, varied states) · Family B: guardianB (B1 in-pool = RLS fixture + 3 session-cohort kids)');
        $this->command->info('Populated: sessions/attendance (2 sessions + marked roster) · member (2 events, 3 directory profiles, RSVPs) · withdrawals + approvals queue');

        // KAP-MKT-1 storefront banner via the REAL intake+scan path — demo polish only. CD-FIX-1: a
        // scan-unavailable environment (e.g. the kap-seed Cloud Run job, no ClamAV) must degrade to
        // "no banner", never abort the seed. Wrap ONLY this call; every other seed step still fails loud.
        try {
            $this->seedBanner($programme, $ops);
        } catch (\Throwable $e) {
            $this->command->warn("banner seed skipped: {$e->getMessage()}");
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Every seeded email must be provably fake (@example.com, RFC-2606 reserved). */
    private function assertSynthetic(string $email): void
    {
        if (! str_ends_with($email, '@example.com')) {
            throw new \RuntimeException("DemoSeeder is synthetic-only: '{$email}' is not an @example.com address.");
        }
    }

    /** A loginable synthetic account with an audited origin (account.provenance). */
    private function account(string $email, string $name, string $role): User
    {
        $this->assertSynthetic($email);
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

    /**
     * The demo consent template — created ONCE and shared by every demo programme (one academy template,
     * three programmes, which is how a real academy works). Trilingual and published, so
     * preFlight's consent.language_versions_incomplete / language_drift checks are genuinely satisfied.
     */
    private function consentTemplate(User $ops): string
    {
        $existing = DB::table('consent_templates')->where('name_en', 'STEM Consent (demo)')->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }
        $templates = app(ConsentTemplateService::class);
        $templateId = $templates->createTemplate(['name_en' => 'STEM Consent (demo)', 'name_tc' => 'STEM 同意書（示範）', 'name_sc' => 'STEM 同意书（示范）'], $ops);
        foreach ([
            'en' => '<p>[DEMO — placeholder, non-binding] I consent to {{student_name}} joining {{programme_name}} (fee {{fee_total}}). {{signature}} {{signature_date}}</p>',
            'zh-TC' => '<p>[示範 — 佔位文字，不具約束力] 本人同意 {{student_name}} 參加 {{programme_name}}(費用 {{fee_total}})。{{signature}} {{signature_date}}</p>',
            'zh-SC' => '<p>[示范 — 占位文字，不具约束力] 本人同意 {{student_name}} 参加 {{programme_name}}(费用 {{fee_total}})。{{signature}} {{signature_date}}</p>',
        ] as $lang => $body) {
            $vid = $templates->draftVersion($templateId, $lang, $body, $ops);
            $templates->publishVersion($vid, $ops);
        }

        return $templateId;
    }

    /**
     * SEED-CONTRACT-1 — publish a demo programme THROUGH THE REAL CONTRACT.
     *
     * The seeder used to write `status = 'published'` directly. That single line was the cause of three
     * separate incidents, because it skipped everything publish() does: the demo database carried
     * 0 programme_versions, 0 programme_capacity rows and 0 withdrawal_policies, so the OD-31 seat counter,
     * the D5 config snapshot and the OD-2 provisional refund policy — the habitat of the FIX-REFUND-SEED
     * money defect — were never exercised by demo data at all.
     *
     * Now: real pre-flight, real version snapshot, real capacity seed, real provisional policy.
     *
     * `eligibility.capacity` is the load-bearing add. `capacity.unset` is only a WARNING, so publish()
     * succeeds without it and still seeds no counter — routing through the contract would have bought three
     * of its four artefacts. team_rules 2..6 keeps capacity.below_min_team clean.
     *
     * fee_items are inserted BEFORE publish because `fees.empty` is a hard pre-flight ERROR.
     *
     * @param  array<string, mixed>  $spec
     */
    private function publishProgramme(User $ops, string $templateId, array $spec): Programme
    {
        $programme = Programme::query()->firstOrCreate(
            ['code' => $spec['code']],
            ['name_en' => $spec['name_en'], 'name_tc' => $spec['name_tc'], 'name_sc' => $spec['name_sc'],
                'jurisdiction' => 'HK', 'status' => 'draft'],
        );
        if ($programme->status === 'published') {
            return $programme; // idempotent — and publish() itself refuses a non-draft
        }

        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification', 'marketing'] as $key) {
            $data = match ($key) {
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                // The FULL OD-33 timeline. `enrolment_opens_on` is new (SEED-CONTRACT-1 ruling 1): the wizard
                // now owns the whole window, and syncBasicsDates mirrors all three onto their columns.
                'basics' => [
                    'enrolment_opens_on' => $spec['opens_on'],
                    'enrolment_closes_on' => $spec['closes_on'],
                    'starts_on' => $spec['starts_on'],
                ],
                'team_rules' => ['formation_deadline_on' => $spec['formation_on'], 'min_team_size' => 2, 'max_size' => 6],
                // OD-31: without this publish() seeds NO seat counter (capacity.unset is only a warning).
                'eligibility' => ['capacity' => 30],
                // KAP-MKT-1: complete, trilingual marketing so the programme appears in the storefront
                // (the catalogue's sole safety gate is marketingLanguageGaps === []; brand_color a valid hex).
                'marketing' => $spec['marketing'],
                default => ['x' => 1],
            };
            DB::table('wizard_sections')->updateOrInsert(
                ['programme_id' => $programme->id, 'section_key' => $key],
                ['id' => (string) Str::uuid7(), 'status' => 'complete', 'data' => json_encode($data), 'updated_by' => $ops->id, 'updated_at' => now(), 'created_at' => now()],
            );
        }
        DB::table('fee_items')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $programme->id,
            'name_en' => 'Programme fee', 'name_tc' => '課程費用', 'name_sc' => '课程费用',
            'amount_minor' => $spec['fee_minor'], 'currency' => 'HKD', 'created_at' => now(), 'updated_at' => now()]);

        return app(\App\Services\Programmes\WizardService::class)->publish($programme, $ops);
    }

    /**
     * The three demo programmes. Dates are RELATIVE to the seed run for the two forward-looking ones, so the
     * demo cannot rot the way the sessions did (both "upcoming" sessions had drifted into the past by the
     * time anyone looked). OD-33 ordering — enrolment close < formation deadline < start — holds for each.
     *
     * @return array{stem: Programme, ai: Programme, soon: Programme}
     */
    private function publishDemoProgrammes(User $ops): array
    {
        $templateId = $this->consentTemplate($ops);
        $d = fn (int $days): string => now()->addDays($days)->toDateString();

        return [
            // RUNNING: started, enrolment long closed. Its window is now genuinely `closed` on the storefront
            // — which is the point of the mirror: it used to advertise "Enrolment open" 82 days after its own
            // published closing date, because closes_on lived in JSON and the chip was derived from a column
            // nothing wrote.
            'stem' => $this->publishProgramme($ops, $templateId, [
                'code' => 'DEMO-STEM', 'name_en' => 'Summer STEM 2026 (demo)', 'name_tc' => '2026 夏季 STEM（示範）', 'name_sc' => '2026 夏季 STEM（示范）',
                'opens_on' => '2026-05-01', 'closes_on' => '2026-06-01', 'formation_on' => '2026-06-20', 'starts_on' => '2026-07-15',
                'fee_minor' => 250000,
                'marketing' => [
                    'tagline' => ['en' => 'Build. Pitch. Launch.', 'tc' => '構建・展示・啟航', 'sc' => '构建・展示・启航'],
                    'category' => ['en' => 'STEM', 'tc' => 'STEM', 'sc' => 'STEM'],
                    'age_range' => ['en' => 'Ages 10–14', 'tc' => '10 至 14 歲', 'sc' => '10 至 14 岁'],
                    'duration' => ['en' => '6 weeks', 'tc' => '六週', 'sc' => '六周'],
                    'brand_color' => '#6B4E71',
                ],
            ]),
            // OPEN NOW: the enrolable programme. Without it the mirror would leave the catalogue with a single
            // closed card, and the guardian-enrol path would go from unexercised to UNEXERCISABLE.
            'ai' => $this->publishProgramme($ops, $templateId, [
                'code' => 'DEMO-AI', 'name_en' => 'AI Discovery (demo)', 'name_tc' => 'AI 探索（示範）', 'name_sc' => 'AI 探索（示范）',
                'opens_on' => $d(-30), 'closes_on' => $d(70), 'formation_on' => $d(85), 'starts_on' => $d(100),
                'fee_minor' => 180000,
                'marketing' => [
                    'tagline' => ['en' => 'Make something that thinks.', 'tc' => '打造會思考的作品', 'sc' => '打造会思考的作品'],
                    'category' => ['en' => 'AI', 'tc' => '人工智能', 'sc' => '人工智能'],
                    'age_range' => ['en' => 'Ages 12–16', 'tc' => '12 至 16 歲', 'sc' => '12 至 16 岁'],
                    'duration' => ['en' => '8 weeks', 'tc' => '八週', 'sc' => '八周'],
                    'brand_color' => '#3E5C76',
                ],
            ]),
            // COMING SOON: enrolment has not opened yet. Makes B9's "Coming soon" filter demonstrable — the
            // filter needs enrolment_opens_at in the FUTURE, which S-READ-3 now serves on the catalogue read.
            'soon' => $this->publishProgramme($ops, $templateId, [
                'code' => 'DEMO-SOON', 'name_en' => 'Design Lab 2027 (demo)', 'name_tc' => '2027 設計實驗室（示範）', 'name_sc' => '2027 设计实验室（示范）',
                'opens_on' => $d(30), 'closes_on' => $d(90), 'formation_on' => $d(105), 'starts_on' => $d(120),
                'fee_minor' => 210000,
                'marketing' => [
                    'tagline' => ['en' => 'Design for someone real.', 'tc' => '為真實的人而設計', 'sc' => '为真实的人而设计'],
                    'category' => ['en' => 'Design', 'tc' => '設計', 'sc' => '设计'],
                    'age_range' => ['en' => 'Ages 11–15', 'tc' => '11 至 15 歲', 'sc' => '11 至 15 岁'],
                    'duration' => ['en' => '10 weeks', 'tc' => '十週', 'sc' => '十周'],
                    'brand_color' => '#5C6E58',
                ],
            ]),
        ];
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
        foreach (['teamed', 'confirmed'] as $to) { // fixture Team Formation (the real formation transaction is exercised separately by formTeam)
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
        // A synthetic-but-valid JPEG (a solid colour square, generated in-container via
        // GD) → the real ClamAV scan pipeline (BI-10). Provably fake, and scans clean.
        $tmp = tempnam(sys_get_temp_dir(), 'ev').'.jpg';
        $img = imagecreatetruecolor(64, 64);
        imagefill($img, 0, 0, imagecolorallocate($img, 42, 16, 58));
        imagejpeg($img, $tmp);
        imagedestroy($img);
        $evidence = new \Illuminate\Http\UploadedFile($tmp, 'evidence.jpg', 'image/jpeg', null, true);
        app(ManualPaymentService::class)->record($order->id, (int) $order->total_amount_minor, $order->currency, [$evidence], 'Bank transfer received (demo)', $fin1);
    }

    /**
     * SEED-CONTRACT-1 (c)+(f) — the two-decision chain, walked end to end by real services.
     *
     * OD-23/OD-28 say approving a PERSON is not approving a RELATIONSHIP: two decisions, separately recorded,
     * separately audited. Demo data never walked it, so two consequences were invisible:
     *   · no enrolled child had a school_link, so `school_name` was NULL on every enrolment read and the
     *     school chip on the child hub / enrolment space could not render at all;
     *   · every guardian_link was `active`, so the nameless pending row S-READ-3 serves had no example.
     *
     * C1 — registers naming the school → ops approves the PERSON (this creates the ACTIVE school_link and a
     *      PENDING guardian link, never an active one) → ops approves the LINK → enrolled. School-linked.
     * C2 — same path, and the link is deliberately LEFT `pending_approval`. Nameless in /my/children by
     *      design (ruling F-1), and a live row in the guardian-link review queue.
     */
    private function seedRegistrationChain(User $ops, User $guardianA, Programme $programme, EnrolmentService $enrolments): void
    {
        $school = School::query()->where('name_en', 'Demo Academy')->first();
        if ($school === null || DB::table('registration_requests')->where('applicant_email', 'student.c1@example.com')->exists()) {
            return; // needs the school; idempotent
        }
        $registration = app(\App\Services\Identity\RegistrationService::class);
        $approvals = app(\App\Services\Identity\RegistrationApprovalService::class);
        $linkage = app(\App\Services\Identity\LinkageService::class);

        foreach ([['c1', 'Demo Student C1', true], ['c2', 'Demo Student C2', false]] as [$key, $name, $approveTheLink]) {
            $email = "student.{$key}@example.com";
            $this->assertSynthetic($email);
            // The REAL public submission, honeypot and all: `form_nonce` is the encrypted issue-time the form
            // mints, and looksAutomated() drops anything filled in under 2s or over an hour. 30s is a human.
            $registration->submit([
                'kind' => 'student', 'applicant_name' => $name, 'applicant_email' => $email,
                'preferred_language' => 'en', 'school_id' => $school->id,
                'counterpart_email' => $guardianA->email, 'counterpart_name' => $guardianA->name,
                'form_nonce' => \Illuminate\Support\Facades\Crypt::encryptString((string) now()->subSeconds(30)->timestamp),
            ]);
            $requestId = DB::table('registration_requests')->where('applicant_email', $email)->value('id');

            // DECISION 1 — the person. Creates the account + the ACTIVE school_link, and (via
            // linkCounterpartAtApproval) a PENDING guardian link. Never an active one.
            $student = $approvals->approve((string) $requestId, $ops);
            $this->activateSeededAccount($student);

            $linkId = DB::table('guardian_links')->where('student_id', $student->id)
                ->where('guardian_id', $guardianA->id)->value('id');
            if ($linkId === null || ! $approveTheLink) {
                continue; // C2 stops here — the pending link IS the fixture
            }
            // DECISION 2 — the relationship. Separately recorded, separately audited.
            $linkage->approveLink((string) $linkId, $ops);
            $this->enrol($enrolments, $programme, $guardianA, $student);
        }
    }

    /**
     * A registration-approved account is minted PENDING ACTIVATION (it owns its own address, OD-29), so it
     * cannot log in. The demo needs these two to be loginable like every other seeded account, so the
     * password and verification are set the same way account() does — the ONLY divergence from the real
     * flow, and it is a demo-login convenience, not a state the platform cannot reach (activation reaches it).
     */
    private function activateSeededAccount(User $student): void
    {
        $student->forceFill([
            'password' => Hash::make($this->password),
            'email_verified_at' => $student->email_verified_at ?? now(),
        ])->save();
    }

    /**
     * Record AND confirm a manual payment — the complete BI-9 four-eyes cycle, which no demo record had ever
     * completed (0 paid orders, 0 receipts before this).
     *
     * `confirm` dispatches FinalizeManualPayment (order → paid + gapless receipt) afterCommit, and this
     * project runs QUEUE_CONNECTION=database, so in a seeder it would sit in the queue and the demo would
     * still show no receipt. Running it inline is safe: the job returns early unless the order is still
     * `issued`, so the queued copy is a harmless no-op.
     */
    private function settleManualPayment(object $order, User $fin1, User $fin2): void
    {
        $this->recordManualPayment($order, $fin1);
        $payment = DB::table('payments')->where('order_id', $order->id)
            ->where('status', 'pending_confirmation')->first();
        if ($payment === null) {
            return; // already settled on an earlier run
        }

        // BI-10 gates confirmation on the evidence being scan-CLEAN, and the scan is queued
        // (QUEUE_CONNECTION=database), so the seeder must run it inline or confirm() refuses. Running the
        // real ScanUpload job is the point — this seeds a payment that passed the actual scan, not one that
        // sidestepped it.
        $evidence = DB::table('payment_evidence')->where('payment_id', $payment->id)->pluck('upload_id');
        foreach ($evidence as $uploadId) {
            try {
                dispatch_sync(new \App\Jobs\ScanUpload($uploadId));
            } catch (\Throwable $e) {
                $this->command->warn("evidence scan unavailable: {$e->getMessage()}");
            }
        }
        // Degrade, never abort — the same contract seedBanner already follows. An environment with no
        // ClamAV (the kap-seed Cloud Run job; a laptop with the sidecar stopped) still gets a complete seed,
        // just without the settled-payment fixture. BI-10 is never bypassed to manufacture demo data.
        $unclean = DB::table('payment_evidence as pe')->join('uploads as u', 'u.id', '=', 'pe.upload_id')
            ->where('pe.payment_id', $payment->id)->where('u.status', '<>', 'clean')->count();
        if ($unclean > 0) {
            $this->command->warn('settled-payment fixture skipped: evidence did not reach scan-clean (BI-10) — no ClamAV in this environment.');

            return;
        }

        app(ManualPaymentService::class)->confirm($payment->id, $fin2); // BI-9: recorder ≠ confirmer
        // confirm() dispatches FinalizeManualPayment afterCommit onto the DATABASE queue, so in a seeder it
        // would sit there and the demo would still show no receipt. Inline is safe: the job returns early
        // unless the order is still `issued`, so the queued copy is a harmless no-op.
        dispatch_sync(new \App\Jobs\FinalizeManualPayment($payment->id));
    }

    /** A forming team via the REAL FormationService — renders on /my/team (member_count ≥ 1). */
    private function formTeam(Programme $programme, User $creator, User $joiner): void
    {
        if (DB::table('team_members')->where('student_id', $creator->id)->where('status', 'active')->exists()) {
            return;
        }
        // (F2) lobby: no service exists — ProgrammeConfigController raw-inserts team_categories too.
        $categoryId = (string) Str::uuid7();
        DB::table('team_categories')->insert([
            'id' => $categoryId, 'programme_id' => $programme->id,
            'name_en' => 'Demo Lobby', 'name_tc' => '示範大廳', 'name_sc' => '示范大厅',
            'school_id' => null, 'assignment_rule' => 'open', 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $formation = app(FormationService::class);
        $team = $formation->create($programme->id, $categoryId, 'Demo Team Alpha', $creator); // creator: in_pool → teamed
        $formation->join($team->id, $joiner);                                                 // joiner:  in_pool → teamed
        // R1-S2 Delta 2: advance forming → submitted via the REAL submit() (submitter = creator), so the
        // team shows the journey MID-FLIGHT (awaiting Team Formation) on /my/team + the ops confirm queue.
        app(\App\Services\Teams\TeamConfirmationService::class)->submit($team->id, $creator);
    }

    /**
     * R1-P360 (3c): pass Design + Pitch on Demo Team Alpha via the REAL TrackerService::approveGate (ops =
     * OD-39 approver), for a 2/5 mid-progress rail. Design and Pitch are precondition-free for this team;
     * Plan (active budget, Spec:210) and Learn (attendance) are not — the Plan+Design pair bailed out per
     * the ruling. Idempotent: skips a stage already passed (approveGate 409s on a duplicate).
     */
    private function approveDemoGates(Programme $programme, User $ops): void
    {
        $teamId = DB::table('teams')->where('programme_id', $programme->id)
            ->where('name', 'Demo Team Alpha')->value('id');
        if ($teamId === null) {
            return;
        }
        $tracker = app(\App\Services\Teams\TrackerService::class);
        foreach (['Design', 'Pitch'] as $stage) {
            $already = DB::table('stage_gates')->where('team_id', $teamId)->where('stage', $stage)->exists();
            if (! $already) {
                $tracker->approveGate($teamId, $stage, $ops, 'demo fixture (R1-P360)');
            }
        }
    }

    /**
     * S-MENTOR-1: enable the per-programme mentor view on the demo programme and link teacher@ to Demo Team
     * Alpha via the REAL TeamTeacherLinkService (ops as the linking admin), so every mentor-access surface
     * demos populated. Idempotent: skips an existing active link.
     */
    private function linkDemoMentor(Programme $programme, ?User $teacher, User $ops): void
    {
        if ($teacher === null) {
            return;
        }
        DB::table('programmes')->where('id', $programme->id)->update(['mentor_team_access' => true]);
        $teamId = DB::table('teams')->where('programme_id', $programme->id)->where('name', 'Demo Team Alpha')->value('id');
        if ($teamId !== null && ! DB::table('team_teacher_links')->where('team_id', $teamId)->where('teacher_id', $teacher->id)->where('status', 'active')->exists()) {
            app(\App\Services\Teams\TeamTeacherLinkService::class)->link($teamId, (int) $teacher->id, $ops);
        }
    }

    /**
     * R2-ASSESS — one assessment taken through the REAL lifecycle to RELEASED with scores for the LIVE
     * enrolments (a3 active + the b-cohort confirmed/active), and a SECOND left at 'published' so the admin
     * section demos the lifecycle mid-flight. a4/a5 are 'teamed' (not confirmed/active) → not gradeable
     * (the grade precondition), so they are excluded — exactly what the map's precondition flag caught.
     */
    private function seedAssessments(Programme $programme, User $ops, User $a3): void
    {
        $svc = app(\App\Services\Assessments\AssessmentService::class);
        if (DB::table('assessments')->where('programme_id', $programme->id)->exists()) {
            return; // idempotent
        }
        // Released, scored.
        $rel = $svc->create($programme->id, ['title' => 'Term 1 Showcase'], $ops);
        foreach (['published', 'open', 'closed'] as $to) { // draft→published→open→closed (grade while closed)
            $svc->transition($rel, $to, $ops);
        }
        $scores = [$a3->id => 88];
        foreach (['b2' => 91, 'b3' => 76, 'b4' => 84] as $k => $score) {
            $sid = DB::table('users')->where('email', "student.{$k}@example.com")->value('id');
            if ($sid !== null) {
                $scores[$sid] = $score;
            }
        }
        foreach ($scores as $sid => $score) {
            $svc->grade($rel, (int) $sid, (int) $score, $ops);
        }
        $svc->transition($rel, 'graded', $ops);
        $svc->transition($rel, 'released', $ops); // terminal — lifts the embargo

        // Mid-flight: created then published (the admin section shows the lifecycle in progress).
        $pub = $svc->create($programme->id, ['title' => 'Term 2 Project'], $ops);
        $svc->transition($pub, 'published', $ops);
    }

    /**
     * KAP-MKT-1 — a storefront banner on the demo programme through the REAL file-intake + ClamAV path
     * (BI-10), the same pipeline recordManualPayment uses. A synthetic (provably-fake) JPEG generated via GD
     * → UploadService::intake (context 'image') → ScanUpload → clean; the reference lands on the programme.
     * The scan is async (Horizon); until clean the catalogue shows brand_color — the honest fallback.
     */
    private function seedBanner(Programme $programme, User $ops): void
    {
        if ($programme->banner_upload_id !== null) {
            return; // idempotent
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ban').'.jpg';
        $img = imagecreatetruecolor(1200, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 58, 42, 74)); // aubergine band
        imagejpeg($img, $tmp);
        imagedestroy($img);
        $file = new \Illuminate\Http\UploadedFile($tmp, 'banner.jpg', 'image/jpeg', null, true);
        $upload = app(\App\Services\Uploads\UploadService::class)->intake($file, 'image', $ops);
        DB::table('programmes')->where('id', $programme->id)->update(['banner_upload_id' => $upload->id, 'updated_at' => now()]);
    }

    /** Member/staff account through the real invitation flow; returns the accepted User. */
    private function inviteAndAccept(User $actor, string $email, string $role, ?int $schoolId): User
    {
        $this->assertSynthetic($email);
        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            return $existing;
        }
        $inv = app(InvitationService::class);
        $result = $inv->issue($actor, $email, $role, $schoolId);
        $user = $inv->accept($result['plain_token'], $this->password); // mints the account (provenance: invitation_accepted)
        $user->forceFill(['email_verified_at' => now()])->save();       // demo accounts can log in
        if (! $user->password || $user->wasRecentlyCreated) {
            $user->forceFill(['password' => Hash::make($this->password)])->save();
        }

        return $user;
    }

    private function provisionSchool(User $ops): void
    {
        if (School::query()->where('name_en', 'Demo Academy')->exists()) {
            return;
        }
        // School — no service; Eloquent create, as SchoolController::store does.
        $school = School::query()->create(['name_en' => 'Demo Academy', 'name_tc' => '示範學院', 'name_sc' => '示范学院']);

        // School admin account via invite→accept…
        $schoolAdmin = $this->inviteAndAccept($ops, 'schooladmin@example.com', 'school_admin', null);
        // …then (F1) the ACTIVE school_admin_links binding — NO SERVICE EXISTS (production
        // code only reads it); raw insert matches every test/seeder in the codebase.
        $linkId = (string) Str::uuid7();
        DB::table('school_admin_links')->insert([
            'id' => $linkId, 'school_admin_id' => $schoolAdmin->id, 'school_id' => $school->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('audit_events')->insert([ // provenance parity (not scanned, but honest)
            'event_id' => (string) Str::uuid7(), 'occurred_at' => now(),
            'entity_type' => 'school_admin_link', 'entity_id' => $linkId,
            'action' => 'school_admin_link.created', 'to_state' => 'active',
            'actor_role' => 'system', 'request_id' => (string) Str::uuid7(),
        ]);

        // Teacher — school-vouched invite→accept writes teacher_links(active) inside accept().
        $this->inviteAndAccept($schoolAdmin, 'teacher@example.com', 'teacher', $school->id);

        // Two students on the roll — BulkStudentCreationService writes school_links(active).
        foreach (['roll1@example.com', 'roll2@example.com'] as $e) {
            $this->assertSynthetic($e);
        }
        app(BulkStudentCreationService::class)->create($schoolAdmin, [
            ['name' => 'Demo Roll Student 1', 'email' => 'roll1@example.com', 'school_id' => $school->id],
            ['name' => 'Demo Roll Student 2', 'email' => 'roll2@example.com', 'school_id' => $school->id],
        ]);
    }

    // ── Demo-data enrichment (populate otherwise-empty surfaces; all REAL service flows) ──

    /**
     * Sessions & attendance — populates MentorAttendance / OpsAttendance / MySessions / ChildSessions
     * AND the RosterMark marking UI, via the S06 services (SessionService/BookingService/Attendance
     * Service). A 3-child cohort under guardian B (leaving B1, the RLS fixture, in-pool) is taken to
     * 'confirmed', booked into a COMPLETED session (mixed marks) + an UPCOMING session.
     */
    private function seedSessions(Programme $programme, User $ops, ?User $teacher, EnrolmentService $enrolments, OrderService $orders, ConsentSigningService $signing, User $guardianB, User $showcase, User $fin1, User $fin2): void
    {
        if ($teacher === null || DB::table('programme_sessions')->where('programme_id', $programme->id)->exists()) {
            return; // needs a mentor; idempotent
        }
        $sessions = app(\App\Services\Sessions\SessionService::class);
        $booking = app(\App\Services\Sessions\BookingService::class);
        $attendance = app(\App\Services\Sessions\AttendanceService::class);

        $cohort = [];
        foreach (['b2', 'b3', 'b4'] as $k) {
            $s = $this->account("student.{$k}@example.com", 'Demo Student '.strtoupper($k), 'student');
            $this->activeGuardianLink($guardianB, $s);
            $this->enrol($enrolments, $programme, $guardianB, $s);
            $this->sign($signing, $enrolments, $programme, $guardianB, $s);
            $order = $this->confirmOrder($enrolments, $orders, $ops, $s); // confirmed WITH its order issued — OD-43 (a live enrolment must carry its money artifact); bookable
            // (b) SEED-CONTRACT-1 — the FIRST cohort child's payment is taken all the way through BI-9:
            // finance1 records, finance2 confirms. That yields the demo's only PAID order and its only
            // gapless receipt (BI-2), which the family receipt line and the finance surfaces both need.
            // a3's payment is deliberately left at pending_confirmation (the live four-eyes moment) and a6's
            // order stays issued-unpaid (the guardian's payable task) — both are untouched here.
            if ($k === 'b2') {
                $this->settleManualPayment($order, $fin1, $fin2);
            }
            $cohort[] = $s;
        }

        // COMPLETED session (past) with a marked roster — the RosterMark demo.
        $s1 = $sessions->create($programme->id, [
            'title' => 'STEM Workshop 1', 'capacity' => 12, 'mentor_id' => $teacher->id,
            'starts_at' => now()->subDays(3)->toIso8601String(), 'ends_at' => now()->subDays(3)->addHours(2)->toIso8601String(),
        ], $ops);
        $sessions->transition($s1, 'published', $ops);
        foreach ($cohort as $s) {
            $booking->book($s1, $s); // book while published
        }
        $sessions->transition($s1, 'in_progress', $ops);              // open the attendance window
        $attendance->mark($s1, $cohort[0]->id, 'attended', $teacher); // mixed marks; the 3rd is left unmarked (booked)
        $attendance->mark($s1, $cohort[1]->id, 'no_show', $teacher);
        $sessions->transition($s1, 'completed', $ops);

        // UPCOMING session (bookable) — shows in My Sessions / My Child's Sessions, and is what makes the
        // student-home NEXT UP card render at all.
        // +21d, not +5d (SEED-CONTRACT-1 (d)): at +5d BOTH demo sessions had drifted into the PAST within a
        // week of seeding and were auto-completed, so NEXT UP was empty and looked like a dead branch when it
        // was really a stale fixture. Three weeks keeps the demo honest for a realistic review window.
        $s2 = $sessions->create($programme->id, [
            'title' => 'STEM Workshop 2', 'capacity' => 12, 'mentor_id' => $teacher->id,
            'starts_at' => now()->addDays(21)->toIso8601String(), 'ends_at' => now()->addDays(21)->addHours(2)->toIso8601String(),
        ], $ops);
        $sessions->transition($s2, 'published', $ops);
        foreach ($cohort as $s) {
            $booking->book($s2, $s);
        }
        // R1-S2 Delta 1: the showcase student (a3 — active, carries its order → OD-43 green) booked into
        // the UPCOMING session, so the student-home Next Session card is populated on the a3 login.
        $booking->book($s2, $showcase);
    }

    /**
     * Member community — populates Events / Directory / Profile / RSVP via EventService +
     * MemberSurfaceService. Two published events, three members with VISIBLE directory profiles
     * (the existing member + two invited), and RSVPs.
     */
    private function seedMemberCommunity(User $ops, ?User $member): void
    {
        if (DB::table('events')->exists()) {
            return; // idempotent
        }
        $events = app(\App\Services\Members\EventService::class);
        $surface = app(\App\Services\Members\MemberSurfaceService::class);

        // PUBLISHED events (draft→published so members can read them — RLS exposes only published).
        $e1 = $events->create(['title_en' => 'Network Mixer', 'title_tc' => '網絡交流會', 'title_sc' => '网络交流会', 'starts_at' => now()->addWeeks(2)->toIso8601String(), 'location' => 'Central Hub (demo)'], $ops);
        $events->transition($e1, 'published', $ops);
        $e2 = $events->create(['title_en' => 'Founders Talk', 'title_tc' => '創辦人分享', 'title_sc' => '创办人分享', 'starts_at' => now()->addWeeks(4)->toIso8601String(), 'location' => null], $ops);
        $events->transition($e2, 'published', $ops);

        // The existing member → a VISIBLE profile + an RSVP.
        if ($member !== null) {
            $surface->upsertProfile(['display_name' => 'Demo Member One', 'headline' => 'Kings Network — Cohort 1 (demo)', 'visible' => true], $member);
            $surface->rsvp($e1, 'going', $member);
        }
        // Two MORE members (invite→accept) with visible profiles + RSVPs → the directory populates.
        foreach ([['member2@example.com', 'Demo Member Two', 'going', $e1], ['member3@example.com', 'Demo Member Three', 'maybe', $e2]] as [$email, $name, $rsvp, $ev]) {
            $m = $this->inviteAndAccept($ops, $email, 'member', null);
            $surface->upsertProfile(['display_name' => $name, 'headline' => 'Kings Network — Cohort 1 (demo)', 'visible' => true], $m);
            $surface->rsvp($ev, $rsvp, $m);
        }
    }

    /**
     * Secondary admin queues — a pending WITHDRAWAL (real WithdrawalService) on guardian A's in-pool
     * child, and a pending REGISTRATION for the onboarding/Approvals queue (raw insert —
     * registration_requests has no seed service; matches PreviewSeeder's flagged pattern, fresh so it
     * stays under the escalation threshold and trips no provenance/aging assertion).
     */
    private function seedAdminQueues(User $guardianA, User $inPoolChild): void
    {
        $enrolmentId = DB::table('enrolments')->where('student_id', $inPoolChild->id)->orderByDesc('created_at')->value('id');
        if ($enrolmentId !== null && DB::table('withdrawal_requests')->where('enrolment_id', $enrolmentId)->doesntExist()) {
            app(\App\Services\Enrolments\WithdrawalService::class)->request($enrolmentId, 'Family relocating overseas (demo)', $guardianA);
        }

        if (DB::table('registration_requests')->where('applicant_email', 'nina.pending@example.com')->doesntExist()) {
            $this->assertSynthetic('nina.pending@example.com');
            DB::table('registration_requests')->insert([
                'id' => (string) Str::uuid7(), 'kind' => 'student', 'applicant_name' => 'Demo Nina (pending)',
                'applicant_email' => 'nina.pending@example.com', 'preferred_language' => 'en', 'routing' => 'academy',
                'school_id' => null, 'counterpart_email' => 'guardianA@example.com', 'counterpart_name' => 'Demo Guardian A',
                'status' => 'submitted', 'reference' => 'DEMO-'.Str::upper(Str::random(8)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
