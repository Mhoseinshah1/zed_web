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
            // one Arabic/Persian face + one Latin face per weight
            $this->assertSame(2, substr_count($css, "font-weight: {$weight};"), "weight {$weight} must have exactly two subset faces");
        }
        $this->assertStringNotContainsString('font-weight: 300', $css);
        $this->assertSame(12, substr_count($css, '@font-face'));

        $files = glob(resource_path('fonts/vazirmatn/*.woff2'));
        $this->assertCount(12, $files);
        $this->assertCount(6, array_filter($files, fn ($f) => str_contains($f, '-arabic.woff2')));
        $this->assertCount(6, array_filter($files, fn ($f) => str_contains($f, '-latin.woff2')));
        foreach ($files as $f) {
            $this->assertStringNotContainsString('Light', basename($f), 'no Light/ExtraLight weight may ship');
        }
    }

    public function test_unicode_ranges_are_present_and_non_overlapping(): void
    {
        $css = $this->fontsCss();
        $this->assertSame(12, substr_count($css, 'unicode-range:'), 'every face must declare a unicode-range');

        // Expand both range sets and prove they never intersect.
        preg_match('/-arabic\.woff2[^;]*;\s*unicode-range:\s*([^;]+);/s', $css, $ar);
        preg_match('/-latin\.woff2[^;]*;\s*unicode-range:\s*([^;]+);/s', $css, $la);
        $expand = function (string $spec): array {
            $points = [];
            foreach (array_map('trim', explode(',', $spec)) as $part) {
                $part = strtoupper(str_replace('U+', '', $part));
                [$a, $b] = array_pad(explode('-', $part), 2, null);
                $from = hexdec($a);
                $to = $b === null ? $from : hexdec($b);
                $points[] = [$from, $to];
            }

            return $points;
        };
        $arabic = $expand($ar[1]);
        $latin = $expand($la[1]);
        foreach ($arabic as [$a1, $a2]) {
            foreach ($latin as [$l1, $l2]) {
                $this->assertTrue($a2 < $l1 || $l2 < $a1, sprintf('ranges overlap: %X-%X vs %X-%X', $a1, $a2, $l1, $l2));
            }
        }
        // The Arabic set must cover Persian letters, Persian digits, and ZWNJ.
        foreach ([0x0645, 0x06CC, 0x067E, 0x06AF, 0x0686, 0x0698, 0x06F0, 0x06F9, 0x200C] as $cp) {
            $covered = false;
            foreach ($arabic as [$a1, $a2]) {
                if ($cp >= $a1 && $cp <= $a2) {
                    $covered = true;
                }
            }
            $this->assertTrue($covered, sprintf('U+%04X must be in the Arabic subset ranges', $cp));
        }
    }

    public function test_every_face_swaps_and_the_license_ships_beside_the_fonts(): void
    {
        $this->assertSame(12, substr_count($this->fontsCss(), 'font-display: swap;'), 'every face must use font-display: swap');
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

        // withoutVite() blanks the hrefs in rendered HTML, so the weight/subset
        // selection is asserted against the single shared partial itself.
        $partial = file_get_contents(resource_path('views/partials/font-preloads.blade.php'));
        $this->assertStringContainsString('Regular-arabic.woff2', $partial);
        $this->assertStringContainsString('Bold-arabic.woff2', $partial);
        $this->assertStringNotContainsString('latin.woff2', $partial, 'the Latin subset must never be preloaded');
        foreach (['Medium', 'SemiBold', 'ExtraBold', 'Black', 'Light'] as $not) {
            $this->assertStringNotContainsString($not.'-arabic.woff2', $partial, "{$not} must not be preloaded");
        }
        $this->assertSame(2, substr_count($partial, 'rel="preload"'));
    }

    public function test_built_css_references_local_font_assets_that_exist(): void
    {
        // The PHP CI job runs without compiled assets; the Frontend Build job
        // asserts the manifest's font entries authoritatively after building.
        if (! is_file(public_path('build/manifest.json'))) {
            $this->markTestSkipped('no Vite build present (verified by the Frontend Build job)');
        }

        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $this->assertIsArray($manifest);

        $found = 0;
        foreach ($manifest as $key => $entry) {
            if (str_contains($key, 'fonts/vazirmatn') && str_ends_with($key, '.woff2')) {
                $found++;
                $this->assertFileExists(public_path('build/'.$entry['file']));
            }
        }
        $this->assertSame(12, $found, 'all twelve subset woff2 files must be in the Vite manifest');
    }
}
