<?php

namespace App\Http\Controllers\Ipo;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Services\Ipo\Form1DataBuilder;
use App\Services\Ipo\Form2DocxRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * IPO Form 2 (provisional/complete specification) for a case. Reuses the title,
 * applicants and agents saved for Form 1; the specification body stays a template.
 */
class Form2Controller extends Controller
{
    public function __construct(
        private readonly Form1DataBuilder $builder,
        private readonly Form2DocxRenderer $renderer,
    ) {}

    public function generate(Request $request, $id): BinaryFileResponse
    {
        $project = Project::with('client')->findOrFail($id);
        $this->authorize('prepareIpoForms', $project);
        $validated = $request->validate([
            'specification' => ['nullable', Rule::in(Form2DocxRenderer::SPECIFICATIONS)],
        ]);
        $specification = $validated['specification']
            ?? (strtoupper((string) $project->service_code) === 'PRV' ? 'provisional' : 'complete');

        $form = $this->builder->build($project, $this->builder->input($project));
        $path = tempnam(sys_get_temp_dir(), 'form2_');
        $this->renderer->render($form, $specification, $path);

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'ipo_form2_generated',
            'subject_type' => 'Project',
            'subject_id'   => $project->id,
            'metadata'     => ['specification' => $specification],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        $reference = Str::of($project->docket_number ?: $project->project_code ?: (string) $project->id)
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '_');

        return response()->download($path, "Form2_{$reference}.docx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend();
    }
}
