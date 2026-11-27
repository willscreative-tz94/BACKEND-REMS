<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/** The two primary accounts. Both are super admins (platform owners): they see and run every agency. */
class PlatformSeeder extends Seeder
{
    public const PASSWORD = 'Password123!';

    public const OWNER_EMAIL = 'architectwills@gmail.com';

    public const PARTNER_EMAIL = 'willscreativedesign@gmail.com';

    public function run(): void
    {
        $owner = User::firstOrCreate(['email' => self::OWNER_EMAIL], [
            'name' => 'William Kotta',
            'phone' => '+255655100001',
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ]);
        $owner->forceFill(['platform_role' => 'owner', 'is_active' => true])->save();

        $partner = User::firstOrCreate(['email' => self::PARTNER_EMAIL], [
            'name' => 'Wills Creative Design',
            'phone' => '+255655100002',
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ]);
        $partner->forceFill(['platform_role' => 'owner', 'is_active' => true])->save();
    }
}
