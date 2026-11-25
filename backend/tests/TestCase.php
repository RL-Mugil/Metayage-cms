<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // CI runs the backend without building the frontend: pages render without the Vite asset tags.
        $this->withoutVite();
    }
}
