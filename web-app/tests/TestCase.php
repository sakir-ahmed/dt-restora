<?php

declare(strict_types=1);

namespace Tests;

use App\Models\MenuItem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Category pre-order rules are remembered per process; every test starts clean.
        MenuItem::forgetCategoryRules();
    }
}
