<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadAgentSignatureRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Ipo\AgentSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * HRMS → Employees → Edit → Registered Patent Agent → Signature image. The image is kept
 * on the private disk against the employee's login and placed in the signature block
 * of the IPO forms (Form 1, Form 2). Only the roles that edit employees can view or change it.
 */
class PatentAgentSignatureController extends Controller
{
    public function show(Request $request, $id): Response
    {
        $this->denyUnlessEmployeeEditor($request);
        $login = $this->agentLogin($id);
        $path = $login->patent_agent_signature_path;
        abort_if(blank($path) || ! Storage::disk(AgentSignature::DISK)->exists($path), 404, 'No signature uploaded.');

        return Storage::disk(AgentSignature::DISK)->response($path, null, [
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(UploadAgentSignatureRequest $request, $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);
        $login = $this->agentLogin($id);

        DB::transaction(function () use ($request, $employee, $login) {
            AgentSignature::store($login, $request->file('signature'));
            $this->audit($request, $employee, $login, 'patent_agent_signature_updated');
        });

        return response()->json(['has_signature' => true], 201);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $this->denyUnlessEmployeeEditor($request);
        $employee = Employee::findOrFail($id);
        $login = $this->agentLogin($id);

        if ($login->patent_agent_signature_path) {
            DB::transaction(function () use ($request, $employee, $login) {
                AgentSignature::remove($login);
                $this->audit($request, $employee, $login, 'patent_agent_signature_removed');
            });
        }

        return response()->json(['has_signature' => false]);
    }

    private function denyUnlessEmployeeEditor(Request $request): void
    {
        abort_unless(in_array($request->user()->role, UploadAgentSignatureRequest::ROLES, true), 403);
    }

    /** The employee's login, which must already be marked as a Registered Patent Agent. */
    private function agentLogin($id): User
    {
        $employee = Employee::findOrFail($id);
        $login = $employee->user_id ? User::find($employee->user_id) : null;
        abort_unless($login, 422, 'Give this employee a portal login first.');
        abort_if(blank($login->patent_agent_no), 422, 'Mark this employee as a Registered Patent Agent (with the IN/PA No.) and save first.');

        return $login;
    }

    private function audit(Request $request, Employee $employee, User $login, string $action): void
    {
        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => $action,
            'subject_type' => 'Employee',
            'subject_id'   => $employee->id,
            'metadata'     => ['user_id' => $login->id],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);
    }
}
