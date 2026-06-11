<?php

namespace App\Http;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

class PaginationHelper
{
    /**
     * Paginate a query builder, respecting limit/offset from request.
     * Returns a structured response: { data, total, per_page, current_page, has_more }
     */
    public static function paginate(Builder $query, $request, int $defaultPerPage = 25): array
    {
        $perPage = (int) $request->query('per_page', $defaultPerPage);
        $page = max(1, (int) $request->query('page', 1));

        // Clamp per_page to reasonable bounds (1-500)
        $perPage = max(1, min($perPage, 500));

        $total = $query->count();
        $data = $query->forPage($page, $perPage)->get();
        $hasMore = ($page * $perPage) < $total;

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
            'has_more' => $hasMore,
        ];
    }

    /**
     * Paginate using offset/limit instead of page number.
     * Query params: limit (default 25, max 500), offset (default 0)
     */
    public static function paginateByOffset(Builder $query, $request, int $defaultLimit = 25): array
    {
        $limit = (int) $request->query('limit', $defaultLimit);
        $offset = max(0, (int) $request->query('offset', 0));

        $limit = max(1, min($limit, 500));

        $total = $query->count();
        $data = $query->limit($limit)->offset($offset)->get();
        $hasMore = ($offset + $limit) < $total;

        return [
            'data' => $data,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
        ];
    }
}
