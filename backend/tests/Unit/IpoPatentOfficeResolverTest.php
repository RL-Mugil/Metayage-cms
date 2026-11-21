<?php

namespace Tests\Unit;

use App\Services\Ipo\PatentOfficeResolver;
use Tests\TestCase;

class IpoPatentOfficeResolverTest extends TestCase
{
    public function test_each_office_resolves_from_its_states(): void
    {
        $resolver = new PatentOfficeResolver;

        $this->assertSame('Chennai', $resolver->forState('Karnataka'));
        $this->assertSame('Chennai', $resolver->forState('Kerala'));
        $this->assertSame('Chennai', $resolver->forState('Andhra Pradesh'));
        $this->assertSame('Mumbai', $resolver->forState('Gujarat'));
        $this->assertSame('Mumbai', $resolver->forState('Maharashtra'));
        $this->assertSame('Delhi', $resolver->forState('Uttar Pradesh'));
        $this->assertSame('Kolkata', $resolver->forState('West Bengal'));
    }

    public function test_spelling_variants_and_union_territories_resolve(): void
    {
        $resolver = new PatentOfficeResolver;

        $this->assertSame('Chennai', $resolver->forState('  tamil   NADU '));
        $this->assertSame('Delhi', $resolver->forState('Jammu & Kashmir'));
        $this->assertSame('Kolkata', $resolver->forState('Andaman & Nicobar Islands'));
        $this->assertSame('Mumbai', $resolver->forState('Dadra & Nagar Haveli'));
        $this->assertSame('Kolkata', $resolver->forState('Orissa'));
    }

    public function test_unknown_or_empty_state_has_no_office(): void
    {
        $resolver = new PatentOfficeResolver;

        $this->assertNull($resolver->forState(null));
        $this->assertNull($resolver->forState(''));
        $this->assertNull($resolver->forState('California'));
        $this->assertTrue($resolver->isOffice('Kolkata'));
        $this->assertFalse($resolver->isOffice('Bangalore'));
    }
}
