<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Self-hosted Vazirmatn: no production page may request Google Fonts (render
 * blocking and frequently unreachable from Iran, the primary audience); only
 * the six weights actually used ship (300 never); every face swaps; only
 * 400 + 700 are preloaded, via ONE shared partial used by the public app
 * layout and the user panel layout.
 */
class FontDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function fontsCss(): string
    {
        return file_get_contents(resource_path('css/fonts.css'));
    }

    public function test_no_google_fonts_hosts_in_rendered_pages(): void
    {
        $public = $this->get('/')->assertOk()->getContent();
        $panel = $this->actingAs(User::factory()->create())->get('/dashboard')->getContent();

        foreach ([$public, $panel] as $html) {
            $this->assertStringNotContainsString('fonts.googleapis.com', $html);
            $this->assertStringNotContainsString('fonts.gstatic.com', $html);
        }
    }

    public function test_no_google_fonts_hosts_in_production_views_or_css(): void
    {
        $paths = array_merge(
            glob(resource_path('views/**/*.blade.php')) ?: [],
            glob(resource_path('views/*.blade.php')) ?: [],
            glob(resource_path('css/*.css')) ?: [],
        );
        $this->assertNotEmpty($paths);

        foreach ($paths as $file) {
            $content = file_get_contents($file);
            // fonts.css mentions the host in a prose comment; strip comments.
            $content = preg_replace('#/\*.*?\*/#s', '', $content);
            $this->assertStringNotContainsString('fonts.googleapis.com', $content, $file);
            $this->assertStringNotContainsString('fonts.gstatic.com', $content, $file);
        }
    }

    public function test_exactly_the_six_required_weights_exist_and_weight_300_is_absent(): void
    {
        $css = $this->fontsCss();

        foreach ([400, 500, 600, 700, 800, 900] as $weight) {
            $this->assertSame(1, substr_count($css, "font-weight: {$weight};"), "weight {$weight} must exist exactly once");
        }
        $this->assertStringNotContainsString('font-weight: 300', $css);
        $this->assertSame(6, substr_count($css, '@font-face'));

        $files = glob(resource_path('fonts/vazirmatn/*.woff2'));
        $this->assertCount(6, $files);
        foreach ($files as $f) {
            $this->assertStringNotContainsString('Light', basename($f), 'no Light/ExtraLight weight may ship');
        }
    }

    public function test_every_face_swaps_and_the_license_ships_beside_the_fonts(): void
    {
        $this->assertSame(6, substr_count($this->fontsCss(), 'font-display: swap;'), 'every face must use font-display: swap');
        $this->assertFileExists(resource_path('fonts/vazirmatn/OFL.txt'));
        $this->assertStringContainsString('SIL OPEN FONT LICENSE', strtoupper(file_get_contents(resource_path('fonts/vazirmatn/OFL.txt'))));
    }

    public function test_only_weights_400_and_700_are_preloaded_on_public_and_panel_pages(): void
    {
        $public = $this->get('/')->assertOk()->getContent();
        $panel = $this->actingAs(User::factory()->create())->get('/dashboard')->getContent();

        foreach (['public' => $public, 'panel' => $panel] as $name => $html) {
            preg_match_all('#<link rel="preload"[^>]*as="font"[^>]*>#', $html, $m);
            $this->assertCount(2, $m[0], "{$name} page must preload exactly two fonts");
            foreach ($m[0] as $tag) {
                $this->assertStringContainsString('type="font/woff2"', $tag);
                $this->assertStringContainsString('crossorigin', $tag);
            }
        }

        // withoutVite() blanks the hrefs in rendered HTML, so the weight
        // selection is asserted against the single shared partial itself.
        $partial = file_get_contents(resource_path('views/partials/font-preloads.blade.php'));
        $this->assertStringContainsString('Regular.woff2', $partial);
        $this->assertStringContainsString('-Bold.woff2', $partial);
        foreach (['Medium', 'SemiBold', 'ExtraBold', 'Black', 'Light'] as $not) {
            $this->assertStringNotContainsString($not.'.woff2', $partial, "{$not} must not be preloaded");
        }
        $this->assertSame(2, substr_count($partial, 'rel="preload"'));
    }

    public function test_built_css_references_local_font_assets_that_exist(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $this->assertIsArray($manifest);

        $found = 0;
        foreach ($manifest as $key => $entry) {
            if (str_contains($key, 'fonts/vazirmatn') && str_ends_with($key, '.woff2')) {
                $found++;
                $this->assertFileExists(public_path('build/'.$entry['file']));
            }
        }
        $this->assertSame(6, $found, 'all six woff2 files must be in the Vite manifest');
    }
}
