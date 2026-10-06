<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Form24QService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Form24QController extends Controller
{
    private const MANAGE_ROLES = User::PAYROLL_ACCESS_ROLES;

    public function __construct(private Form24QService $service)
    {
    }

    public function exceptions(Request $request, $financialYear, $quarter)
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->service->exceptions((int) $financialYear, (int) $quarter));
    }

    public function export(Request $request, $financialYear, $quarter)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $financialYear = (int) $financialYear;
        $quarter = (int) $quarter;

        $spreadsheet = $this->service->build($financialYear, $quarter);
        $tmpPath = tempnam(sys_get_temp_dir(), 'f24q') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'export_form24q',
            'subject_type' => 'Form24Q', 'subject_id' => $financialYear,
            'metadata' => ['quarter' => $quarter],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->download($tmpPath, "form-24q-fy{$financialYear}-q{$quarter}.xlsx")->deleteFileAfterSend(true);
    }
}
