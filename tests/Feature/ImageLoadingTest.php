<?php

namespace Tests\Feature;

use App\Models\Tutorial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Image loading/decoding/CLS strategy: header logos are above-the-fold LCP
 * candidates (eager + high priority — never lazy); footer logos, listing
 * thumbnails, banners, and ticket attachments are lazy/async; the tutorial
 * detail hero is eager/high; every image keeps a non-empty alt; CMS logos are
 * never given fabricated intrinsic dimensions.
 */
class ImageLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_logo_partial_is_eager_for_headers_and_lazy_for_footers(): void
    {
        $partial = file_get_contents(resource_path('views/partials/site-logo.blade.php'));

        $this->assertStringContainsString('loading="eager" fetchpriority="high"', $partial);
        $this->assertStringContainsString('loading="lazy" decoding="async"', $partial);
        // CMS dimensions are unknown → no fabricated width/height attributes.
        $this->assertStringNotContainsString('width=', $partial);
        $this->assertStringNotContainsString('height=', $partial);
        $this->assertStringContainsString('alt=', $partial);
    }

    public function test_every_template_header_and_footer_uses_the_shared_logo_partial(): void
    {
        foreach (['classic', 'map', 'matrix', 'shop', 'woodmart', 'modern'] as $tpl) {
            $header = file_get_contents(resource_path("views/templates/{$tpl}/header.blade.php"));
            $footer = file_get_contents(resource_path("views/templates/{$tpl}/footer.blade.php"));

            $this->assertStringNotContainsString('<img', $header, "{$tpl} header must use the shared partial");
            $this->assertStringNotContainsString('<img', $footer, "{$tpl} footer must use the shared partial");
            $this->assertStringContainsString("'eager' => true", $header, "{$tpl} header logo must be eager");
            $this->assertStringContainsString('partials.site-logo', $footer);
            $this->assertStringNotContainsString("'eager' => true", $footer, "{$tpl} footer logo must stay lazy");
        }
    }

    public function test_tutorial_listing_thumbnails_are_lazy_and_async(): void
    {
        Tutorial::create([
            'title' => 'آموزش تست', 'slug' => 'test-tutorial', 'platform' => 'android',
            'content' => 'x', 'image' => 'tutorials/pic.jpg', 'is_active' => true,
        ]);

        $html = $this->get('/tutorials')->assertOk()->getContent();
        preg_match('#<img(?:->|[^>])*tutorials/pic\.jpg[^>]*>#', $html, $m);
        $this->assertNotEmpty($m, 'thumbnail must render');
        $this->assertStringContainsString('loading="lazy"', $m[0]);
        $this->assertStringContainsString('decoding="async"', $m[0]);
        $this->assertStringContainsString('object-cover', $m[0]);
        $this->assertMatchesRegularExpression('#alt="[^"]+"#', $m[0]);
    }

    public function test_tutorial_detail_hero_is_eager_high_priority_with_reserved_ratio(): void
    {
        Tutorial::create([
            'title' => 'آموزش تست', 'slug' => 'test-tutorial', 'platform' => 'android',
            'content' => 'x', 'image' => 'tutorials/pic.jpg', 'is_active' => true,
        ]);

        $html = $this->get('/tutorials/test-tutorial')->assertOk()->getContent();
        preg_match('#<img(?:->|[^>])*tutorials/pic\.jpg[^>]*>#s', $html, $m);
        $this->assertNotEmpty($m, 'hero must render');
        $this->assertStringContainsString('loading="eager"', $m[0]);
        $this->assertStringContainsString('fetchpriority="high"', $m[0]);
        $this->assertStringNotContainsString('loading="lazy"', $m[0]);
        $this->assertStringContainsString('aspect-video', $m[0]);
        $this->assertMatchesRegularExpression('#alt="[^"]+"#', $m[0]);
    }

    public function test_banner_and_ticket_attachment_images_are_lazy_async(): void
    {
        foreach (['partials/banners.blade.php', 'partials/ticket-attachments.blade.php'] as $view) {
            $src = file_get_contents(resource_path('views/'.$view));
            preg_match_all('#<img(?:->|[^>])*>#s', $src, $m);
            $this->assertNotEmpty($m[0], $view);
            foreach ($m[0] as $tag) {
                $this->assertStringContainsString('loading="lazy"', $tag, $view);
                $this->assertStringContainsString('decoding="async"', $tag, $view);
            }
        }
    }

    public function test_every_view_image_has_a_non_empty_alt_attribute(): void
    {
        $files = glob(resource_path('views/{,*/,*/*/,*/*/*/}*.blade.php'), GLOB_BRACE) ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            preg_match_all('#<img(?:->|[^>])*>#s', file_get_contents($file), $m);
            foreach ($m[0] as $tag) {
                $this->assertMatchesRegularExpression('#alt="[^"]#', $tag, "image without alt in {$file}: {$tag}");
            }
        }
    }

    public function test_no_lazy_loaded_image_in_any_template_header(): void
    {
        foreach (glob(resource_path('views/templates/*/header.blade.php')) as $file) {
            $this->assertStringNotContainsString('loading="lazy"', file_get_contents($file), $file);
        }
    }
}
