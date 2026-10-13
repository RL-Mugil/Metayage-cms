<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\TrademarkMatterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Mark details and mark representation (logo) of a trademark case. */
class TrademarkMarkController extends Controller
{
    public function __construct(private readonly TrademarkMatterService $trademarks)
    {
    }

    public function show(Request $request, $id)
    {
        $project = Project::findOrFail($id);
        $this->authorize('view', $project);

        return response()->json(['data' => $this->trademarks->present($this->trademarks->forProject($project))]);
    }

    public function update(Request $request, $id)
    {
        $project = $this->editableTrademarkCase($id);

        $classCount = match (strtoupper((string) $project->service_code)) {
            'TMF' => 'size:1',
            'TMC' => 'min:2',
            default => 'min:1',
        };
        $validated = $request->validate([
            'mark_text' => 'required|string|max:255',
            'mark_type' => ['required', Rule::in(TrademarkMatterService::markTypes())],
            'nice_classes' => ['required', 'array', $classCount],
            'nice_classes.*' => 'integer|between:1,45|distinct',
        ]);

        $application = DB::transaction(fn () => $this->trademarks->attach($project, $validated, $request->user()->id));

        return response()->json(['data' => $this->trademarks->present($application->fresh('markDocument'))]);
    }

    public function storeImage(Request $request, $id)
    {
        $project = $this->editableTrademarkCase($id);

        $request->validate([
            // IP India accepts the mark representation as a JPG; PNG is kept for convenience.
            'image' => 'required|file|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        $application = $this->trademarks->forProject($project)
            ?? DB::transaction(fn () => $this->trademarks->attach($project, [], $request->user()->id));
        $this->trademarks->storeMarkImage($project, $application, $request->file('image'), $request->user());

        return response()->json(['data' => $this->trademarks->present($application->fresh('markDocument'))], 201);
    }

    private function editableTrademarkCase($id): Project
    {
        $project = Project::findOrFail($id);
        // The creator (e.g. a Patent Analyst) finishes their own case's mark and logo
        // right after the New Case form, even without general edit rights.
        $user = request()->user();
        abort_unless($user->can('update', $project) || ($project->created_by_id === $user->id && $user->can('view', $project)), 403);
        abort_unless(TrademarkMatterService::isTrademarkCode($project->service_code), 422, 'This case is not a trademark matter.');

        return $project;
    }
}
