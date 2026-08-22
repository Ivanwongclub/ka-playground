<?php

namespace App\Http\Controllers;

use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Services\Audit\AuditService;
use App\Services\Authz\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Programme entity + version snapshots (S02A step 2). The wizard, readiness,
 * pre-flight and publish flow are S02B — these endpoints are the entity CRUD
 * and the immutable snapshot mechanism they will build on.
 */
class ProgrammeController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(Programme::query()->orderBy('code')->paginate(50));
    }

    /**
     * S-FIX-UX-1 D7: a thin ops-readable programme picker — id/code/trilingual names ONLY, no
     * configuration data. Gated permission:operations.manage on the route so an operations-only
     * admin has a programme-list source for attendance oversight (the config-gated index() is not
     * reachable by ops). Read-only sibling of index(); no pagination, shape {data:[…]}.
     */
    public function opsOptions(): JsonResponse
    {
        return response()->json(['data' => Programme::query()->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_tc', 'name_sc'])]);
    }

    // R1-F2: the Programme 360 is reached from ops / audit / config surfaces. These two reads are gated on
    // that union IN-CONTROLLER (an OR the route `permission:` middleware can't express) — the config-gated
    // index() is not reachable by ops/audit.
    private function assertStaff(Request $request, PermissionResolver $resolver): void
    {
        $u = $request->user();
        if (! $resolver->allows($u, 'operations.manage') && ! $resolver->allows($u, 'audit.read') && ! $resolver->allows($u, 'configuration.manage')) {
            abort(403, 'Programme overview is an academy operations / audit / configuration surface');
        }
    }

    /**
     * R1-F2 — Programme 360 HEADER. Display columns ONLY (id/code/trilingual names/status/enrolment window),
     * NOTHING from the config payload (that is the config-gated wizard). Gated ops∨audit∨config.
     */
    public function overview(Request $request, PermissionResolver $resolver, string $id): JsonResponse
    {
        $this->assertStaff($request, $resolver);
        $p = Programme::query()->findOrFail($id);

        return response()->json([
            'id' => $p->id, 'code' => $p->code,
            'name_en' => $p->name_en, 'name_tc' => $p->name_tc, 'name_sc' => $p->name_sc,
            'status' => $p->status,
            'enrolment_opens_at' => $p->enrolment_opens_at, 'enrolment_closes_at' => $p->enrolment_closes_at,
        ]);
    }

    /**
     * R1-F2 — Programme 360 FUNNEL (option b): per-programme enrolment counts BY STATUS only — no names, no
     * ids, no PII. Gated ops∨audit∨config; the counts resolve under the CALLER'S RLS (enr_read), so ops/audit
     * (opsAudit) get true tallies. A config-only admin's RLS does not admit enrolments — the UI renders this
     * funnel ONLY for ops/audit holders (config-only sees a link onward); NOT elevated (no wall crossed here).
     */
    public function enrolmentSummary(Request $request, PermissionResolver $resolver, string $id): JsonResponse
    {
        $this->assertStaff($request, $resolver);

        return response()->json([
            'programme_id' => (int) $id,
            'by_status' => DB::table('enrolments')->where('programme_id', $id)
                ->groupBy('status')->select('status', DB::raw('count(*) as n'))->orderBy('status')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $programme = Programme::query()->create($validated);
        $this->audit->record(
            'programme', (string) $programme->id, 'programme.created',
            toState: 'draft', payloadAfter: $validated, actor: $request->user(),
        );

        return response()->json(['id' => $programme->id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $programme = Programme::query()->findOrFail($id);
        $before = $programme->only(array_keys($this->rules()));
        $validated = $this->validated($request, $programme->id);
        $programme->update($validated);
        $this->audit->record(
            'programme', (string) $programme->id, 'programme.updated',
            payloadBefore: $before, payloadAfter: $validated, actor: $request->user(),
        );

        return response()->json(['id' => $programme->id]);
    }

    /** Freeze the current config as the next immutable version (D5). */
    public function snapshot(Request $request, int $id): JsonResponse
    {
        $programme = Programme::query()->findOrFail($id);

        $version = DB::transaction(function () use ($programme, $request): ProgrammeVersion {
            // Serialise numbering by locking the programme row (FOR UPDATE is
            // not allowed with aggregates in Postgres)
            Programme::query()->whereKey($programme->id)->lockForUpdate()->first();
            $next = (int) ProgrammeVersion::query()
                ->where('programme_id', $programme->id)
                ->max('version') + 1;

            $version = ProgrammeVersion::query()->create([
                'id' => (string) Str::uuid7(),
                'programme_id' => $programme->id,
                'version' => $next,
                'config' => $programme->only([
                    'code', 'name_en', 'name_tc', 'name_sc', 'status', 'jurisdiction',
                    'enrolment_opens_at', 'enrolment_closes_at', 'hold_window_days', 'payer_party',
                ]),
                'created_by' => $request->user()->id,
            ]);
            $this->audit->record(
                'programme_version', $version->id, 'programme.version_snapshotted',
                toState: "v{$next}",
                payloadAfter: ['programme_id' => $programme->id, 'version' => $next],
                actor: $request->user(),
            );

            return $version;
        });

        return response()->json(['version_id' => $version->id, 'version' => $version->version], 201);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $rules = $this->rules();
        $rules['code'][] = 'unique:programmes,code'.($ignoreId !== null ? ",{$ignoreId}" : '');

        return $request->validate($rules);
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_tc' => ['required', 'string', 'max:255'],
            'name_sc' => ['required', 'string', 'max:255'],
            'jurisdiction' => ['required', 'in:HK,CN'], // OD-16
            // S-TTL-1 PART B — RETIRED as writable. WizardService::syncBasicsDates is the SOLE writer of
            // these two columns, mirroring basics.enrolment_opens_on / .enrolment_closes_on. Accepting them
            // here made this a SECOND writer of the same window, so an admin write survived only until the
            // next basics save silently mirrored over it — a write that looks like it took and did not,
            // which is the precise defect that produced the storefront advertising "Enrolment open" 82 days
            // past its own closing date. `prohibited` REJECTS with 422 rather than dropping quietly: a
            // caller is told the wizard owns the timeline instead of being misled by a 200.
            // The READ (overview(), above) and the programme_versions snapshot are untouched — the columns
            // are still canonical, only this write path is gone.
            'enrolment_opens_at' => ['prohibited'],
            'enrolment_closes_at' => ['prohibited'],
            // FLAG, not decided here: hold_window_days is OD-11's ENROLMENT SEAT timer, and OD-11's stated
            // mechanism (release the seat, run the 2.18 waitlist promotion) was superseded by OD-34 under
            // team-based capacity (OD-31). It is validated here, snapshotted into programme_versions, and
            // read by NOTHING in app/. Same family of defect, separate decision — see the S-TTL-1 report.
            'hold_window_days' => ['sometimes', 'integer', 'min:1', 'max:60'], // OD-11 (obsolete — see above)
            'payer_party' => ['sometimes', 'in:parent,student,school'], // E6
        ];
    }
}
