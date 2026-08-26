<?php

namespace Tests\Feature;

use App\Services\JurisdictionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Coverage-regression guard: every India/US service code
 * ProjectController::stagesForServiceCode() understands must also resolve a
 * DB-driven JurisdictionLifecycleTemplate, now that
 * ProjectController::createProjectWithCodes() prefers the DB-driven system
 * and only falls back to the hardcoded list when no template matches. If a
 * future service code is added to one list without the other, project
 * creation for it silently regresses to the legacy stage list — this test
 * catches that before it ships.
 */
class LifecycleTemplateCoverageTest extends TestCase
{
    use RefreshDatabase;

    public const IN_SERVICE_CODES = [
        'PRV', 'CPT', 'CPD', 'FER', 'SER', 'TER', 'HRG', 'RNF',
        'PAS', 'SRH', 'PAT', 'FTO', 'CPE', 'CVP', 'PCT', 'NAP', 'NPE', 'NAF', 'NPA',
        'DVA', 'PAD', '9EP', '98A', '18F', '18A', 'GRT', 'RPO', 'ABN', 'PGO', 'WDR',
        'OPP', '27F', 'ROA', 'ERH', '24F',
    ];

    public const US_SERVICE_CODES = [
        'PRV', 'NPV', 'NPD', 'NPP', 'NPS', 'CNS', 'DIV', 'CIP', 'OAR', 'AFT', 'RCE',
        'APP', 'ISF', 'M35', 'M75', 'M15', 'REI', 'XPR', 'REV', 'IPR', 'PGR',
    ];

    public function test_every_india_service_code_resolves_a_lifecycle_template(): void
    {
        $service = app(JurisdictionLifecycleService::class);
        foreach (self::IN_SERVICE_CODES as $code) {
            try {
                $template = $service->resolve('IN', $code);
            } catch (ValidationException $e) {
                $this->fail("No IN lifecycle template resolves for service code {$code}: ".$e->getMessage());
            }
            $this->assertNotEmpty($template->stages, "IN template for {$code} has no stages.");
        }
    }

    public function test_every_us_service_code_resolves_a_lifecycle_template(): void
    {
        $service = app(JurisdictionLifecycleService::class);
        foreach (self::US_SERVICE_CODES as $code) {
            try {
                $template = $service->resolve('US', $code);
            } catch (ValidationException $e) {
                $this->fail("No US lifecycle template resolves for service code {$code}: ".$e->getMessage());
            }
            $this->assertNotEmpty($template->stages, "US template for {$code} has no stages.");
        }
    }
}
