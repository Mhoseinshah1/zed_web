<?php

namespace Tests\Feature;

use App\Filament\Pages\SeoSettingsPage;
use App\Filament\Resources\SeoPageResource\Pages\EditSeoPage;
use App\Models\SeoPage;
use App\Models\User;
use App\Services\Seo\SeoSettings;
use Database\Seeders\SeoPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SeoAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new SeoPageSeeder)->run();
        SeoSettings::flush();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    public function test_seo_pages_resource_lists_and_loads(): void
    {
        $this->actingAs($this->admin())
            ->get('/zed-admin/seo-pages')
            ->assertSuccessful()
            ->assertSee('سئوی صفحات');
    }

    public function test_editing_a_seo_page_saves_and_affects_output(): void
    {
        $home = SeoPage::where('page_key', 'home')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(EditSeoPage::class, ['record' => $home->getRouteKey()])
            ->fillForm([
                'meta_title' => 'عنوان سفارشی زدپروکسی',
                'meta_description' => 'توضیحات سفارشی برای صفحه اصلی',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('عنوان سفارشی زدپروکسی', $home->fresh()->meta_title);

        // The saved value actually reaches the rendered <head>.
        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('عنوان سفارشی زدپروکسی', $html);
        $this->assertStringContainsString('توضیحات سفارشی برای صفحه اصلی', $html);
    }

    public function test_invalid_schema_override_is_rejected_on_save(): void
    {
        $home = SeoPage::where('page_key', 'home')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(EditSeoPage::class, ['record' => $home->getRouteKey()])
            ->fillForm(['schema_json_override' => '{not valid json'])
            ->call('save')
            ->assertHasFormErrors(['schema_json_override']);
    }

    public function test_global_seo_settings_page_saves_and_caches(): void
    {
        Livewire::actingAs($this->admin())
            ->test(SeoSettingsPage::class)
            ->fillForm([
                'seo_site_name' => 'زدپروکسی',
                'seo_default_title' => 'زدپروکسی | عنوان سراسری',
                'seo_org_name' => 'شرکت زدپروکسی',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        SeoSettings::flush();
        $this->assertSame('زدپروکسی', SeoSettings::siteName());
        $this->assertSame('شرکت زدپروکسی', SeoSettings::get('seo_org_name'));
    }

    public function test_invalid_analytics_id_is_rejected_by_the_form(): void
    {
        Livewire::actingAs($this->admin())
            ->test(SeoSettingsPage::class)
            ->fillForm(['seo_google_analytics_id' => 'not-a-ga-id'])
            ->call('save')
            ->assertHasFormErrors(['seo_google_analytics_id']);
    }

    public function test_saving_a_new_analytics_id_renders_immediately(): void
    {
        // Warm the 3600s settings cache with the empty value first.
        $this->get('/')->assertOk();

        Livewire::actingAs($this->admin())
            ->test(SeoSettingsPage::class)
            ->fillForm(['seo_google_analytics_id' => 'G-NEWID1234'])
            ->call('save')
            ->assertHasNoFormErrors();

        // No manual flush here — SeoSettingsPage::save() must bust the cache
        // itself so the new ID appears on the very next request.
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('gtag/js?id=G-NEWID1234', $html);
    }
}
