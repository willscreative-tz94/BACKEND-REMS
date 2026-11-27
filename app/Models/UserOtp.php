<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pending one-time passcode challenge (see OtpService). Platform-level, not tenant-scoped. */
#[Fillable(['user_id', 'purpose', 'challenge_hash', 'code_hash', 'attempts', 'sends', 'last_sent_at', 'expires_at', 'consumed_at', 'ip_address', 'user_agent'])]
#[Hidden(['challenge_hash', 'code_hash'])]
class UserOtp extends Model
{
    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_VERIFY_EMAIL = 'verify_email';

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sends' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('rems.otp.max_attempts');
    }
}
