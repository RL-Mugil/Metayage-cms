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

    public function test_state_comes_from_the_pin_and_trailing_state_codes(): void
    {
        $p = PostalAddress::split('#09, East end D Main, Jayanagar 9th Block, Bengaluru,  , , 560069', null, 'India');
        $this->assertSame(['#09', 'East end D Main, Jayanagar 9th Block', 'Bengaluru', 'Karnataka', '560069', 'clean'],
            [$p['address_house'], $p['address_street'], $p['city'], $p['state'], $p['pincode'], $p['confidence']]);

        $p = PostalAddress::split('Vindhya C5, 3rd Floor, Gachibowli, Hyderabad Hyderabad TG 500032, IN', null, 'India');
        $this->assertSame(['Hyderabad', 'Telangana', '500032'], [$p['city'], $p['state'], $p['pincode']]);

        // No comma before the city.
        $p = PostalAddress::split('51 MGR Road Besant nagar Chennai TN 600090', null, 'India');
        $this->assertSame(['Chennai', 'Tamil Nadu'], [$p['city'], $p['state']]);

        $this->assertSame('Delhi', PostalAddress::indianStateFromPin('110001'));
        $this->assertSame('Goa', PostalAddress::indianStateFromPin('403001'));
        $this->assertSame('Jharkhand', PostalAddress::indianStateFromPin('834001'));
        $this->assertSame('', PostalAddress::indianStateFromPin('56008'));
    }

    public function test_curly_quotes_survive(): void
    {
        $p = PostalAddress::split('MNG House, “A” Wing, Karve Nagar, Pune – 411052', null, 'India');
        $this->assertStringContainsString('“A” Wing', $p['address_street']);
        $this->assertTrue(mb_check_encoding($p['address_street'], 'UTF-8'));
        $this->assertSame(['Pune', 'Maharashtra', '411052'], [$p['city'], $p['state'], $p['pincode']]);
    }

    public function test_us_singapore_and_unknown_country(): void
    {
        $p = PostalAddress::split('8, The Green, Ste A, Dover, County Kent, Delaware, 19901, USA', null, 'US');
        $this->assertSame(['Dover', 'Delaware', '19901', 'clean'], [$p['city'], $p['state'], $p['pincode'], $p['confidence']]);

        $p = PostalAddress::split('2121 Crigan Bluff Drive, Cary, NC 27513, USA', null, 'US');
        $this->assertSame(['Cary', 'North Carolina', '27513'], [$p['city'], $p['state'], $p['pincode']]);

        // No city found: never "clean".
        $p = PostalAddress::split('4021 262nd PL SE Sammamish, WA, USA 98029', null, 'US');
        $this->assertSame(['', 'Washington', '98029', 'partial'], [$p['city'], $p['state'], $p['pincode'], $p['confidence']]);

        $p = PostalAddress::split('105 CECIL STREET #13-01 THE OCTAGON SINGAPORE -069534', null, 'SG');
        $this->assertSame(['Singapore', '069534', 'clean'], [$p['city'], $p['pincode'], $p['confidence']]);

        // Stored country "EP": the country is read from the address.
        $p = PostalAddress::split('3675 Market Street, Suite 200, Philadelphia, PA, USA - 19104', null, 'EP');
        $this->assertSame(['Philadelphia', 'Pennsylvania', '19104'], [$p['city'], $p['state'], $p['pincode']]);

        $p = PostalAddress::split('Briton House, Briton Street, Southampton, SO14 3EB', null, 'GB');
        $this->assertSame(['Southampton', 'SO14 3EB'], [$p['city'], $p['pincode']]);
    }

    public function test_compose(): void
    {
        $this->assertSame('No. 12, MG Road, Bengaluru, Karnataka, India - 560001', PostalAddress::compose([
            'address_house' => 'No. 12', 'address_street' => 'MG Road', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'country' => 'India', 'pincode' => '560001',
        ]));
        $this->assertSame('', PostalAddress::compose([]));
    }
}
