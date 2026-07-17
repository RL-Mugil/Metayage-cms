<?php

namespace Tests\Unit;

use App\Models\Firm;
use App\Support\FirmContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FirmContextTest extends TestCase
{
    public function test_context_can_be_set_and_cleared(): void
    {
        $firm = $this->firm(10);
        $context = new FirmContext;

        $context->set($firm);

        $this->assertTrue($context->hasFirm());
        $this->assertSame(10, $context->id());
        $this->assertSame($firm, $context->firm());

        $context->clear();

        $this->assertFalse($context->hasFirm());
        $this->assertNull($context->id());
    }

    public function test_run_restores_previous_context_after_failure(): void
    {
        $original = $this->firm(10);
        $temporary = $this->firm(20);
        $context = new FirmContext;
        $context->set($original);

        try {
            $context->run($temporary, function () use ($context): void {
                $this->assertSame(20, $context->id());
                throw new RuntimeException('Expected test exception');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Expected test exception', $exception->getMessage());
        }

        $this->assertSame(10, $context->id());
        $this->assertSame($original, $context->firm());
    }

    private function firm(int $id): Firm
    {
        $firm = new Firm(['name' => "Firm {$id}", 'slug' => "firm-{$id}"]);
        $firm->setAttribute('id', $id);

        return $firm;
    }
}
