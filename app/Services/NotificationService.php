<?php

namespace App\Services;

use App\Mail\NotificationMail;
use App\Models\Agent;
use App\Models\AppNotification;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One place that fans an event out to the right people: an in-app
 * notification (bell) for each recipient in the current agency, plus a
 * branded HTML email (NotificationMail) when rems.notifications.mail is on.
 * Mail failures never break the request that triggered them.
 */
class NotificationService
{
    /** @param  iterable<User>|User|null  $users */
    public static function send(iterable|User|null $users, string $type, string $title, ?string $body = null, ?string $link = null, array $data = []): void
    {
        if (! $users || ! tenant()->check()) {
            return;
        }

        $users = $users instanceof User ? [$users] : $users;
        $seen = [];

        foreach ($users as $user) {
            if (! $user || isset($seen[$user->id]) || $user->id === auth()->id()) {
                continue;
            }
            $seen[$user->id] = true;

            AppNotification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'link' => $link,
                'data' => $data ?: null,
            ]);

            if (config('rems.notifications.mail')) {
                self::mail($user, $type, $title, $body, $link);
            }
        }
    }

    public static function toAgent(?Agent $agent, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        self::send($agent?->user, $type, $title, $body, $link);
    }

    public static function toCustomer(?Customer $customer, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        self::send($customer?->user, $type, $title, $body, $link);
    }

    /** Owners and admins of the current agency. */
    public static function toAdmins(string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        $users = Membership::query()
            ->where('organization_id', tenant()->id())
            ->whereIn('role', ['owner', 'admin'])
            ->where('is_active', true)
            ->with('user')
            ->get()
            ->pluck('user');

        self::send($users, $type, $title, $body, $link);
    }

    private static function mail(User $user, string $type, string $title, ?string $body, ?string $link): void
    {
        try {
            Mail::to($user->email, $user->name)->send(new NotificationMail($user, $type, $title, $body, $link));
        } catch (Throwable $e) {
            Log::warning('Notification email failed', ['user' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
