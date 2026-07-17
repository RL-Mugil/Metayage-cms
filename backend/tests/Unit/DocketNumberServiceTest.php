<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Project;
use App\Services\DocketNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocketNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_the_canonical_identifier_and_increments_by_invention(): void
    {
        $client = Client::create([
            'client_code' => '144M',
            'company_name' => 'Canonical Client',
            'status' => 'Active',
        ]);
        $service = app(DocketNumberService::class);

        $first = DB::transaction(fn () => $service->assignForCreation([
            'client_id' => $client->id,
            'patent_office_code' => 'in',
            'service_code' => 'fer',
        ]));
        Project::create(array_merge($first, [
            'project_name' => 'First invention',
            'project_type' => 'Patent',
        ]));
        $second = DB::transaction(fn () => $service->assignForCreation([
            'client_id' => $client->id,
            'patent_office_code' => 'US',
            'service_code' => 'FIL',
        ]));

        $this->assertSame('144M001INFER', $first['docket_number']);
        $this->assertSame($first['docket_number'], $first['project_code']);
        $this->assertSame('001', $first['invention_number']);
        $this->assertSame('144M002USFIL', $second['docket_number']);
    }

    public function test_it_rejects_a_noncanonical_import_identifier(): void
    {
        $client = Client::create([
            'client_code' => '144M',
            'company_name' => 'Canonical Client',
            'status' => 'Active',
        ]);

        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(DocketNumberService::class)->assignForCreation([
            'client_id' => $client->id,
            'docket_number' => 'LEGACY-123',
            'patent_office_code' => 'IN',
            'service_code' => 'FER',
        ]));
    }
}
