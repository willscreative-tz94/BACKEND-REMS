<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Platform first (the REMS owner accounts), then each agency inside its
     * own TenantContext. Re-runnable: existing users keep their passwords and
     * an agency is only built when its slug does not exist yet.
     *
     * Every seeded account signs in with Password123!
     */
    public function run(): void
    {
        $this->call([
            PlatformSeeder::class,
            AgencySeeder::class,
        ]);
    }
}
