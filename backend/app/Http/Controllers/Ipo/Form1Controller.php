<?php

namespace App\Http\Controllers\Ipo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ipo\SaveForm1InputRequest;
use App\Models\AuditLog;
use App\Models\IpoFormInput;
use App\Models\Project;
use App\Services\Ipo\Form1DataBuilder;
use App\Services\Ipo\Form1DocxRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * IPO Form 1 for a case: load the prefilled data, save the paralegal's input, and
 * generate the editable DOCX. Applicant, inventor, agent and service details come from
 * the database; the input only carries what the database cannot know.
 */
class Form1Controller extends Controller
{
    public function __construct(
        private readonly Form1DataBuilder $builder,
        private readonly Form1DocxRenderer $renderer,
    ) {}

    public function show(Request $request, $id): JsonResponse
    {
        $project = Project::with('client')->findOrFail($id);
        $this->authorize('prepareIpoForms', $project);

        return response()->json($this->builder->prefill($project));
    }

    public function save(SaveForm1InputRequest $request, $id): JsonResponse
    {
        $project = Project::with('client')->findOrFail($id);
        $this->persist($request, $project, 'ipo_form1_saved');

        return response()->json($this->builder->prefill($project->fresh('client')));
    }

    public function generate(SaveForm1InputRequest $request, $id): BinaryFileResponse
    {
        $project = Project::with('client')->findOrFail($id);
        $input = $this->persist($request, $project, 'ipo_form1_generated');
        $form = $this->builder->build($project->fresh('client'), $input);

        $path = tempnam(sys_get_temp_dir(), 'form1_');
        $this->renderer->render($form, $path);

        $reference = Str::of($project->docket_number ?: $project->project_code ?: (string) $project->id)
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '_');

        return response()->download($path, "Form1_{$reference}.docx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend();
    }

    /** Saves the input (and the applicant category onto the client) with its audit entry. */
    private function persist(SaveForm1InputRequest $request, Project $project, string $action): array
    {
        $input = $this->builder->normaliseInput($request->validated(), $project);

        DB::transaction(function () use ($request, $project, $input, $action) {
            IpoFormInput::updateOrCreate(
                ['project_id' => $project->id, 'form_type' => Form1DataBuilder::FORM_TYPE],
                ['data' => $input, 'updated_by_id' => $request->user()->id],
            );
            if ($project->client && $project->client->applicant_category !== $input['applicant_category']) {
                $project->client->update(['applicant_category' => $input['applicant_category']]);
            }
            AuditLog::create([
                'user_id'      => $request->user()->id,
                'action'       => $action,
                'subject_type' => 'Project',
                'subject_id'   => $project->id,
                'metadata'     => [
                    'application_type' => $input['application_type'],
                    'sub_type'         => $input['sub_type'],
                    'agent_user_ids'   => $input['agent_user_ids'],
                    'co_applicants'    => count($input['co_applicants']),
                ],
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);
        });

        return $input;
    }
}
