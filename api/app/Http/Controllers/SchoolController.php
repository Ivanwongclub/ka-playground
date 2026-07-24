<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Schools CRUD (S02A step 2) — academy configuration surface (OD-17). */
class SchoolController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(School::query()->orderBy('name_en')->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $school = School::query()->create($validated);
        $this->audit->record(
            'school', (string) $school->id, 'school.created',
            payloadAfter: $validated, actor: $request->user(),
        );

        return response()->json(['id' => $school->id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $school = School::query()->findOrFail($id);
        $before = $school->only(['name_en', 'name_tc', 'name_sc']);
        $validated = $this->validated($request);
        $school->update($validated);
        $this->audit->record(
            'school', (string) $school->id, 'school.updated',
            payloadBefore: $before, payloadAfter: $validated, actor: $request->user(),
        );

        return response()->json(['id' => $school->id]);
    }

    /** @return array<string, string> */
    private function validated(Request $request): array
    {
        // OD-19: admin-authored short labels carry all three languages, always
        return $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_tc' => ['required', 'string', 'max:255'],
            'name_sc' => ['required', 'string', 'max:255'],
        ]);
    }
}
