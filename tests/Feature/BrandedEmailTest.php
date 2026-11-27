<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Mail\OtpCodeMail;
use App\Models\BrandSetting;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BrandedEmailTest extends TestCase
{
    use RefreshDatabase;

    private function agency(): Organization
    {
        $org = Organization::create(['name' => 'Kilimanjaro Homes', 'slug' => 'kili', 'status' => 'active', 'email' => 'hello@kili.test']);
        BrandSetting::create(['organization_id' => $org->id, 'display_name' => 'Kilimanjaro Homes', 'tagline' => 'Homes with a view', 'light_primary_hex' => '#0f766e', 'light_accent_hex' => '#f59e0b']);

        return $org;
    }

    public function test_otp_email_is_branded_and_shows_the_code(): void
    {
        $user = User::factory()->create(['name' => 'Jane Doe']);
        $mail = tenant()->run($this->agency(), fn () => new OtpCodeMail($user, '482913', 'login', now()->addMinutes(10), '10.0.0.1'));

        $mail->assertHasSubject('482913 is your Kilimanjaro Homes verification code');
        $mail->assertSeeInHtml('482913');
        $mail->assertSeeInHtml('#0f766e');
        $mail->assertSeeInHtml('Never share this code');
        $mail->assertSeeInHtml('10.0.0.1');
        $mail->assertSeeInText('482913');
        $mail->assertSeeInText('Hi Jane Doe');
    }

    public function test_notification_email_has_category_button_and_reply_to(): void
    {
        config(['app.frontend_url' => 'https://app.rems.test']);
        $user = User::factory()->create(['name' => 'Sam Agent']);
        $mail = tenant()->run($this->agency(), fn () => new NotificationMail($user, 'viewing.requested', 'New viewing request', "Asha wants to view Plot 12.\nSaturday 10:00", '/viewings'));

        $mail->assertHasSubject('[Kilimanjaro Homes] New viewing request');
        $mail->assertHasReplyTo('hello@kili.test');
        $mail->assertSeeInHtml('Viewings');
        $mail->assertSeeInHtml('See the viewing');
        $mail->assertSeeInHtml('https://app.rems.test/viewings', false);
        $mail->assertSeeInHtml('Hi Sam,');
        $mail->assertSeeInHtml('Asha wants to view Plot 12.<br />', false);
        $mail->assertSeeInText('See the viewing: https://app.rems.test/viewings');
    }

    public function test_notification_body_is_escaped(): void
    {
        $user = User::factory()->create();
        $mail = new NotificationMail($user, 'message.received', 'Hi', '<script>alert(1)</script>');

        $mail->assertDontSeeInHtml('<script>alert(1)</script>', false);
        $mail->assertSeeInHtml('&lt;script&gt;', false);
    }

    public function test_notification_service_sends_branded_mail_when_enabled(): void
    {
        Mail::fake();
        config(['rems.notifications.mail' => true]);
        $org = $this->agency();
        $admin = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $admin->id, 'role' => 'admin', 'is_active' => true]);

        tenant()->run($org, fn () => NotificationService::toAdmins('sale.offer', 'New offer', 'An offer came in.', '/sales/1'));

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($admin->email) && $mail->brand->name === 'Kilimanjaro Homes');
    }
}
