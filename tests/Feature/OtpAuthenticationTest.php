<?php

namespace Tests\Feature;

use App\Mail\OtpCodeMail;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['rems.otp.enabled' => true, 'rems.otp.log_codes' => false]);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => 'jane@example.com', 'password' => 'Secret123']);
    }

    private function login(): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => 'jane@example.com', 'password' => 'Secret123']);
    }

    private function sentCode(): string
    {
        $code = null;
        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    public function test_correct_password_returns_an_otp_challenge_instead_of_a_token(): void
    {
        $this->user();

        $response = $this->login()->assertOk()
            ->assertJsonPath('otp_required', true)
            ->assertJsonPath('purpose', 'login')
            ->assertJsonPath('email', 'ja••@example.com')
            ->assertJsonMissingPath('token');

        $this->assertSame(64, strlen($response->json('challenge')));
        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo('jane@example.com') && strlen($mail->code) === 6);

        $otp = UserOtp::sole();
        $this->assertNotSame($response->json('challenge'), $otp->challenge_hash, 'challenge must be stored hashed');
        $this->assertNotSame($this->sentCode(), $otp->code_hash, 'code must be stored hashed');
    }

    public function test_wrong_password_sends_no_code(): void
    {
        $this->user();

        $this->postJson('/api/auth/login', ['email' => 'jane@example.com', 'password' => 'nope'])->assertUnprocessable();

        Mail::assertNothingSent();
        $this->assertSame(0, UserOtp::count());
    }

    public function test_correct_code_issues_a_token_and_cannot_be_reused(): void
    {
        $user = $this->user()->forceFill(['email_verified_at' => null]);
        $user->save();
        $challenge = $this->login()->json('challenge');
        $code = $this->sentCode();

        $this->postJson('/api/auth/otp/verify', ['challenge' => $challenge, 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email'], 'organizations']);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/auth/otp/verify', ['challenge' => $challenge, 'code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_challenge_locks_after_too_many_wrong_codes(): void
    {
        config(['rems.otp.max_attempts' => 3]);
        $this->user();
        $challenge = $this->login()->json('challenge');
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/auth/otp/verify', ['challenge' => $challenge, 'code' => $wrong])->assertUnprocessable();
        }

        $this->postJson('/api/auth/otp/verify', ['challenge' => $challenge, 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Too many wrong codes. Please sign in again.');
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->user();
        $challenge = $this->login()->json('challenge');
        $code = $this->sentCode();

        $this->travel(11)->minutes();

        $this->postJson('/api/auth/otp/verify', ['challenge' => $challenge, 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'This code has expired. Request a new one.');
    }

    public function test_resend_respects_the_cooldown_and_replaces_the_code(): void
    {
        $this->user();
        $challenge = $this->login()->json('challenge');

        $this->postJson('/api/auth/otp/resend', ['challenge' => $challenge])->assertUnprocessable();

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/otp/resend', ['challenge' => $challenge])->assertOk()->assertJsonPath('otp_required', true);
        Mail::assertSent(OtpCodeMail::class, 2);
        $this->assertSame(2, UserOtp::sole()->sends);

        $latest = null;
        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$latest) {
            $latest = $mail->code;

            return true;
        });
        $this->postJson('/api/auth/otp/verify', ['challenge' => $challenge, 'code' => $latest])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_signing_in_again_revokes_the_previous_challenge(): void
    {
        $this->user();
        $old = $this->login()->json('challenge');
        $oldCode = $this->sentCode();
        $this->login();

        $this->postJson('/api/auth/otp/verify', ['challenge' => $old, 'code' => $oldCode])->assertUnprocessable();
    }

    public function test_registration_requires_email_verification_before_a_session(): void
    {
        Organization::create(['name' => 'Acme Homes', 'slug' => 'acme', 'status' => 'active']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'New Buyer', 'email' => 'buyer@example.com', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            'organization' => 'acme',
        ])->assertCreated()->assertJsonPath('otp_required', true)->assertJsonPath('purpose', 'verify_email')->assertJsonMissingPath('token');

        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->brand->name === 'Acme Homes');

        $this->postJson('/api/auth/otp/verify', ['challenge' => $response->json('challenge'), 'code' => $this->sentCode()])
            ->assertOk()
            ->assertJsonPath('default_organization', 'acme');

        $this->assertNotNull(User::where('email', 'buyer@example.com')->value('email_verified_at'));
    }

    public function test_otp_can_be_switched_off(): void
    {
        config(['rems.otp.enabled' => false]);
        $this->user();

        $this->login()->assertOk()->assertJsonStructure(['token']);
        Mail::assertNothingSent();
    }
}
