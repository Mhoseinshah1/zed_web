<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAuthTest extends TestCase
{
    use RefreshDatabase;

    // ── Route availability ──────────────────────────────────────────────────

    public function test_login_page_returns_200(): void
    {
        $response = $this->get('/login');
        $response->assertOk();
    }

    public function test_register_page_returns_200(): void
    {
        $response = $this->get('/register');
        $response->assertOk();
    }

    public function test_post_login_is_not_405(): void
    {
        $response = $this->post('/login', [
            'username' => 'nobody',
            'password' => 'wrongpassword',
        ]);

        $this->assertNotEquals(405, $response->getStatusCode(),
            'POST /login must not return 405 — route must accept POST'
        );
    }

    public function test_post_register_is_not_405(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'Test User',
            'username'              => 'testuser',
            'email'                 => 'test@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertNotEquals(405, $response->getStatusCode(),
            'POST /register must not return 405 — route must accept POST'
        );
    }

    // ── Login form fields ───────────────────────────────────────────────────

    public function test_login_page_contains_username_field(): void
    {
        $this->get('/login')->assertSee('name="username"', false);
    }

    public function test_login_page_does_not_contain_email_type_input(): void
    {
        $this->get('/login')->assertDontSee('type="email"', false);
    }

    public function test_register_page_contains_username_field(): void
    {
        $this->get('/register')->assertSee('name="username"', false);
    }

    // ── Registration ────────────────────────────────────────────────────────

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'New User',
            'username'              => 'newuser',
            'email'                 => 'newuser@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard.index'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['username' => 'newuser', 'email' => 'newuser@example.com']);
    }

    public function test_registration_fails_with_duplicate_username(): void
    {
        User::factory()->create(['username' => 'taken', 'email' => 'taken@example.com']);

        $response = $this->post('/register', [
            'name'                  => 'Another User',
            'username'              => 'taken',
            'email'                 => 'other@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['username' => 'user1', 'email' => 'dup@example.com']);

        $response = $this->post('/register', [
            'name'                  => 'Another User',
            'username'              => 'user2',
            'email'                 => 'dup@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ── Login ────────────────────────────────────────────────────────────────

    public function test_user_can_login_with_valid_username_and_password(): void
    {
        $user = User::factory()->create([
            'username' => 'loginuser',
            'password' => bcrypt('mypassword'),
        ]);

        $response = $this->post('/login', [
            'username' => 'loginuser',
            'password' => 'mypassword',
        ]);

        $response->assertRedirect(route('dashboard.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'username' => 'loginuser2',
            'password' => bcrypt('correctpassword'),
        ]);

        $response = $this->post('/login', [
            'username' => 'loginuser2',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_login_fails_with_nonexistent_username(): void
    {
        $response = $this->post('/login', [
            'username' => 'nobody',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    // ── Logout ───────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create([
            'username' => 'logoutuser',
        ]);

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect(route('home'));
        $this->assertGuest();
    }

    // ── Panel access ─────────────────────────────────────────────────────────

    public function test_unauthenticated_user_is_redirected_from_dashboard(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirectContains('/login');
    }

    public function test_authenticated_user_can_access_dashboard(): void
    {
        $user = User::factory()->create(['username' => 'paneluser']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
    }

    // ── Admin panel unaffected ────────────────────────────────────────────────

    public function test_zed_admin_path_still_works(): void
    {
        $response = $this->get('/zed-admin');
        $response->assertRedirectContains('/zed-admin/login');
    }

    public function test_zed_admin_login_page_still_renders(): void
    {
        $response = $this->get('/zed-admin/login');
        $response->assertOk();
    }
}
