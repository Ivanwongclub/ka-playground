<?php

namespace App\Services\Programmes;

use App\Models\Programme;
use App\Models\User;
use App\Models\WizardSection;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Hub-and-spoke wizard (Spec Part D): ten interdependent sections, independent
 * saves, readiness recomputation, pre-flight severity model, publish one-way
 * door. Fee amounts and withdrawal terms are NOT in section payloads — they
 * live in the scoped tables (S02B plan).
 */
class WizardService
{
    /** D2: section key => [required-for-publish, depends-on]. Integration is Phase 2. */
    public const SECTIONS = [
        'basics' => ['required' => true, 'depends' => []],
        'eligibility' => ['required' => true, 'depends' => ['basics']],
        'fees' => ['required' => true, 'depends' => ['basics']],
        'consent' => ['required' => true, 'depends' => ['basics']],
        'tracker' => ['required' => true, 'depends' => ['team_rules', 'role_library']],
        'team_rules' => ['required' => true, 'depends' => ['basics']],
        'role_library' => ['required' => true, 'depends' => ['team_rules']],
        'learning' => ['required' => true, 'depends' => ['basics']],
        'certification' => ['required' => true, 'depends' => ['tracker', 'learning']],
        'integration' => ['required' => false, 'depends' => ['basics']], // deferred (Phase 2)
        // S-MARKETPLACE-A (Option B): storefront copy. OPTIONAL — NOT a publish prerequisite; a programme
        // can operate (enrol / form teams / take payment) without it. Marketing-completeness gates whether
        // a programme APPEARS in the public catalogue (the STEP-2 read filters on it), never whether it can
        // publish. So preFlight never errors on this section.
        'marketing' => ['required' => false, 'depends' => ['basics']],
    ];

    /** Sections locked once Published (D5 one-way door). */
    public const LOCKED_WHEN_PUBLISHED = ['fees', 'consent'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    /** @return array{sections: list<array<string, mixed>>, readiness: array{complete: int, required: int}} */
    public function state(Programme $programme): array
    {
        $rows = WizardSection::query()
            ->where('programme_id', $programme->id)
            ->get()->keyBy('section_key');

        $sections = [];
        $complete = 0;
        foreach (self::SECTIONS as $key => $meta) {
            $row = $rows->get($key);
            $status = $row->status ?? ($meta['required'] ? 'not_started' : 'deferred');
            if ($status === 'complete' && $meta['required']) {
                $complete++;
            }
            $sections[] = [
                'key' => $key,
                'status' => $status,
                'required' => $meta['required'],
                'depends' => $meta['depends'],
                'data' => $row->data ?? null,
            ];
        }

        return [
            'sections' => $sections,
            'readiness' => ['complete' => $complete, 'required' => count(array_filter(self::SECTIONS, fn ($m) => $m['required']))],
        ];
    }

    /**
     * FIX-REFUND-SEED — the ONE writer of programmes.starts_at (AUDIT-2 A-1).
     *
     * The wizard's authoritative start date is `basics.starts_on`, a bare `YYYY-MM-DD` calendar date in
     * wizard_sections.data. The COLUMN `programmes.starts_at` has existed since 2026_07_25_120000 and until
     * now had no writer at all — which is how WithdrawalPolicyService::seedProvisional came to seed
     * `full_refund_before = NULL` on every wizard-published programme (a 0%-everywhere refund policy that
     * presents as configured). One definition, deliberately: a publish-only writer would go stale the first
     * time an admin moves the date, relocating the duality instead of closing it. So this is called from
     * publish() AND from a successful `basics` save — including the POST-PUBLISH edit path, which is legal
     * and re-validated by deadlineOrderingViolation (see saveSection's OD-33/A12 re-validation above).
     *
     * TIMEZONE: a bare HK calendar date is read as Asia/Hong_Kong MIDNIGHT, never naive UTC. Parsing
     * '2027-02-01' as UTC would place the full-refund boundary 8 hours late — in the family's favour, and
     * still wrong in a money field.
     *
     * ends_at is deliberately NOT written: there is no `basics.ends_on` anywhere in the wizard, the API or
     * the reference set, and inventing a programme end date is not this card's business. Tracked under
     * AUDIT-2 A-1 (add the field, or drop the column).
     */
    /**
     * The ONE writer that mirrors the wizard's basics timeline onto the `programmes` columns the rest of the
     * platform reads. FIX-REFUND-SEED established it for `starts_on`; SEED-CONTRACT-1 (ruling 1, option A)
     * widens it to the whole enrolment WINDOW, so the wizard owns the timeline end to end.
     *
     * WHY THE WINDOW HAD TO JOIN IT: `enrolment_closes_on` lived in the basics JSON while the storefront's
     * open/closed chip was derived from the `enrolment_closes_at` COLUMN, with nothing mirroring between
     * them — so the two drifted, and the demo storefront advertised "Enrolment open" for 82 days after its
     * own published closing date. Same column, same source, no split.
     *
     * MIRROR, never merge: if the wizard no longer carries a date the column is CLEARED. Returning early
     * instead would leave the previous value behind on a date removal — the exact staleness this writer
     * exists to prevent, just harder to see. An absent start date then blocks publish, which is the point.
     *
     * FLAG (retirement card, ruling 1): ProgrammeController still validates and writes
     * `enrolment_opens_at`/`enrolment_closes_at` directly on the admin create/update. Until that card lands,
     * an admin's window write survives only until the next basics save, which will mirror over it. Acceptable
     * now because no live admin surface writes basics (J-19 unbuilt); the retirement card closes it properly.
     */
    private function syncBasicsDates(Programme $programme): void
    {
        $basics = (array) (WizardSection::query()
            ->where('programme_id', $programme->id)->where('section_key', 'basics')->value('data') ?? []);

        // ->utc() is load-bearing, not decoration. Eloquent serialises a datetime with `Y-m-d H:i:s` and
        // DROPS the offset, so persisting an HKT-midnight Carbon directly stores '2027-02-01 00:00:00' and
        // Postgres reads it as UTC midnight — the refund boundary 8 hours late, in the family's favour and
        // still wrong. Converting first stores the correct instant (HKT midnight = 16:00 UTC the day before).
        // Caught by test_publish_seeds_od2_provisional_policy_with_real_windows.
        $at = function (string $key, bool $endOfDay = false) use ($basics): ?\Illuminate\Support\Carbon {
            $on = $basics[$key] ?? null;
            if (! is_string($on) || trim($on) === '') {
                return null;
            }
            $day = Carbon::parse($on, 'Asia/Hong_Kong');

            return ($endOfDay ? $day->endOfDay() : $day->startOfDay())->utc();
        };

        $programme->forceFill([
            'starts_at' => $at('starts_on'),
            'enrolment_opens_at' => $at('enrolment_opens_on'),
            // END of day: "enrolment closes on the 31st" means the 31st is still open. startOfDay would shut
            // the storefront a full day early — the chip is derived as `now >= closes_at`.
            'enrolment_closes_at' => $at('enrolment_closes_on', endOfDay: true),
        ])->save();
    }

    /** @param array<string, mixed> $data */
    /**
     * S-MARKETPLACE-A (Option B) — the storefront-completeness DEFINITION (not a publish gate). The four
     * marketing text fields each need a non-empty EN + 繁 + 简 (OD-19); brand_color a valid hex. Returns
     * the list of gaps (empty ⇒ complete). Pure + static so `saveSection` AND the reconcile assertion AND
     * the STEP-2 public read all filter on the SAME predicate.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function marketingLanguageGaps(array $data): array
    {
        $gaps = [];
        foreach (['tagline', 'category', 'age_range', 'duration'] as $field) {
            foreach (['en', 'tc', 'sc'] as $lang) {
                $v = $data[$field][$lang] ?? null;
                if (! is_string($v) || trim($v) === '') {
                    $gaps[] = "{$field}.{$lang}";
                }
            }
        }
        $brand = $data['brand_color'] ?? null;
        if (! is_string($brand) || preg_match('/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/', $brand) !== 1) {
            $gaps[] = 'brand_color';
        }

        return $gaps;
    }

    public function saveSection(Programme $programme, string $key, array $data, string $status, User $actor): WizardSection
    {
        if (! array_key_exists($key, self::SECTIONS)) {
            throw ValidationException::withMessages(['section' => ["Unknown wizard section '{$key}'"]]);
        }
        if (! in_array($status, ['incomplete', 'complete'], true)) {
            throw ValidationException::withMessages(['status' => ['Status must be incomplete or complete']]);
        }
        if ($programme->status !== 'draft' && in_array($key, self::LOCKED_WHEN_PUBLISHED, true)) {
            // D5: Published is a one-way door for pricing and consent
            $this->audit->record(
                'programme', (string) $programme->id, 'programme.locked_field_attempt',
                reason: "section '{$key}' is locked once the programme leaves draft (D5)",
                payloadAfter: ['section' => $key, 'programme_status' => $programme->status],
                actor: $actor,
            );
            throw new HttpException(423, "Section '{$key}' is locked — changes require a new programme version (D5)");
        }

        // OD-33/A12: date ordering is re-validated on EVERY post-publish edit —
        // a published programme can never be edited into an illegal timeline
        if ($programme->status !== 'draft' && in_array($key, ['basics', 'team_rules'], true)) {
            $violation = $this->deadlineOrderingViolation($programme, $key, $data);
            if ($violation !== null) {
                throw ValidationException::withMessages(['dates' => [$violation]]);
            }
        }

        // OD-31 capacity edit rule (S05-2): raising is fine; LOWERING below the
        // seats already claimed by confirmed teams is refused
        if ($programme->status !== 'draft' && $key === 'eligibility' && isset($data['capacity'])) {
            $claimed = (int) DB::table('programme_capacity')->where('programme_id', $programme->id)->value('claimed');
            if ((int) $data['capacity'] < $claimed) {
                throw ValidationException::withMessages(['capacity' => ["Capacity cannot be lowered to {$data['capacity']}: {$claimed} seat(s) are already claimed by confirmed teams (OD-31)"]]);
            }
            $this->scope->asSystem(
                'Programme capacity edit (S05-2): the seat counter is a system-only table (claimed moves only through 成團); this raises/lowers the CAPACITY column after the OD-31 lower-below-claimed guard, never claimed. Config authority was established by the wizard route before this call.',
                fn () => DB::table('programme_capacity')->where('programme_id', $programme->id)->update(['capacity' => (int) $data['capacity'], 'updated_at' => now()]),
            );
        }

        // S-MARKETPLACE-A (Option B) — marketing storefront copy. A `complete`-status save must be fully
        // trilingual (OD-19). On a PUBLISHED programme marketing is editable but never DEGRADABLE — it must
        // stay complete-trilingual (mirrors the post-publish date re-validation for basics/team_rules), so
        // the public storefront never reads a half-filled row. This governs the marketing save ONLY — it
        // never blocks publish (preFlight ignores this optional section).
        if ($key === 'marketing') {
            $published = $programme->status !== 'draft';
            if ($status === 'complete' || $published) {
                $gaps = self::marketingLanguageGaps($data);
                if ($gaps !== []) {
                    throw ValidationException::withMessages(['marketing' => ['marketing.language_incomplete: missing '.implode(', ', $gaps)]]);
                }
            }
            if ($published && $status !== 'complete') {
                throw ValidationException::withMessages(['marketing' => ['A published programme\'s marketing cannot be set incomplete — it is editable but not degradable (Option B)']]);
            }
        }

        $section = WizardSection::query()->updateOrCreate(
            ['programme_id' => $programme->id, 'section_key' => $key],
            ['status' => $status, 'data' => $data, 'updated_by' => $actor->id],
        );

        // S-MENTOR-1: mirror the team_rules mentor-access toggle to the programmes COLUMN. The RLS arm reads
        // it under the TEACHER'S context; wizard_sections is configuration-gated (teacher-unreadable), so the
        // flag must live on the global-read programmes table. Boolean → no OD-19 (not a user-facing string).
        if ($key === 'team_rules') {
            DB::table('programmes')->where('id', $programme->id)
                ->update(['mentor_team_access' => (bool) ($data['mentor_team_access'] ?? false)]);
        }
        // FIX-REFUND-SEED: the SAME mirroring shape for the wizard's start date. Called here and at publish
        // through the one writer, so a post-publish basics edit (legal, OD-33-re-validated above) cannot
        // leave programmes.starts_at — and therefore the refund window seeded from it — stale.
        if ($key === 'basics') {
            $this->syncBasicsDates($programme);
        }
        $this->audit->record(
            'programme', (string) $programme->id, 'programme.section_saved',
            toState: $status,
            payloadAfter: ['section' => $key],
            actor: $actor,
        );

        return $section;
    }


    /**
     * OD-33: enrolment close < formation deadline < programme start. Dates live
     * in wizard data (basics.enrolment_closes_on, basics.starts_on,
     * team_rules.formation_deadline_on). If NONE are set the rule is silent
     * (pre-flight WARNS; S05 formation requires the deadline before it runs);
     * a PARTIAL or misordered set is a violation.
     * $overrideKey/$overrideData evaluate a prospective edit before saving.
     *
     * @param array<string, mixed>|null $overrideData
     */
    public function deadlineOrderingViolation(Programme $programme, ?string $overrideKey = null, ?array $overrideData = null): ?string
    {
        $sectionData = function (string $key) use ($programme, $overrideKey, $overrideData): array {
            if ($key === $overrideKey && $overrideData !== null) {
                return $overrideData;
            }
            $row = WizardSection::query()->where('programme_id', $programme->id)->where('section_key', $key)->first();

            return (array) ($row->data ?? []);
        };
        $basics = $sectionData('basics');
        $teamRules = $sectionData('team_rules');
        $dates = [
            'enrolment_closes_on' => $basics['enrolment_closes_on'] ?? null,
            'formation_deadline_on' => $teamRules['formation_deadline_on'] ?? null,
            'starts_on' => $basics['starts_on'] ?? null,
        ];
        $set = array_filter($dates, fn ($v) => $v !== null && $v !== '');
        if ($set === []) {
            return null; // silent — pre-flight raises the warning
        }
        if (count($set) < 3) {
            $missing = implode(', ', array_keys(array_diff_key($dates, $set)));
            return "Formation timeline is partially configured — missing: {$missing} (OD-33: all three dates or none)";
        }
        if (! ($dates['enrolment_closes_on'] < $dates['formation_deadline_on'] && $dates['formation_deadline_on'] < $dates['starts_on'])) {
            return 'Formation timeline out of order — required: enrolment close < formation deadline < programme start (OD-33)';
        }

        return null;
    }

    /** @return array{findings: list<array{severity: string, code: string, message: string}>, publishable: bool} */
    public function preFlight(Programme $programme, User $actor): array
    {
        $state = $this->state($programme);
        $byKey = collect($state['sections'])->keyBy('key');
        $findings = [];

        foreach (self::SECTIONS as $key => $meta) {
            if ($meta['required'] && ($byKey[$key]['status'] ?? '') !== 'complete') {
                $findings[] = ['severity' => 'error', 'code' => "section.{$key}.incomplete", 'message' => "Section '{$key}' is not complete"];
            }
        }
        $violation = $this->deadlineOrderingViolation($programme);
        if ($violation !== null) {
            $findings[] = ['severity' => 'error', 'code' => 'deadline.ordering', 'message' => $violation];
        } elseif (empty(($byKey['team_rules']['data'] ?? [])['formation_deadline_on'])) {
            $findings[] = ['severity' => 'warning', 'code' => 'deadline.dates_missing', 'message' => 'No formation timeline set (OD-33) — S05 team formation requires it before the programme runs'];
        }

        $capacity = ($byKey['eligibility']['data'] ?? [])['capacity'] ?? null;
        $minTeam = (int) (($byKey['team_rules']['data'] ?? [])['min_team_size'] ?? 1);
        if ($capacity === null) {
            $findings[] = ['severity' => 'warning', 'code' => 'capacity.unset', 'message' => 'No programme capacity set (OD-31, eligibility) — S05 team 成團 refuses without it'];
        } elseif ((int) $capacity <= 0) {
            $findings[] = ['severity' => 'error', 'code' => 'capacity.invalid', 'message' => 'Programme capacity must be greater than 0 (OD-31)'];
        } elseif ((int) $capacity < $minTeam) {
            $findings[] = ['severity' => 'error', 'code' => 'capacity.below_min_team', 'message' => "Programme capacity ({$capacity}) is below the minimum team size ({$minTeam}) (OD-31)"];
        }

        $consent = $byKey['consent']['data'] ?? [];
        if (empty($consent['template_ref'])) {
            $findings[] = ['severity' => 'error', 'code' => 'consent.template_missing', 'message' => 'Consent template not selected; enrolment cannot open'];
        } elseif (\Illuminate\Support\Str::isUuid($consent['template_ref'])
            && DB::table('consent_templates')->where('id', $consent['template_ref'])->exists()) {
            // OD-20/OD-20a: all three languages published, at parity
            $parity = app(\App\Services\Consent\ConsentTemplateService::class)->languageParity($consent['template_ref']);
            if (! $parity['complete']) {
                $missing = implode(', ', array_keys(array_filter($parity['versions'], fn ($v) => $v === null)));
                $findings[] = ['severity' => 'error', 'code' => 'consent.language_versions_incomplete', 'message' => "Consent template is missing published language version(s): {$missing} (OD-20)"];
            } elseif ($parity['drift']) {
                $versions = json_encode($parity['versions']);
                $findings[] = ['severity' => 'error', 'code' => 'consent.language_drift', 'message' => "Consent template language versions have drifted apart: {$versions} — a material change must be applied to ALL THREE languages together (OD-20a)"];
            }
        } elseif (! \Illuminate\Support\Str::isUuid($consent['template_ref']) && $consent['template_ref'] === 'placeholder-s03') {
            // Legacy sentinel from pre-S03 wizard fixtures — accepted until S04A wires real selection
        } else {
            $findings[] = ['severity' => 'error', 'code' => 'consent.template_unknown', 'message' => 'Selected consent template does not exist'];
        }
        $fees = $byKey['fees']['data'] ?? [];
        if (empty($fees['has_fee_items'])) {
            $findings[] = ['severity' => 'error', 'code' => 'fees.empty', 'message' => 'No fee items configured'];
        }
        $teamRules = $byKey['team_rules']['data'] ?? [];
        $eligibility = $byKey['eligibility']['data'] ?? [];
        if (isset($teamRules['max_size'], $eligibility['min_enrolment'])
            && (int) $teamRules['max_size'] < (int) $eligibility['min_enrolment']) {
            $findings[] = ['severity' => 'warning', 'code' => 'team.max_below_min_enrolment', 'message' => 'Team maximum is below the minimum enrolment; some students will not fit into a team'];
        }
        $basics = $byKey['basics']['data'] ?? [];
        // FIX-REFUND-SEED: the start date is a PUBLISH PRECONDITION, not a nicety — the OD-2 provisional
        // withdrawal policy is seeded from it, and a policy with no window refunds nothing, ever. Named here
        // so the failure reads as a pre-flight finding; seedProvisional's throw is the backstop.
        if (empty($basics['starts_on'])) {
            $findings[] = ['severity' => 'error', 'code' => 'basics.starts_on_missing', 'message' => 'No programme start date set (basics) — the OD-2 provisional withdrawal policy is seeded from it and cannot be seeded without it'];
        }
        if (empty($basics['hero_image'])) {
            $findings[] = ['severity' => 'info', 'code' => 'basics.no_hero', 'message' => 'No hero image set; the catalogue card will show a colour block'];
        }
        // OD-12 / R2: a Learn team-gate threshold is configured, but the Learn gate can only be
        // assessed once sessions carry attendance. Warn (mirrors the capacity nudge) — non-blocking.
        $teamGatePct = (int) (DB::table('certification_rules')->where('programme_id', $programme->id)->value('team_gate_pass_pct') ?? 0);
        if ($teamGatePct > 0 && DB::table('programme_sessions')->where('programme_id', $programme->id)->doesntExist()) {
            $findings[] = ['severity' => 'warning', 'code' => 'learn.no_sessions', 'message' => "Learn gate requires {$teamGatePct}% of members to qualify on attendance, but no sessions are configured yet — the Learn stage cannot be assessed until sessions run (OD-12)"];
        }

        $publishable = collect($findings)->where('severity', 'error')->isEmpty();

        DB::table('pre_flight_results')->insert([
            'id' => (string) Str::uuid7(),
            'programme_id' => $programme->id,
            'findings' => json_encode($findings),
            'publishable' => $publishable,
            'ran_by' => $actor->id,
            'ran_at' => now(),
        ]);
        $this->audit->record(
            'programme', (string) $programme->id, 'programme.preflight_ran',
            toState: $publishable ? 'publishable' : 'blocked',
            payloadAfter: ['errors' => collect($findings)->where('severity', 'error')->count()],
            actor: $actor,
        );

        return ['findings' => $findings, 'publishable' => $publishable];
    }

    public function publish(Programme $programme, User $actor): Programme
    {
        if ($programme->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only a draft programme can be published']]);
        }
        $preFlight = $this->preFlight($programme, $actor);
        if (! $preFlight['publishable']) {
            throw ValidationException::withMessages(['pre_flight' => ['Pre-flight errors block publishing — resolve them first']]);
        }

        return DB::transaction(function () use ($programme, $actor): Programme {
            $programme->update(['status' => 'published']);
            // S05-2: seed the seat counter from eligibility.capacity when set
            $capacity = json_decode((string) DB::table('wizard_sections')
                ->where('programme_id', $programme->id)->where('section_key', 'eligibility')
                ->value('data'), true)['capacity'] ?? null;
            if ($capacity !== null && (int) $capacity > 0) {
                $this->seedCapacity($programme, (int) $capacity);
            }
            // Freeze the full config as an immutable version (D5)
            app(\App\Http\Controllers\ProgrammeController::class); // (snapshot logic shared below)
            $next = (int) \App\Models\ProgrammeVersion::query()
                ->where('programme_id', $programme->id)->max('version') + 1;
            \App\Models\ProgrammeVersion::query()->create([
                'id' => (string) Str::uuid7(),
                'programme_id' => $programme->id,
                'version' => $next,
                'config' => [
                    'programme' => $programme->only(['code', 'name_en', 'name_tc', 'name_sc', 'status', 'jurisdiction', 'hold_window_days', 'payer_party', 'mentor_team_access']),
                    'sections' => collect($this->state($programme)['sections'])->keyBy('key')->map(fn ($s) => ['status' => $s['status'], 'data' => $s['data']])->all(),
                ],
                'created_by' => $actor->id,
            ]);
            // FIX-REFUND-SEED: the column must carry the wizard's date BEFORE the provisional policy
            // is seeded from it — seedProvisional reads $programme->starts_at and refuses on NULL.
            $this->syncBasicsDates($programme);
            app(WithdrawalPolicyService::class)->seedProvisional($programme->fresh(), $actor);
            $this->audit->record(
                'programme', (string) $programme->id, 'programme.published',
                fromState: 'draft', toState: 'published',
                payloadAfter: ['version' => $next],
                actor: $actor,
            );

            return $programme->fresh();
        });
    }

    /** Seed the system-only seat counter at publish (frame[1] must be this method for asSystem). */
    private function seedCapacity(Programme $programme, int $capacity): void
    {
        $this->scope->asSystem(
            'Programme capacity seed (S05-2): publish seeds the seat counter from eligibility.capacity with claimed=0. programme_capacity is a system-only table; this is the one insert of the row, inside the publish transaction. Publish authority was established by the route before this call.',
            fn () => DB::table('programme_capacity')->updateOrInsert(
                ['programme_id' => $programme->id],
                ['capacity' => $capacity, 'claimed' => 0, 'updated_at' => now(), 'created_at' => now()],
            ),
        );
    }

    public function saveAsTemplate(Programme $programme, User $actor): Programme
    {
        $template = $this->cloneProgramme($programme, $actor, isTemplate: true);
        $this->audit->record(
            'programme', (string) $template->id, 'programme.template_saved',
            payloadAfter: ['source_programme_id' => $programme->id],
            actor: $actor,
        );

        return $template;
    }

    public function createFromTemplate(Programme $template, User $actor): Programme
    {
        if (! $template->is_template) {
            throw ValidationException::withMessages(['template' => ['Not a template']]);
        }
        $draft = $this->cloneProgramme($template, $actor, isTemplate: false);
        $this->audit->record(
            'programme', (string) $draft->id, 'programme.created_from_template',
            toState: 'draft',
            payloadAfter: ['template_id' => $template->id],
            actor: $actor,
        );

        return $draft;
    }

    private function cloneProgramme(Programme $source, User $actor, bool $isTemplate): Programme
    {
        return DB::transaction(function () use ($source, $isTemplate): Programme {
            $clone = Programme::query()->create([
                'code' => $source->code.'-'.($isTemplate ? 'TPL' : 'COPY').'-'.Str::upper(Str::random(4)),
                'name_en' => $source->name_en,
                'name_tc' => $source->name_tc,
                'name_sc' => $source->name_sc,
                'status' => 'draft',
                'jurisdiction' => $source->jurisdiction,
                'hold_window_days' => $source->hold_window_days,
                'mentor_team_access' => $source->mentor_team_access, // S-MENTOR-1: clone carries the toggle
                'payer_party' => $source->payer_party,
                'is_template' => $isTemplate,
            ]);
            foreach (WizardSection::query()->where('programme_id', $source->id)->get() as $section) {
                WizardSection::query()->create([
                    'programme_id' => $clone->id,
                    'section_key' => $section->section_key,
                    'status' => $section->status,
                    'data' => $section->data,
                    'updated_by' => $section->updated_by,
                ]);
            }

            return $clone;
        });
    }
}
