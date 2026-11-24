<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\ActionItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Patent Portfolio → Action Required: each row opens where its action is done. */
class ActionItemTargetTest extends TestCase
{
    use RefreshDatabase;

    private function target(array $project, string $stage = '', string $pending = 'Awaiting next action', bool $renewal = false): array
    {
        $p = (new Project())->forceFill($project + ['id' => 12, 'docket_number' => '940M001INFER', 'patent_office_code' => 'IN', 'urgency' => 'Medium']);
        $svc = strtoupper($p->service_code ?? '');

        return app(ActionItemService::class)->actionTarget($p, $stage, $svc, $pending, $renewal);
    }

    public function test_each_action_opens_its_tab(): void
    {
        $this->assertSame('/projects/12?tab=deadlines', $this->target(['service_code' => 'FER'], 'Response Deadline Docketed')['url']);
        $this->assertSame('/pending-payments?search=940M001INFER', $this->target(['service_code' => 'REN'], '', 'Renewal due', true)['url']);
        $this->assertSame('forms', $this->target(['service_code' => 'CPT'], 'Forms Prepared', 'Ready to file')['tab']);
        $this->assertSame('deadlines', $this->target(['service_code' => 'NPV', 'patent_office_code' => 'US'], 'Forms Prepared', 'Ready to file')['tab']);
        $this->assertSame('documents', $this->target(['service_code' => 'PRV'], 'Awaiting IDF', 'Awaiting IDF')['tab']);
        $this->assertSame('discussion', $this->target(['service_code' => 'CPT'], 'Client Review', 'Awaiting client approval')['tab']);
        $this->assertSame('deadlines', $this->target(['service_code' => 'PRV', 'urgency' => 'Critical'], 'Drafting Started')['tab']);
        $this->assertSame(['tab' => 'lifecycle', 'label' => 'Update stage', 'url' => '/projects/12?tab=lifecycle'],
            $this->target(['service_code' => 'PRV'], 'Drafting Started', 'Drafting in progress'));
    }
}
