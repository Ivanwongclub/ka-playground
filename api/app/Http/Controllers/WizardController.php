<?php

namespace App\Http\Controllers;

use App\Models\Programme;
use App\Services\Programmes\WizardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** S02B step 1 — wizard endpoints, all behind configuration.manage. */
class WizardController extends Controller
{
    public function __construct(private readonly WizardService $wizard) {}

    public function state(int $id): JsonResponse
    {
        return response()->json($this->wizard->state(Programme::query()->findOrFail($id)));
    }

    public function saveSection(Request $request, int $id, string $key): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:incomplete,complete'],
            'data' => ['sometimes', 'array'],
        ]);
        $section = $this->wizard->saveSection(
            Programme::query()->findOrFail($id),
            $key,
            $validated['data'] ?? [],
            $validated['status'],
            $request->user(),
        );

        return response()->json(['status' => $section->status]);
    }

    public function preFlight(Request $request, int $id): JsonResponse
    {
        return response()->json(
            $this->wizard->preFlight(Programme::query()->findOrFail($id), $request->user())
        );
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $programme = $this->wizard->publish(Programme::query()->findOrFail($id), $request->user());

        return response()->json(['status' => $programme->status]);
    }

    public function saveAsTemplate(Request $request, int $id): JsonResponse
    {
        $template = $this->wizard->saveAsTemplate(Programme::query()->findOrFail($id), $request->user());

        return response()->json(['template_id' => $template->id], 201);
    }

    public function createFromTemplate(Request $request, int $id): JsonResponse
    {
        $draft = $this->wizard->createFromTemplate(Programme::query()->findOrFail($id), $request->user());

        return response()->json(['programme_id' => $draft->id], 201);
    }
}
