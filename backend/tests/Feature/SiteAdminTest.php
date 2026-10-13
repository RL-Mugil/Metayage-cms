<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SiteSettings;
use App\Support\HtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteAdminTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => uniqid($role.'-', true).'@test.local',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'Active',
        ]);
    }

    // ── Badge ──

    public function test_super_admin_can_change_badge(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $this->putJson('/api/settings/badge', ['text' => 'Staging · v3'])->assertOk();

        $this->assertSame('Staging · v3', SiteSettings::badge());
    }

    public function test_non_admin_cannot_change_badge(): void
    {
        foreach (['partner', 'manager', 'associate'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->putJson('/api/settings/badge', ['text' => 'Hacked'])->assertForbidden();
        }
        $this->assertSame(SiteSettings::DEFAULT_BADGE, SiteSettings::badge());
    }

    // ── Maintenance mode ──

    public function test_non_admin_is_blocked_during_maintenance_but_admin_is_not(): void
    {
        SiteSettings::put('maintenance_enabled', 'true');

        $associate = $this->user('associate');
        $this->actingAs($associate)->get('/')->assertStatus(503)->assertSee('mugilvannan@myipstrategy.com');
        $this->actingAs($associate)->getJson('/api/settings')->assertStatus(503)->assertJson(['maintenance' => true]);

        $admin = $this->user('super_admin');
        $this->assertNotSame(503, $this->actingAs($admin)->get('/')->getStatusCode());
    }

    public function test_login_page_still_works_during_maintenance(): void
    {
        SiteSettings::put('maintenance_enabled', 'true');

        $this->get('/login')->assertOk();
    }

    public function test_only_super_admin_can_toggle_maintenance(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $this->putJson('/api/settings/maintenance', ['enabled' => true])->assertForbidden();

        Sanctum::actingAs($this->user('super_admin'));
        $this->putJson('/api/settings/maintenance', ['enabled' => true, 'title' => 'Servicing'])->assertOk();
        $this->assertTrue(SiteSettings::maintenance()['enabled']);
        $this->assertSame('Servicing', SiteSettings::maintenance()['title']);
    }

    // ── Legal pages ──

    public function test_super_admin_can_publish_a_legal_page_and_public_endpoint_serves_it(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $this->putJson('/api/settings/pages/privacy', [
            'html' => '<h1>Our Policy</h1><p style="text-align:center;color:#ff0000">Hello <b>world</b></p>',
        ])->assertOk();

        $this->getJson('/api/public/pages/privacy')
            ->assertOk()
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'Our Policy') && str_contains($html, 'text-align: center'));
    }

    public function test_non_admin_cannot_edit_legal_pages(): void
    {
        Sanctum::actingAs($this->user('manager'));

        $this->putJson('/api/settings/pages/terms', ['html' => '<p>x</p>'])->assertForbidden();
    }

    public function test_unknown_page_slug_is_404(): void
    {
        $this->getJson('/api/public/pages/secret')->assertNotFound();
    }

    public function test_sanitizer_strips_scripts_handlers_and_unsafe_urls(): void
    {
        $dirty = '<p onclick="steal()" style="color:red;background:url(javascript:x);position:fixed">Hi</p>'
            .'<script>alert(1)</script><a href="javascript:alert(1)">bad</a><a href="https://ok.test">ok</a>'
            .'<img src=x onerror=alert(1)><iframe src="//evil"></iframe>';

        $clean = HtmlSanitizer::clean($dirty);

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringNotContainsString('position', $clean);
        $this->assertStringNotContainsString('background', $clean);
        $this->assertStringContainsString('color: red', $clean);
        $this->assertStringContainsString('href="https://ok.test"', $clean);
    }
}
