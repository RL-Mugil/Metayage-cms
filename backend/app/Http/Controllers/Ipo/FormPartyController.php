<?php

namespace App\Http\Controllers\Ipo;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Services\Ipo\Form1DataBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Lets whoever prepares a case's IPO forms complete the applicant's filing address from
 * the Forms tab. Only these filing fields can be changed here; the rest of the client
 * record still needs client-edit rights. (Inventors are edited via InventorController.)
 */
class FormPartyController extends Controller
{
    private const ADDRESS_RULES = [
        'address_house'  => 'nullable|string|max:255',
        'address_street' => 'nullable|string|max:500',
        'city'           => 'nullable|string|max:100',
        'state'          => 'nullable|string|max:100',
        'country'        => 'nullable|string|max:100',
        'pincode'        => 'nullable|string|max:20',
    ];

    public function updateApplicant(Request $request, $id): JsonResponse
    {
        $project = Project::with('client')->findOrFail($id);
        $this->authorize('prepareIpoForms', $project);
        abort_unless($project->client !== null, 422, 'This case has no client.');

        $validated = $request->validate(self::ADDRESS_RULES + [
            'nationality'        => 'nullable|string|max:100',
            'contact_email'      => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:20',
            'applicant_category' => ['nullable', Rule::in(Form1DataBuilder::CATEGORIES)],
        ]);

        DB::transaction(function () use ($request, $project, $validated) {
            $project->client->update($validated);
            $this->audit($request, 'ipo_applicant_updated', 'Client', $project->client->id, [
                'project_id' => $project->id, 'fields' => array_keys($validated),
            ]);
        });

        return response()->json(['ok' => true]);
    }

    private function audit(Request $request, string $action, string $type, int $subjectId, array $metadata): void
    {
        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => $action,
            'subject_type' => $type,
            'subject_id'   => $subjectId,
            'metadata'     => $metadata,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);
    }
}
