<?php

namespace App\Http\Controllers;

use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Services\Audit\AuditService;
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
            'enrolment_opens_at' => ['nullable', 'date'],
            'enrolment_closes_at' => ['nullable', 'date', 'after:enrolment_opens_at'],
            'hold_window_days' => ['sometimes', 'integer', 'min:1', 'max:60'], // OD-11
            'payer_party' => ['sometimes', 'in:parent,student,school'], // E6
        ];
    }
}
