<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grant or revoke super admin (platform owner): sees every agency in the
 * Platform Console and switcher, can open, onboard, verify and suspend them.
 *
 *   php artisan rems:super-admin willscreativedesign@gmail.com
 *   php artisan rems:super-admin someone@example.com --name="Jane Doe"   (creates the account)
 *   php artisan rems:super-admin someone@example.com --revoke
 *   php artisan rems:super-admin --list
 */
class SuperAdmin extends Command
{
    protected $signature = 'rems:super-admin {email? : Account email} {--name= : Name when creating a new account} {--password= : Password when creating (default Password123!)} {--revoke : Remove super admin} {--list : Show current super admins}';

    protected $description = 'Grant, revoke or list willsrems super admins (platform owners)';

    public function handle(): int
    {
        if ($this->option('list') || ! $this->argument('email')) {
            $this->table(['Name', 'Email'], User::where('platform_role', 'owner')->orderBy('email')->get()->map(fn (User $u) => [$u->name, $u->email])->all());

            return self::SUCCESS;
        }

        $email = strtolower($this->argument('email'));
        $user = User::where('email', $email)->first();

        if ($this->option('revoke')) {
            if (! $user) {
                $this->error("No account for {$email}.");

                return self::FAILURE;
            }
            if ($user->platform_role === 'owner' && User::where('platform_role', 'owner')->count() === 1) {
                $this->error('Refusing to remove the last super admin.');

                return self::FAILURE;
            }
            $user->forceFill(['platform_role' => null])->save();
            $this->info("{$email} is no longer a super admin.");

            return self::SUCCESS;
        }

        if (! $user) {
            $user = User::create([
                'name' => $this->option('name') ?: ucwords(str_replace(['.', '_', '-'], ' ', strstr($email, '@', true))),
                'email' => $email,
                'password' => $this->option('password') ?: 'Password123!',
                'email_verified_at' => now(),
            ]);
            $this->line("Created account {$email}.");
        }

        $user->forceFill(['platform_role' => 'owner', 'is_active' => true])->save();
        $this->info("{$email} is now a super admin.");

        return self::SUCCESS;
    }
}
