<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\CaseInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Case page: health score, journey timeline and the document checklist. */
class CaseInsightsController extends Controller
{
    public function show(Request $request, int $id, CaseInsights $insights): JsonResponse
    {
        $project = Project::with('patentApplication')->findOrFail($id);
        $this->authorize('view', $project);

        return response()->json([
            'health' => $insights->healthMany(collect([$project]))[$project->id] ?? null,
            'journey' => $insights->journey($project),
            'checklist' => $insights->checklist($project),
        ]);
    }
}
