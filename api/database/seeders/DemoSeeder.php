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
        $this->admin('finance2@example.com', 'Demo Finance Two', ['finance']); // BI-9 needs a 2nd finance officer
        $this->admin('super@example.com', 'Demo Super Admin', ['super_admin']);
        $this->admin('audit@example.com', 'Demo Audit Reader', ['audit_read']);

        // ── Published, marketing-complete, trilingual programme (catalogue not empty) ──
        $programme = $this->publishProgramme($ops);
        $enrolments = app(EnrolmentService::class);
        $signing = app(ConsentSigningService::class);
        $orders = app(OrderService::class);

        // ── Family A — one guardian, five children in VARIED states ──
        $guardianA = $this->account('guardianA@example.com', 'Demo Guardian A', 'guardian');
        $a = [];
        foreach (['a1', 'a2', 'a3', 'a4', 'a5'] as $k) {
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

        // ── Family B — a SEPARATE guardian + child (the cross-family RLS boundary) ──
        $guardianB = $this->account('guardianB@example.com', 'Demo Guardian B', 'guardian');
        $childB = $this->account('student.b1@example.com', 'Demo Student B1', 'student');
        $this->activeGuardianLink($guardianB, $childB);
        $this->enrol($enrolments, $programme, $guardianB, $childB);
        $this->sign($signing, $enrolments, $programme, $guardianB, $childB);

        // ── A REAL team for /my/team + formation surfaces (FormationService, not raw) ──
        $this->formTeam($programme, $a['a4'], $a['a5']);

        // R3 activation for the started programme (as the scheduled job does).
        app(\App\Services\Enrolments\EnrolmentActivationService::class)->run();

        // ── Member via the REAL invite→accept flow (InvitationService) ──
        $member = $this->inviteAndAccept($ops, 'member@example.com', 'member', null);

        // ── School: school_admin (invite→accept), teacher (school-vouched invite→accept),
        //    two students on the roll (bulk service). F1: the school_admin_links active
        //    binding is raw-inserted (no service exists) — flagged above. ──
        $this->provisionSchool($ops);
        $teacher = User::query()->where('email', 'teacher@example.com')->first();

        // ── Populate the surfaces that would otherwise render EMPTY (demo-quality). All via
        //    REAL service flows; @example.com synthetic; the RLS fixtures (B1) stay untouched. ──
        $this->seedSessions($programme, $ops, $teacher, $enrolments, $orders, $signing, $guardianB); // attendance surfaces + RosterMark
        $this->seedMemberCommunity($ops, $member);                                           // Events / Directory / Profile / RSVP
        $this->seedAdminQueues($guardianA, $a['a2']);                                         // Withdrawals + onboarding/Approvals queue
        app(\App\Services\Enrolments\EnrolmentActivationService::class)->run();               // activate the session cohort (confirmed → active)

        // ── Machine-readable fixtures for deploy/gcp/rls-proof.sh ──
        $this->command->info('');
        $this->command->info('════ DEMO SEED READY (synthetic; @example.com; strong creds) ════');
        $this->command->line("RLS-PROOF-FIXTURES={$guardianA->id},{$guardianB->id},{$childB->id}");
        $this->command->info('Family A: guardianA (5 children, varied states) · Family B: guardianB (B1 in-pool = RLS fixture + 3 session-cohort kids)');
        $this->command->info('Populated: sessions/attendance (2 sessions + marked roster) · member (2 events, 3 directory profiles, RSVPs) · withdrawals + approvals queue');
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

    private function publishProgramme(User $ops): Programme
    {
        $templates = app(ConsentTemplateService::class);
        $programme = Programme::query()->firstOrCreate(
            ['code' => 'DEMO-STEM'],
            ['name_en' => 'Summer STEM 2026 (demo)', 'name_tc' => '2026 夏季 STEM（示範）', 'name_sc' => '2026 夏季 STEM（示范）', 'jurisdiction' => 'HK', 'status' => 'draft'],
        );
        if ($programme->status === 'published') {
            return $programme;
        }
        $templateId = $templates->createTemplate(['name_en' => 'STEM Consent (demo)', 'name_tc' => 'STEM 同意書（示範）', 'name_sc' => 'STEM 同意书（示范）'], $ops);
        foreach ([
            'en' => '<p>[DEMO — placeholder, non-binding] I consent to {{student_name}} joining {{programme_name}} (fee {{fee_total}}). {{signature}} {{signature_date}}</p>',
            'zh-TC' => '<p>[示範 — 佔位文字，不具約束力] 本人同意 {{student_name}} 參加 {{programme_name}}(費用 {{fee_total}})。{{signature}} {{signature_date}}</p>',
            'zh-SC' => '<p>[示范 — 占位文字，不具约束力] 本人同意 {{student_name}} 参加 {{programme_name}}(费用 {{fee_total}})。{{signature}} {{signature_date}}</p>',
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
        foreach (['teamed', 'confirmed'] as $to) { // fixture 成團 (the real formation transaction is exercised separately by formTeam)
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
    private function seedSessions(Programme $programme, User $ops, ?User $teacher, EnrolmentService $enrolments, OrderService $orders, ConsentSigningService $signing, User $guardianB): void
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
            $this->confirmOrder($enrolments, $orders, $ops, $s); // confirmed WITH its order issued — OD-43 (a live enrolment must carry its money artifact); bookable
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

        // UPCOMING session (bookable) — shows in My Sessions / My Child's Sessions.
        $s2 = $sessions->create($programme->id, [
            'title' => 'STEM Workshop 2', 'capacity' => 12, 'mentor_id' => $teacher->id,
            'starts_at' => now()->addDays(5)->toIso8601String(), 'ends_at' => now()->addDays(5)->addHours(2)->toIso8601String(),
        ], $ops);
        $sessions->transition($s2, 'published', $ops);
        foreach ($cohort as $s) {
            $booking->book($s2, $s);
        }
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
