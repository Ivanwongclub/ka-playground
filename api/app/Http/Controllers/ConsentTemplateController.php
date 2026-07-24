<?php

namespace App\Http\Controllers;

use App\Services\Consent\ConsentTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsentTemplateController extends Controller
{
    public function __construct(private readonly ConsentTemplateService $templates) {}

    /** Template catalogue (global table — names only; text lives in versions). */
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('consent_templates')
            ->orderBy('created_at')->get(['id', 'name_en', 'name_tc', 'name_sc'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $names = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_tc' => ['required', 'string', 'max:255'],
            'name_sc' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(['id' => $this->templates->createTemplate($names, $request->user())], 201);
    }

    public function draftVersion(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'language' => ['required', 'string'],
            'body_html' => ['required', 'string'],
        ]);

        return response()->json([
            'version_id' => $this->templates->draftVersion($id, $validated['language'], $validated['body_html'], $request->user()),
        ], 201);
    }

    public function publishVersion(Request $request, string $id, string $versionId): JsonResponse
    {
        // FR037: materiality is declared at publish; material → OD-20a fan-out
        $data = $request->validate(['material' => ['sometimes', 'boolean']]);
        $this->templates->publishVersion($versionId, $request->user(), (bool) ($data['material'] ?? false));

        return response()->json(['status' => 'published']);
    }

    public function seedPlaceholder(Request $request): JsonResponse
    {
        return response()->json(['template_id' => $this->templates->seedPlaceholder($request->user())], 201);
    }

    /** RLS-shaped read: what YOUR session may see of a template's versions. */
    public function versions(string $id): JsonResponse
    {
        return response()->json([
            'data' => DB::table('consent_template_versions')->where('template_id', $id)
                ->orderBy('language')->orderBy('version')
                ->get(['id', 'language', 'version', 'status', 'is_placeholder', 'sha256']),
        ]);
    }
}
