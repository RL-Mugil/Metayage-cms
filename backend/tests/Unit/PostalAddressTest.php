<?php

namespace Tests\Unit;

use App\Support\PostalAddress;
use PHPUnit\Framework\TestCase;

class PostalAddressTest extends TestCase
{
    public function test_typical_indian_address_with_house_number(): void
    {
        $p = PostalAddress::split('207, EGPI Arcadia, Banaswadi Main Road, Bengaluru, Karnataka 560033, India', null, 'India');
        $this->assertSame('207, EGPI Arcadia', $p['address_house']);
        $this->assertSame('Banaswadi Main Road', $p['address_street']);
        $this->assertSame('Bengaluru', $p['city']);
        $this->assertSame('Karnataka', $p['state']);
        $this->assertSame('560033', $p['pincode']);
        $this->assertSame('clean', $p['confidence']);
    }

    public function test_pin_with_space_and_dash_and_known_state(): void
    {
        $p = PostalAddress::split("Plot 14, Phase 2,\nElectronic City, Bangalore - 560 100", 'Karnataka', 'India');
        $this->assertSame('560100', $p['pincode']);
        $this->assertSame('Bangalore', $p['city']);
        $this->assertSame('Karnataka', $p['state']);
        $this->assertSame('Plot 14', $p['address_house']);
        $this->assertSame('Phase 2, Electronic City', $p['address_street']);
    }

    public function test_address_that_cannot_be_split_keeps_text_in_street(): void
    {
        $p = PostalAddress::split('Near Bus Stand Main Road', null, 'India');
        $this->assertSame('', $p['city']);
        $this->assertSame('Near Bus Stand Main Road', $p['address_street']);
        $this->assertSame('partial', $p['confidence']);
    }

    public function test_foreign_address_and_empty_input(): void
    {
        $p = PostalAddress::split('1 Infinite Loop, Cupertino, California, USA 95014', 'California', 'USA');
        $this->assertSame('95014', $p['pincode']);
        $this->assertSame('Cupertino', $p['city']);
        $this->assertSame('', PostalAddress::split('', null, 'India')['address_street']);
    }

    public function test_compose(): void
    {
        $this->assertSame('No. 12, MG Road, Bengaluru, Karnataka, India - 560001', PostalAddress::compose([
            'address_house' => 'No. 12', 'address_street' => 'MG Road', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'country' => 'India', 'pincode' => '560001',
        ]));
        $this->assertSame('', PostalAddress::compose([]));
    }
}
