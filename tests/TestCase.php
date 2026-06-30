<?php

namespace Tests;

use App\Services\Theme\ThemeSettingsService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The settings service memoises per request; flush it between tests so
        // a value set in one test can never leak into the next via the memo.
        ThemeSettingsService::flush();
    }
}
