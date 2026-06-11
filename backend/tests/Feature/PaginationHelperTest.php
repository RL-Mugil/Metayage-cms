<?php

namespace Tests\Feature;

use App\Http\PaginationHelper;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PaginationHelperTest extends TestCase
{
    use RefreshDatabase;

    private function seedUsers(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            User::create([
                'name' => "User {$i}",
                'email' => "user{$i}@test.local",
                'password' => bcrypt('password'),
                'role' => 'associate',
                'status' => 'Active',
            ]);
        }
    }

    private function request(array $params = []): Request
    {
        return Request::create('/test', 'GET', $params);
    }

    // ── paginate() ────────────────────────────────────────────────────────────

    public function test_paginate_returns_default_page_size(): void
    {
        $this->seedUsers(30);
        $result = PaginationHelper::paginate(User::query(), $this->request());

        $this->assertCount(25, $result['data']);
        $this->assertSame(30, $result['total']);
        $this->assertSame(25, $result['per_page']);
        $this->assertSame(1, $result['current_page']);
        $this->assertSame(2, $result['last_page']);
        $this->assertTrue($result['has_more']);
    }

    public function test_paginate_respects_custom_page_and_per_page(): void
    {
        $this->seedUsers(12);
        $result = PaginationHelper::paginate(
            User::query()->orderBy('id'),
            $this->request(['per_page' => 5, 'page' => 3])
        );

        $this->assertCount(2, $result['data']); // 12 items, page 3 of 5 → 2 left
        $this->assertSame(3, $result['current_page']);
        $this->assertSame(3, $result['last_page']);
        $this->assertFalse($result['has_more']);
    }

    public function test_paginate_clamps_per_page_to_max_500(): void
    {
        $this->seedUsers(3);
        $result = PaginationHelper::paginate(User::query(), $this->request(['per_page' => 9999]));

        $this->assertSame(500, $result['per_page']);
    }

    public function test_paginate_clamps_per_page_to_min_1(): void
    {
        $this->seedUsers(3);
        $result = PaginationHelper::paginate(User::query(), $this->request(['per_page' => 0]));

        $this->assertSame(1, $result['per_page']);
        $this->assertCount(1, $result['data']);
    }

    public function test_paginate_clamps_page_to_min_1(): void
    {
        $this->seedUsers(3);
        $result = PaginationHelper::paginate(User::query(), $this->request(['page' => -5]));

        $this->assertSame(1, $result['current_page']);
        $this->assertCount(3, $result['data']);
    }

    public function test_paginate_last_page_has_more_is_false(): void
    {
        $this->seedUsers(10);
        $result = PaginationHelper::paginate(
            User::query(),
            $this->request(['per_page' => 5, 'page' => 2])
        );

        $this->assertFalse($result['has_more']);
    }

    public function test_paginate_empty_result_set(): void
    {
        $result = PaginationHelper::paginate(User::query(), $this->request());

        $this->assertCount(0, $result['data']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['last_page']);
        $this->assertFalse($result['has_more']);
    }

    public function test_paginate_pages_do_not_overlap(): void
    {
        $this->seedUsers(10);
        $page1 = PaginationHelper::paginate(User::query()->orderBy('id'), $this->request(['per_page' => 5, 'page' => 1]));
        $page2 = PaginationHelper::paginate(User::query()->orderBy('id'), $this->request(['per_page' => 5, 'page' => 2]));

        $ids1 = collect($page1['data'])->pluck('id')->all();
        $ids2 = collect($page2['data'])->pluck('id')->all();

        $this->assertCount(5, $ids1);
        $this->assertCount(5, $ids2);
        $this->assertEmpty(array_intersect($ids1, $ids2));
    }

    // ── paginateByOffset() ────────────────────────────────────────────────────

    public function test_offset_pagination_defaults(): void
    {
        $this->seedUsers(30);
        $result = PaginationHelper::paginateByOffset(User::query(), $this->request());

        $this->assertCount(25, $result['data']);
        $this->assertSame(30, $result['total']);
        $this->assertSame(25, $result['limit']);
        $this->assertSame(0, $result['offset']);
        $this->assertTrue($result['has_more']);
    }

    public function test_offset_pagination_respects_limit_and_offset(): void
    {
        $this->seedUsers(12);
        $result = PaginationHelper::paginateByOffset(
            User::query()->orderBy('id'),
            $this->request(['limit' => 5, 'offset' => 10])
        );

        $this->assertCount(2, $result['data']);
        $this->assertFalse($result['has_more']);
    }

    public function test_offset_pagination_clamps_negative_offset_to_zero(): void
    {
        $this->seedUsers(3);
        $result = PaginationHelper::paginateByOffset(User::query(), $this->request(['offset' => -10]));

        $this->assertSame(0, $result['offset']);
    }

    public function test_offset_pagination_clamps_limit_bounds(): void
    {
        $this->seedUsers(3);

        $tooBig = PaginationHelper::paginateByOffset(User::query(), $this->request(['limit' => 9999]));
        $this->assertSame(500, $tooBig['limit']);

        $tooSmall = PaginationHelper::paginateByOffset(User::query(), $this->request(['limit' => 0]));
        $this->assertSame(1, $tooSmall['limit']);
    }
}
