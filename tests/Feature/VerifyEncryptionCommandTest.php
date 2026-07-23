<?php

namespace Tests\Feature;

use App\Models\VpnPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers zedproxy:verify-encryption — the installer's post-upgrade guard that
 * proves the APP_KEY still decrypts existing secrets. Values are never printed.
 */
class VerifyEncryptionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_when_no_encrypted_data_exists(): void
    {
        $this->artisan('zedproxy:verify-encryption')
            ->expectsOutputToContain('هیچ داده رمزگذاری‌شده‌ای برای بررسی وجود ندارد.')
            ->assertExitCode(0);
    }

    public function test_passes_when_encrypted_secrets_decrypt(): void
    {
        VpnPanel::create([
            'name' => 'Panel A',
            'type' => VpnPanel::TYPE_MARZBAN,
            'base_url' => 'https://panel.example.com',
            'api_token' => 'super-secret-token',
            'password' => 'super-secret-pass',
        ]);

        // Sanity: the value round-trips through the encrypted cast.
        $this->assertSame('super-secret-token', VpnPanel::first()->api_token);

        $this->artisan('zedproxy:verify-encryption')
            ->expectsOutputToContain('با موفقیت تایید شد')
            ->assertExitCode(0);
    }

    public function test_fails_when_ciphertext_cannot_be_decrypted(): void
    {
        $panel = VpnPanel::create([
            'name' => 'Panel B',
            'type' => VpnPanel::TYPE_MARZBAN,
            'base_url' => 'https://panel.example.com',
            'api_token' => 'super-secret-token',
        ]);

        // Simulate a changed APP_KEY / corrupted ciphertext by writing a value
        // that is not a valid Laravel encryption payload for the current key.
        DB::table('vpn_panels')->where('id', $panel->id)->update([
            'api_token' => base64_encode('this-is-not-valid-ciphertext'),
        ]);

        $this->artisan('zedproxy:verify-encryption')
            ->expectsOutputToContain('خطا در رمزگشایی اطلاعات حساس')
            ->assertExitCode(1);
    }

    public function test_never_prints_the_decrypted_value(): void
    {
        VpnPanel::create([
            'name' => 'Panel C',
            'type' => VpnPanel::TYPE_MARZBAN,
            'base_url' => 'https://panel.example.com',
            'api_token' => 'TOP-SECRET-NEEDLE',
        ]);

        $this->artisan('zedproxy:verify-encryption')
            ->doesntExpectOutputToContain('TOP-SECRET-NEEDLE')
            ->assertExitCode(0);
    }
}
