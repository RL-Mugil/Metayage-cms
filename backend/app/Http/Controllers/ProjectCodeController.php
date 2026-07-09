<?php

namespace App\Http\Controllers;

use App\Models\ProjectCode;
use App\Models\User;
use Illuminate\Http\Request;

class ProjectCodeController extends Controller
{
    private const VALID_TYPES = ['office', 'service'];

    /** GET /api/project-codes?type=office|service — returns custom DB codes only */
    public function index(Request $request)
    {
        $type = $request->query('type');
        if (! in_array($type, self::VALID_TYPES, true)) {
            return response()->json(['message' => 'Invalid type.'], 422);
        }

        $rows = ProjectCode::where('type', $type)
            ->orderBy('code')
            ->get(['code', 'description']);

        return response()->json($rows);
    }

    /** POST /api/project-codes — add a new custom code */
    public function store(Request $request)
    {
        // Clients cannot add codes
        if (in_array($request->user()->role, User::CLIENT_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'type'        => 'required|in:office,service',
            'code'        => 'required|string|max:50',
            'description' => 'required|string|max:255',
        ]);

        $type        = $data['type'];
        $code        = strtoupper(trim($data['code']));
        $description = trim($data['description']);

        // Check against static config first
        $configKey  = $type === 'office' ? 'offices' : 'services';
        $configCodes = require config_path('project_import_codes.php');
        foreach (array_keys($configCodes[$configKey] ?? []) as $existing) {
            if (strcasecmp($existing, $code) === 0) {
                return response()->json(['message' => "Code '{$code}' already exists."], 422);
            }
        }

        // Check against DB (case-insensitive via normalization — stored uppercase)
        $exists = ProjectCode::where('type', $type)
            ->whereRaw('UPPER(code) = ?', [$code])
            ->exists();

        if ($exists) {
            return response()->json(['message' => "Code '{$code}' already exists."], 422);
        }

        $row = ProjectCode::create([
            'type'          => $type,
            'code'          => $code,
            'description'   => $description,
            'created_by_id' => $request->user()->id,
        ]);

        return response()->json(['code' => $row->code, 'description' => $row->description], 201);
    }
}
