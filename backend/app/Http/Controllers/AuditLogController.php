<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Http\PaginationHelper;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['super_admin', 'partner'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $query = AuditLog::with('user:id,name,email,avatar_url')
            ->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', 'ilike', '%' . $request->action . '%');
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('action', 'ilike', $term)
                  ->orWhere('subject_type', 'ilike', $term)
                  ->orWhereRaw("metadata::text ILIKE ?", [$term]);
            });
        }

        return response()->json(PaginationHelper::paginate($query, $request, 50));
    }
}
