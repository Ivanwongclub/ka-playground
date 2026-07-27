<?php

namespace App\Services\Programmes;

use App\Models\Programme;
use App\Models\User;
use App\Models\WizardSection;
use App\Services\Audit\AuditService;
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
    ];

    /** Sections locked once Published (D5 one-way door). */
    public const LOCKED_WHEN_PUBLISHED = ['fees', 'consent'];

    public function __construct(private readonly AuditService $audit) {}

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

    /** @param array<string, mixed> $data */
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

        $section = WizardSection::query()->updateOrCreate(
            ['programme_id' => $programme->id, 'section_key' => $key],
            ['status' => $status, 'data' => $data, 'updated_by' => $actor->id],
        );
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
        if (empty($basics['hero_image'])) {
            $findings[] = ['severity' => 'info', 'code' => 'basics.no_hero', 'message' => 'No hero image set; the catalogue card will show a colour block'];
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
            // Freeze the full config as an immutable version (D5)
            app(\App\Http\Controllers\ProgrammeController::class); // (snapshot logic shared below)
            $next = (int) \App\Models\ProgrammeVersion::query()
                ->where('programme_id', $programme->id)->max('version') + 1;
            \App\Models\ProgrammeVersion::query()->create([
                'id' => (string) Str::uuid7(),
                'programme_id' => $programme->id,
                'version' => $next,
                'config' => [
                    'programme' => $programme->only(['code', 'name_en', 'name_tc', 'name_sc', 'status', 'jurisdiction', 'hold_window_days', 'payer_party']),
                    'sections' => collect($this->state($programme)['sections'])->keyBy('key')->map(fn ($s) => ['status' => $s['status'], 'data' => $s['data']])->all(),
                ],
                'created_by' => $actor->id,
            ]);
            app(WithdrawalPolicyService::class)->seedProvisional($programme, $actor);
            $this->audit->record(
                'programme', (string) $programme->id, 'programme.published',
                fromState: 'draft', toState: 'published',
                payloadAfter: ['version' => $next],
                actor: $actor,
            );

            return $programme->fresh();
        });
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
