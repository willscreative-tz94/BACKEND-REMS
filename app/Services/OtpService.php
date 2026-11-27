<?php

namespace App\Services;

use App\Mail\OtpCodeMail;
use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Email one-time passcodes for each user.
 *
 * - The browser only ever holds an opaque challenge token; the DB stores a
 *   SHA-256 of it and a bcrypt hash of the code, never either in clear.
 * - Codes are single-use, expire after rems.otp.ttl_minutes and lock after
 *   rems.otp.max_attempts wrong guesses; resends are rate limited.
 * - Issuing a new challenge revokes the user's earlier ones for that purpose.
 */
class OtpService
{
    public static function enabled(): bool
    {
        return (bool) config('rems.otp.enabled');
    }

    /**
     * Start a challenge and email the code.
     *
     * @return array{otp_required: true, challenge: string, purpose: string, email: string, expires_in: int, resend_in: int, length: int}
     */
    public function issue(User $user, string $purpose): array
    {
        $challenge = Str::random(64);
        $code = $this->generateCode();

        $otp = DB::transaction(function () use ($user, $purpose, $challenge, $code) {
            UserOtp::where('user_id', $user->id)->where('purpose', $purpose)->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return UserOtp::create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'challenge_hash' => hash('sha256', $challenge),
                'code_hash' => Hash::make($code),
                'last_sent_at' => now(),
                'expires_at' => now()->addMinutes((int) config('rems.otp.ttl_minutes')),
                'ip_address' => request()?->ip(),
                'user_agent' => Str::limit((string) request()?->userAgent(), 250, ''),
            ]);
        });

        $this->deliver($user, $otp, $code);

        return $this->payload($otp, $challenge);
    }

    /** Send a fresh code for an open challenge (same token, new code). */
    public function resend(string $challenge): array
    {
        $otp = $this->find($challenge);
        $cooldown = (int) config('rems.otp.resend_seconds');
        $wait = $otp->last_sent_at ? $cooldown - (int) $otp->last_sent_at->diffInSeconds(now(), true) : 0;

        if ($wait > 0) {
            throw ValidationException::withMessages(['code' => "Please wait {$wait}s before requesting another code."]);
        }
        if ($otp->sends >= (int) config('rems.otp.max_sends')) {
            throw ValidationException::withMessages(['code' => 'Too many codes requested. Please sign in again.']);
        }

        $code = $this->generateCode();
        $otp->forceFill([
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'sends' => $otp->sends + 1,
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes((int) config('rems.otp.ttl_minutes')),
        ])->save();

        $this->deliver($otp->user, $otp, $code);

        return $this->payload($otp, $challenge);
    }

    /** Check the code; on success the challenge is consumed and its user returned. */
    public function verify(string $challenge, string $code): UserOtp
    {
        $otp = $this->find($challenge);

        // Count the attempt before comparing, so parallel guesses can't share one slot.
        $otp->increment('attempts');

        if (! Hash::check(preg_replace('/\D/', '', $code), $otp->code_hash)) {
            $left = (int) config('rems.otp.max_attempts') - $otp->attempts;
            throw ValidationException::withMessages(['code' => $left > 0
                ? "That code is not correct. {$left} ".Str::plural('attempt', $left).' left.'
                : 'Too many wrong codes. Please sign in again.']);
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        return $otp;
    }

    private function find(string $challenge): UserOtp
    {
        $otp = UserOtp::with('user')->where('challenge_hash', hash('sha256', $challenge))->first();

        if (! $otp || ! $otp->user) {
            throw ValidationException::withMessages(['code' => 'This verification has expired. Please sign in again.']);
        }
        if ($otp->consumed_at) {
            throw ValidationException::withMessages(['code' => 'This code has already been used. Please sign in again.']);
        }
        if ($otp->attempts >= (int) config('rems.otp.max_attempts')) {
            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Please sign in again.']);
        }
        if ($otp->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Request a new one.']);
        }
        if (! $otp->user->is_active) {
            throw ValidationException::withMessages(['code' => 'This account has been deactivated.']);
        }

        return $otp;
    }

    private function generateCode(): string
    {
        $length = max(4, min(10, (int) config('rems.otp.length')));

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    private function deliver(User $user, UserOtp $otp, string $code): void
    {
        if (config('rems.otp.log_codes') && ! app()->isProduction()) {
            Log::info('OTP code', ['email' => $user->email, 'purpose' => $otp->purpose, 'code' => $code]);
        }

        try {
            Mail::to($user->email, $user->name)->send(new OtpCodeMail($user, $code, $otp->purpose, $otp->expires_at, $otp->ip_address));
        } catch (Throwable $e) {
            Log::error('OTP email failed', ['user' => $user->id, 'error' => $e->getMessage()]);

            // Without the email the user can't continue, unless codes are being logged for local work.
            if (! config('rems.otp.log_codes') || app()->isProduction()) {
                throw ValidationException::withMessages(['email' => 'We could not send your verification code. Please try again shortly.']);
            }
        }
    }

    private function payload(UserOtp $otp, string $challenge): array
    {
        return [
            'otp_required' => true,
            'challenge' => $challenge,
            'purpose' => $otp->purpose,
            'email' => self::maskEmail($otp->user->email),
            'expires_in' => max(0, (int) now()->diffInSeconds($otp->expires_at, false)),
            'resend_in' => (int) config('rems.otp.resend_seconds'),
            'length' => (int) config('rems.otp.length'),
        ];
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $shown = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $shown.str_repeat('•', max(2, mb_strlen($local) - mb_strlen($shown))).'@'.$domain;
    }
}
