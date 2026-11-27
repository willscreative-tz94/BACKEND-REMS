<?php

namespace App\Mail;

use App\Models\User;
use App\Models\UserOtp;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The one-time sign-in / email-verification code. Sent immediately (never queued). */
class OtpCodeMail extends Mailable
{
    use Queueable;

    public EmailBrand $brand;

    public function __construct(
        public User $user,
        public string $code,
        public string $purpose,
        public CarbonInterface $expiresAt,
        public ?string $ipAddress = null,
    ) {
        $this->brand = EmailBrand::current();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->code} is your {$this->brand->name} verification code");
    }

    public function content(): Content
    {
        $verifying = $this->purpose === UserOtp::PURPOSE_VERIFY_EMAIL;

        return new Content(
            view: 'emails.otp',
            text: 'emails.otp-text',
            with: [
                'brand' => $this->brand,
                'heading' => $verifying ? 'Confirm your email address' : 'Your sign-in code',
                'intro' => $verifying
                    ? "Welcome to {$this->brand->name}! Enter this code to confirm your email and finish creating your account."
                    : "Use this code to finish signing in to {$this->brand->name}.",
                'minutes' => max(1, (int) round(now()->diffInMinutes($this->expiresAt, true))),
                'requestedAt' => now()->format('j M Y, H:i T'),
                'preheader' => "Your code is {$this->code}. It expires soon — never share it with anyone.",
            ],
        );
    }
}
