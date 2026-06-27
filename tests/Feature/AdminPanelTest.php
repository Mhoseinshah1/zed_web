<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login as AdminLogin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    // ── Panel path ──────────────────────────────────────────────────────────

    public function test_zed_admin_path_redirects_unauthenticated_users_to_login(): void
    {
        $response = $this->get('/zed-admin');

        // Filament redirects to the panel login page
        $response->assertRedirectContains('/zed-admin/login');
    }

    public function test_zed_admin_login_page_renders(): void
    {
        $response = $this->get('/zed-admin/login');

        $response->assertOk();
    }

    public function test_old_admin_path_is_not_the_configured_panel(): void
    {
        // /admin is no longer the Filament admin — it should not return the panel
        $response = $this->get('/admin');

        // Expect 404 (no route) rather than 302 to /admin/login
        $response->assertStatus(404);
    }

    // ── Login form ───────────────────────────────────────────────────────────

    public function test_login_form_contains_username_field(): void
    {
        $response = $this->get('/zed-admin/login');

        $response->assertOk();
        // The Livewire form field is named "username" — check it appears in the page source
        $response->assertSee('username');
    }

    public function test_login_form_does_not_contain_email_type_input(): void
    {
        $response = $this->get('/zed-admin/login');

        $response->assertOk();
        // There should be no <input type="email"> on the admin login page
        $response->assertDontSee('type="email"', false);
    }

    public function test_direct_post_to_login_url_returns_405(): void
    {
        // Filament uses Livewire — login submits via POST /livewire/update, not via
        // a direct POST to /zed-admin/login. A direct POST here MUST return 405 so
        // that if Livewire's wire:submit fails to bind (e.g. JS not loaded), the
        // browser gets a clear 405 rather than silently proceeding.
        $response = $this->post('/zed-admin/login', [
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertStatus(405);
    }

    public function test_livewire_update_endpoint_accepts_post(): void
    {
        // The Livewire XHR update endpoint must always accept POST.
        // If this returns 405, Livewire's wire:submit handler fails and the
        // login form shows "405 Method Not Allowed" in the error dialog.
        $response = $this->post('/livewire/update', [], [
            'X-Livewire' => 'true',
            'Content-Type' => 'application/json',
        ]);

        // Livewire may return 422/500 for an invalid payload but never 405
        $this->assertNotEquals(405, $response->getStatusCode(),
            '/livewire/update must not return 405 — only POST is registered on this route'
        );
    }

    // ── Authentication ───────────────────────────────────────────────────────

    public function test_admin_can_login_with_username_and_password(): void
    {
        $admin = User::factory()->create([
            'username'          => 'testadmin',
            'password'          => bcrypt('secret123'),
            'is_admin'          => true,
            'email_verified_at' => now(),
        ]);

        Livewire::test(AdminLogin::class)
            ->fillForm([
                'username' => 'testadmin',
                'password' => 'secret123',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_non_admin_user_cannot_access_panel_after_login(): void
    {
        User::factory()->create([
            'username' => 'regularuser',
            'password' => bcrypt('secret123'),
            'is_admin' => false,
        ]);

        // canAccessPanel() returns false — Filament should reject the login
        Livewire::test(AdminLogin::class)
            ->fillForm([
                'username' => 'regularuser',
                'password' => 'secret123',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['username']);

        $this->assertGuest();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'username' => 'testadmin2',
            'password' => bcrypt('correctpassword'),
            'is_admin' => true,
        ]);

        Livewire::test(AdminLogin::class)
            ->fillForm([
                'username' => 'testadmin2',
                'password' => 'wrongpassword',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['username']);

        $this->assertGuest();
    }
}
