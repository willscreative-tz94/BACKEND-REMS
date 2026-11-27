<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SplashSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['platform_role' => 'owner'])->save();

        return $user;
    }

    public function test_app_config_falls_back_to_bundled_defaults(): void
    {
        $this->getJson('/api/app-config')->assertOk()
            ->assertJsonPath('splash.video_url', null)
            ->assertJsonPath('splash.poster_url', null)
            ->assertJsonPath('splash.video_enabled', true)
            ->assertJsonPath('splash.overlay', 55);
    }

    public function test_super_admin_can_upload_video_poster_and_copy(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->post('/api/platform/splash', [
            'video' => UploadedFile::fake()->create('intro.mp4', 2048, 'video/mp4'),
            'poster' => UploadedFile::fake()->image('poster.jpg', 1280, 720),
            'overlay' => 40,
            'video_enabled' => '1',
            'headline' => 'Homes for every agency',
            'lede' => '',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.overlay', 40)
            ->assertJsonPath('data.headline', 'Homes for every agency')
            ->assertJsonPath('data.lede', null)
            ->assertJsonPath('data.is_default_video', false)
            ->assertJsonPath('data.updated_by', $admin->name);

        $this->assertStringContainsString('/storage/platform/splash/', $response->json('data.video_url'));
        $this->assertCount(2, Storage::disk('public')->files('platform/splash'));

        $this->getJson('/api/app-config')
            ->assertJsonPath('splash.video_url', $response->json('data.video_url'))
            ->assertJsonPath('splash.video_type', 'video/mp4')
            ->assertJsonPath('splash.headline', 'Homes for every agency');
    }

    public function test_replacing_and_resetting_the_video_removes_old_files(): void
    {
        $admin = $this->superAdmin();
        $upload = fn () => $this->actingAs($admin)->post('/api/platform/splash', [
            'video' => UploadedFile::fake()->create('intro.webm', 1024, 'video/webm'),
        ], ['Accept' => 'application/json'])->assertOk();

        $upload();
        $upload();
        $this->assertCount(1, Storage::disk('public')->files('platform/splash'));

        $this->actingAs($admin)->deleteJson('/api/platform/splash/video')->assertOk()->assertJsonPath('data.video_url', null);
        $this->assertCount(0, Storage::disk('public')->files('platform/splash'));
    }

    public function test_rejects_non_video_files_and_bad_overlay(): void
    {
        $this->actingAs($this->superAdmin())->post('/api/platform/splash', [
            'video' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            'overlay' => 120,
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['video', 'overlay']);
    }

    public function test_only_super_admins_can_edit_the_splash(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/platform/splash')->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson('/api/platform/splash', ['overlay' => 10])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/platform/splash', ['overlay' => 10])->assertUnauthorized();
    }
}
