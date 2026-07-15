<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformCertificateSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sets_platform_certificate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cert = Certificate::factory()->create();
        $settings = Setting::current();

        $this->actingAs($admin)->patch('/admin/settings', [
            'company_name' => $settings->company_name,
            'primary_color' => $settings->primary_color,
            'accent_color' => $settings->accent_color,
            'platform_certificate_id' => $cert->id,
        ])->assertRedirect();

        Setting::clearCache();
        $this->assertTrue(Setting::current()->platformCertificate->is($cert));
    }

    public function test_rejects_nonexistent_certificate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $settings = Setting::current();

        $this->actingAs($admin)->patch('/admin/settings', [
            'company_name' => $settings->company_name,
            'primary_color' => $settings->primary_color,
            'accent_color' => $settings->accent_color,
            'platform_certificate_id' => 9999,
        ])->assertSessionHasErrors('platform_certificate_id');
    }
}
