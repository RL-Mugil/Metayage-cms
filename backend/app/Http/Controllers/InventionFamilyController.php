<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFamilyBranchRequest;
use App\Models\InventionFamily;
use App\Models\Project;
use App\Services\InventionFamilyService;
use Illuminate\Http\Request;

class InventionFamilyController extends Controller
{
    public function show(Request $request, InventionFamily $family)
    {
        $engagements = $family->engagements()->with('patentApplication')->get()
            ->filter(fn (Project $project) => $request->user()->can('view', $project))->values();
        abort_if($engagements->isEmpty(), 403);

        return response()->json([
            'family' => $family->load('client:id,client_code,company_name,legal_name'),
            'applications' => $family->applications()->whereIn('id', $engagements->pluck('patent_application_id')->filter())->get(),
            'engagements' => $engagements,
        ]);
    }

    public function storeBranch(StoreFamilyBranchRequest $request, InventionFamily $family, InventionFamilyService $service)
    {
        $source = Project::findOrFail($request->integer('source_project_id'));
        abort_unless((int) $source->invention_family_id === (int) $family->id, 422, 'Source matter is outside this invention family.');

        $project = $service->createBranch($source, $request->validated(), $request->user(), [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Family engagement created.', 'project' => $project], 201);
    }
}
