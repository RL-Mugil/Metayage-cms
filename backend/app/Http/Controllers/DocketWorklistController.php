<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Services\DocketWorklistService;
use Illuminate\Http\Request;

class DocketWorklistController extends Controller
{
    public function __invoke(Request $request, DocketWorklistService $service)
    {
        abort_if($request->user()->isClientRole(), 403);
        $filters = $request->validate([
            'horizon_days' => ['nullable', 'integer', 'between:1,365'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'risk_level' => ['nullable', 'string', 'max:16'], 'review_status' => ['nullable', 'string', 'max:24'],
            'record_type' => ['nullable', 'string', 'max:32'], 'responsible_user_id' => ['nullable', 'integer'],
        ]);
        $result = PaginationHelper::paginate($service->query($request->user(), $filters), $request, 50);
        $result['data'] = $result['data']->map(fn ($deadline) => $service->serialize($deadline));
        return response()->json($result);
    }
}
