<?php

namespace Tests;

use App\Services\Theme\ThemeSettingsService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stub Vite so view-rendering tests don't require a compiled asset
        // manifest (public/build/manifest.json). Without this, any test that
        // renders a Blade layout using @vite throws a ViewException → HTTP 500
        // in CI environments that don't run `npm run build`.
        $this->withoutVite();

        // The settings service memoises per request; flush it between tests so
        // a value set in one test can never leak into the next via the memo.
        ThemeSettingsService::flush();
    }
}
