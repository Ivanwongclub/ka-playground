<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditService;
use App\Services\Programmes\WithdrawalPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S02B step 2 — config CRUD (configuration.manage) + the RLS-shaped category
 * read used by formation (S05) and the five-branch isolation verification.
 */
class ProgrammeConfigController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly WithdrawalPolicyService $withdrawal,
    ) {}

    public function saveWithdrawalPolicy(Request $request, int $programmeId): JsonResponse
    {
        $validated = $request->validate([
            'full_refund_before' => ['nullable', 'date'],
            'no_refund_after' => ['nullable', 'date'],
            'requires_approval' => ['required', 'boolean'],
            'bands' => ['sometimes', 'array'],
            'bands.*.until_date' => ['required_with:bands', 'date'],
            'bands.*.refund_pct' => ['required_with:bands', 'integer'],
        ]);
        $this->withdrawal->save(
            \App\Models\Programme::query()->findOrFail($programmeId),
            $validated['full_refund_before'] ?? null,
            $validated['no_refund_after'] ?? null,
            $validated['requires_approval'],
            $validated['bands'] ?? [],
            $request->user(),
        );

        return response()->json(['status' => 'ok']);
    }

    public function withdrawalPolicy(int $programmeId): JsonResponse
    {
        return response()->json([
            'policy' => DB::table('withdrawal_policies')->where('programme_id', $programmeId)->first(),
            'bands' => DB::table('withdrawal_bands')->where('programme_id', $programmeId)->orderBy('position')->get(),
        ]);
    }

    /** RLS does the shaping — no capability gate on reads by design (plan). */
    public function categories(int $programmeId): JsonResponse
    {
        return response()->json([
            'data' => DB::table('team_categories')
                ->where('programme_id', $programmeId)
                ->whereNull('retired_at')
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_tc', 'name_sc', 'school_id', 'assignment_rule', 'is_default']),
        ]);
    }

    public function storeCategory(Request $request, int $programmeId): JsonResponse
    {
        $validated = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_tc' => ['required', 'string', 'max:255'],
            'name_sc' => ['required', 'string', 'max:255'],
            'school_id' => ['nullable', 'integer', 'exists:schools,id'],
            'assignment_rule' => ['required', 'in:auto_by_school,open,admin_assigned'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $id = (string) Str::uuid7();
        try {
            // Savepoint: an OD-13b refusal must not abort the enclosing transaction
            DB::transaction(fn () => DB::table('team_categories')->insert(array_merge($validated, [
                'id' => $id, 'programme_id' => $programmeId,
                'is_default' => (bool) ($validated['is_default'] ?? false),
                'created_at' => now(), 'updated_at' => now(),
            ])));
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // OD-13b: the partial unique index refused a second default
            return response()->json(['message' => 'This programme already has a default lobby (OD-13b)'], 409);
        }
        $this->audit->record('team_category', $id, 'team_category.created',
            payloadAfter: $validated, actor: $request->user());

        return response()->json(['id' => $id], 201);
    }

    public function retireCategory(Request $request, int $programmeId, string $id): JsonResponse
    {
        DB::table('team_categories')->where('id', $id)->where('programme_id', $programmeId)
            ->update(['retired_at' => now(), 'updated_at' => now()]);
        $this->audit->record('team_category', $id, 'team_category.retired',
            reason: 'admin action — existing teams keep the lobby (TEAM-CATEGORIES §8)',
            actor: $request->user());

        return response()->json(['status' => 'retired']);
    }

    public function feeItems(int $programmeId): JsonResponse
    {
        return response()->json([
            'data' => DB::table('fee_items')->where('programme_id', $programmeId)
                ->orderBy('sort')->get(),
        ]);
    }

    public function storeFeeItem(Request $request, int $programmeId): JsonResponse
    {
        $validated = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_tc' => ['required', 'string', 'max:255'],
            'name_sc' => ['required', 'string', 'max:255'],
            'amount_minor' => ['required', 'integer', 'min:0'], // OD-18: integer minor units only
            'currency' => ['sometimes', 'in:HKD'],
            'sort' => ['sometimes', 'integer', 'min:0'],
        ]);

        $id = (string) Str::uuid7();
        DB::table('fee_items')->insert(array_merge($validated, [
            'id' => $id, 'programme_id' => $programmeId,
            'currency' => $validated['currency'] ?? 'HKD',
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->audit->record('fee_item', $id, 'fee_item.created',
            payloadAfter: $validated, actor: $request->user());

        return response()->json(['id' => $id], 201);
    }

    public function saveCertificationRules(Request $request, int $programmeId): JsonResponse
    {
        $validated = $request->validate([
            'attendance_threshold_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'team_gate_pass_pct' => ['required', 'integer', 'min:0', 'max:100'], // OD-12, editable post-creation
            'assessment_threshold_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'certificate_template' => ['nullable', 'string', 'max:255'],
        ]);

        $existing = DB::table('certification_rules')->where('programme_id', $programmeId)->first();
        if ($existing) {
            DB::table('certification_rules')->where('programme_id', $programmeId)
                ->update(array_merge($validated, ['updated_at' => now()]));
            $this->audit->record('certification_rules', $existing->id, 'certification_rules.updated',
                payloadBefore: (array) $existing, payloadAfter: $validated, actor: $request->user());
        } else {
            $id = (string) Str::uuid7();
            DB::table('certification_rules')->insert(array_merge($validated, [
                'id' => $id, 'programme_id' => $programmeId,
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->audit->record('certification_rules', $id, 'certification_rules.created',
                payloadAfter: $validated, actor: $request->user());
        }

        return response()->json(['status' => 'ok']);
    }
}
