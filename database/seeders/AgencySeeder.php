<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AppNotification;
use App\Models\BrandSetting;
use App\Models\Commission;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyFavorite;
use App\Models\PropertyMedia;
use App\Models\Sale;
use App\Models\User;
use App\Models\Viewing;
use App\Services\AuditLogger;
use App\Services\CommissionService;
use App\Services\ContractTemplates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Two isolated agencies with their own brand, staff, customers and
 * listings. Both primary accounts belong to both agencies (with different
 * roles), so the agency switcher and per-tenant branding can be tried
 * straight away.
 */
class AgencySeeder extends Seeder
{
    /** Hot-linked listing photos (Unsplash). The UI falls back to a branded placeholder if one fails. */
    private const PHOTOS = [
        'house' => ['photo-1600596542815-ffad4c1539a9', 'photo-1600585154340-be6161a56a0c', 'photo-1568605114967-8130f3a36994', 'photo-1570129477492-45c003edd2be', 'photo-1564013799919-ab600027ffc6', 'photo-1580587771525-78b9dba3b914', 'photo-1512917774080-9991f1c4c750', 'photo-1582268611958-ebfd161ef9cf'],
        'villa' => ['photo-1613490493576-7fde63acd811', 'photo-1512917774080-9991f1c4c750', 'photo-1600596542815-ffad4c1539a9', 'photo-1580587771525-78b9dba3b914'],
        'apartment' => ['photo-1545324418-cc1a3fa10c00', 'photo-1502672260266-1c1ef2d93688', 'photo-1522708323590-d24dbb6b0267', 'photo-1560448204-e02f11c3d0e2'],
        'townhouse' => ['photo-1570129477492-45c003edd2be', 'photo-1564013799919-ab600027ffc6', 'photo-1568605114967-8130f3a36994'],
        'office' => ['photo-1486406146926-c627a92ad1ab', 'photo-1497366216548-37526070297c'],
        'commercial' => ['photo-1486406146926-c627a92ad1ab', 'photo-1497366216548-37526070297c'],
        'warehouse' => ['photo-1486406146926-c627a92ad1ab'],
        'land' => ['photo-1500382017468-9049fed747ef'],
        'interior' => ['photo-1586023492125-27b2c045efd7', 'photo-1600607687939-ce8a6c25118c', 'photo-1600566753190-17f0baa2a6c3', 'photo-1502672260266-1c1ef2d93688'],
    ];

    private const AGENCIES = [
        [
            'name' => 'MMConnect Properties', 'slug' => 'mmconnect-properties', 'city' => 'Dar es Salaam',
            'email' => 'hello@mmconnectproperties.co.tz', 'phone' => '+255222600100', 'address' => 'Plot 12, Haile Selassie Road, Masaki',
            'license' => 'TRA-REA-2024-0117', 'owner' => PlatformSeeder::OWNER_EMAIL, 'admin' => PlatformSeeder::PARTNER_EMAIL,
            'brand' => ['display_name' => 'MMConnect Properties', 'tagline' => 'Find, view and own your next home — all in one place.', 'logo_path' => '/willsrems-logo.png',
                'light_primary_hex' => '#1e69d2', 'light_accent_hex' => '#e56c5d', 'dark_primary_hex' => '#5f96e3', 'dark_accent_hex' => '#ef9a8f'],
            'cities' => ['Dar es Salaam', 'Dar es Salaam', 'Dar es Salaam', 'Zanzibar', 'Dodoma', 'Mwanza'],
            'domain' => 'mmconnectproperties.co.tz',
        ],
        [
            'name' => 'Kilimanjaro Homes', 'slug' => 'kilimanjaro-homes', 'city' => 'Arusha',
            'email' => 'karibu@kilimanjarohomes.co.tz', 'phone' => '+255272500200', 'address' => 'Njiro Road, Block C',
            'license' => 'TRA-REA-2023-0342', 'owner' => PlatformSeeder::PARTNER_EMAIL, 'admin' => PlatformSeeder::OWNER_EMAIL,
            'brand' => ['display_name' => 'Kilimanjaro Homes', 'tagline' => 'Homes, land and safari lodges across the northern circuit.', 'logo_path' => null,
                'light_primary_hex' => '#0f766e', 'light_accent_hex' => '#d97706', 'dark_primary_hex' => '#2dd4bf', 'dark_accent_hex' => '#fbbf24'],
            'cities' => ['Arusha', 'Arusha', 'Moshi', 'Arusha', 'Zanzibar'],
            'domain' => 'kilimanjarohomes.co.tz',
        ],
    ];

    /** [neighbourhood, city, region, lat, lng] */
    private const LOCATIONS = [
        'Dar es Salaam' => [['Masaki', 'Dar es Salaam', 'Kinondoni', -6.7465, 39.2810], ['Oyster Bay', 'Dar es Salaam', 'Kinondoni', -6.7700, 39.2870], ['Mikocheni', 'Dar es Salaam', 'Kinondoni', -6.7600, 39.2490], ['Mbezi Beach', 'Dar es Salaam', 'Kinondoni', -6.7180, 39.2210], ['Kigamboni', 'Dar es Salaam', 'Kigamboni', -6.8530, 39.3110], ['Upanga', 'Dar es Salaam', 'Ilala', -6.8050, 39.2830], ['Kariakoo', 'Dar es Salaam', 'Ilala', -6.8190, 39.2730], ['Mbweni', 'Dar es Salaam', 'Kinondoni', -6.6430, 39.1530]],
        'Zanzibar' => [['Stone Town', 'Zanzibar', 'Mjini Magharibi', -6.1630, 39.1890], ['Nungwi', 'Zanzibar', 'Unguja North', -5.7260, 39.2980], ['Paje', 'Zanzibar', 'Unguja South', -6.2650, 39.5350], ['Fumba', 'Zanzibar', 'Mjini Magharibi', -6.3070, 39.2950]],
        'Dodoma' => [['Area D', 'Dodoma', 'Dodoma', -6.1730, 35.7520], ['Kisasa', 'Dodoma', 'Dodoma', -6.1890, 35.7860]],
        'Mwanza' => [['Capri Point', 'Mwanza', 'Mwanza', -2.5260, 32.8990], ['Isamilo', 'Mwanza', 'Mwanza', -2.5080, 32.9110]],
        'Arusha' => [['Njiro', 'Arusha', 'Arusha', -3.4010, 36.7130], ['Sakina', 'Arusha', 'Arusha', -3.3640, 36.6560], ['Usa River', 'Arusha', 'Arumeru', -3.3670, 36.8520], ['Themi', 'Arusha', 'Arusha', -3.3830, 36.6970], ['Kisongo', 'Arusha', 'Arumeru', -3.3700, 36.6000]],
        'Moshi' => [['Shanty Town', 'Moshi', 'Kilimanjaro', -3.3300, 37.3330], ['Rau', 'Moshi', 'Kilimanjaro', -3.3420, 37.3500]],
    ];

    private const FIRST = ['Amina', 'Baraka', 'Neema', 'Juma', 'Rehema', 'Hamisi', 'Zawadi', 'Emmanuel', 'Fatma', 'Godfrey', 'Mwanaisha', 'Joseph', 'Upendo', 'Salim', 'Grace', 'Abdallah', 'Esther', 'Daudi', 'Halima', 'Peter', 'Saida', 'Elia', 'Mariam', 'Frank', 'Asha', 'Kelvin', 'Winfrida', 'Rashid'];

    private const LAST = ['Mushi', 'Mollel', 'Kimaro', 'Mwakyusa', 'Njau', 'Massawe', 'Said', 'Lyimo', 'Mrema', 'Shayo', 'Mbwambo', 'Kweka', 'Mfinanga', 'Ngowi', 'Swai', 'Temba', 'Urio', 'Kileo', 'Makundi', 'Hassan'];

    private int $n = 0;

    public function run(): void
    {
        // Unguarded so back-dated created_at values survive #[Fillable] (realistic charts).
        Model::unguarded(function () {
            foreach (self::AGENCIES as $i => $spec) {
                if (Organization::where('slug', $spec['slug'])->exists()) {
                    continue;
                }
                mt_srand(4200 + $i);
                $this->seedAgency($spec, $i);
            }
        });
    }

    private function seedAgency(array $spec, int $index): void
    {
        $owner = User::where('email', $spec['owner'])->firstOrFail();
        $admin = User::where('email', $spec['admin'])->firstOrFail();

        $org = Organization::create([
            'name' => $spec['name'], 'slug' => $spec['slug'], 'email' => $spec['email'], 'phone' => $spec['phone'],
            'address' => $spec['address'], 'city' => $spec['city'], 'country' => 'Tanzania', 'currency' => 'TZS',
            'timezone' => 'Africa/Dar_es_Salaam', 'license_number' => $spec['license'], 'status' => 'active',
            'verified_at' => now()->subMonths(8), 'default_commission_rate' => 3.00, 'owner_id' => $owner->id,
        ]);
        BrandSetting::create(['organization_id' => $org->id, ...$spec['brand'], 'reply_to_email' => $spec['email']]);

        tenant()->run($org, function () use ($org, $spec, $owner, $admin, $index) {
            $this->member($owner, 'owner', now()->subMonths(8));
            $this->member($admin, 'admin', now()->subMonths(7));
            auth()->setUser($owner);

            // ── Brokers & agents ────────────────────────────────────────────
            $brokers = collect(range(1, 2))->map(fn ($k) => $this->agent($spec, 'broker', null, 'verified'));
            $agents = collect([
                $this->agent($spec, 'agent', $brokers[0], 'verified'),
                $this->agent($spec, 'agent', $brokers[0], 'verified'),
                $this->agent($spec, 'agent', $brokers[1], 'verified'),
                $this->agent($spec, 'agent', $brokers[1], 'verified'),
                $this->agent($spec, 'agent', $brokers[0], 'pending'),
            ]);
            $allAgents = $brokers->concat($agents);
            $sellers = $allAgents->filter(fn ($a) => $a->verification_status === 'verified')->values();

            // ── Customers (some with portal accounts) ────────────────────────
            $customers = collect(range(1, 16))->map(function ($k) use ($sellers, $spec, $index) {
                $name = $this->name();
                $withAccount = $k <= 5;
                $email = $withAccount && $k === 1 && $index === 0 ? 'customer@rems.test' : Str::slug($name, '.').'@example.com';
                $user = null;
                if ($withAccount) {
                    $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'phone' => $this->phone(), 'password' => PlatformSeeder::PASSWORD]);
                    $name = $user->name;
                    $this->member($user, 'customer', now()->subDays(mt_rand(20, 200)));
                }
                $type = ['buyer', 'buyer', 'tenant', 'investor', 'buyer', 'tenant', 'seller', 'landlord'][$k % 8];
                $budget = $type === 'tenant' ? [mt_rand(8, 20) * 100000, mt_rand(25, 60) * 100000] : [mt_rand(15, 60) * 10000000, mt_rand(70, 250) * 10000000];

                return Customer::create([
                    'user_id' => $user?->id, 'assigned_agent_id' => $sellers[$k % $sellers->count()]->id, 'name' => $name, 'email' => $user?->email ?? $email,
                    'phone' => $user?->phone ?? $this->phone(), 'national_id' => (string) mt_rand(19700101, 20001231).'-'.mt_rand(10000, 99999).'-'.mt_rand(10000, 99999).'-'.mt_rand(10, 99),
                    'type' => $type, 'status' => ['lead', 'active', 'active', 'negotiating', 'lead', 'active'][$k % 6], 'source' => ['website', 'referral', 'walk-in', 'social', 'portal'][$k % 5],
                    'budget_min' => $budget[0], 'budget_max' => $budget[1], 'preferred_type' => ['house', 'apartment', 'villa', 'land'][$k % 4],
                    'preferred_locations' => collect(self::LOCATIONS[$spec['cities'][$k % count($spec['cities'])]])->random(2)->pluck(0)->implode(', '),
                    'preferred_bedrooms' => mt_rand(1, 5), 'kyc_status' => $k % 4 === 0 ? 'verified' : 'pending',
                    'notes' => $k % 3 === 0 ? 'Prefers properties close to international schools. Cash buyer.' : null,
                    'created_at' => now()->subDays(mt_rand(5, 240)),
                ]);
            });

            // ── Listings ────────────────────────────────────────────────────
            $properties = collect();
            $count = $index === 0 ? 26 : 16;
            for ($k = 0; $k < $count; $k++) {
                $properties->push($this->property($spec, $sellers[$k % $sellers->count()], $k, $customers));
            }

            $listed = $properties->whereIn('status', ['available', 'under_offer'])->values();

            // ── Favourites for portal customers ──────────────────────────────
            $customers->filter(fn ($c) => $c->user_id)->each(function ($c) use ($listed) {
                $listed->random(min(3, $listed->count()))->each(fn ($p) => PropertyFavorite::firstOrCreate(['user_id' => $c->user_id, 'property_id' => $p->id]));
            });

            // ── Viewings: past (completed/no-show) and upcoming ──────────────
            foreach (range(1, 22) as $k) {
                $p = $listed->random();
                $c = $customers->random();
                $past = $k <= 12;
                $at = $past ? now()->subDays(mt_rand(1, 60))->setTime(mt_rand(9, 16), [0, 30][mt_rand(0, 1)]) : now()->addDays(mt_rand(1, 21))->setTime(mt_rand(9, 16), [0, 30][mt_rand(0, 1)]);
                Viewing::create([
                    'property_id' => $p->id, 'customer_id' => $c->id, 'agent_id' => $p->agent_id, 'scheduled_at' => $at,
                    'duration_minutes' => [30, 45, 60][mt_rand(0, 2)], 'type' => $k % 5 === 0 ? 'virtual' : 'in_person',
                    'status' => $past ? ($k % 6 === 0 ? 'no_show' : 'completed') : ($k % 3 === 0 ? 'requested' : 'confirmed'),
                    'customer_notes' => $k % 4 === 0 ? 'Would like to see the garden and parking.' : null,
                    'feedback' => $past && $k % 6 !== 0 ? ['Loved the layout, considering an offer.', 'Too far from town for us.', 'Great finish, price slightly high.', 'Will bring family for a second visit.'][$k % 4] : null,
                    'interest_level' => $past && $k % 6 !== 0 ? mt_rand(2, 5) : null,
                ]);
            }

            // ── Deals across the pipeline, with payments, contracts, commissions ──
            $soldTargets = $properties->whereIn('status', ['sold', 'rented'])->values();
            $offerTargets = $properties->where('status', 'under_offer')->values();
            $available = $properties->where('status', 'available')->values();

            foreach ($soldTargets as $k => $p) {
                $this->deal($p, $customers[$k % $customers->count()], 'completed', now()->subDays(mt_rand(10, 300)));
            }
            foreach ($offerTargets as $k => $p) {
                $this->deal($p, $customers[($k + 5) % $customers->count()], $k % 2 ? 'contract' : 'accepted', now()->subDays(mt_rand(3, 30)));
            }
            foreach ($available->take(4) as $k => $p) {
                $this->deal($p, $customers[($k + 9) % $customers->count()], ['offer', 'negotiating', 'offer', 'rejected'][$k], now()->subDays(mt_rand(1, 14)));
            }
            // A deal for the demo customer so their portal shows the full journey.
            if ($index === 0 && ($demo = $customers->firstWhere('email', 'customer@rems.test')) && $available->count() > 5) {
                $this->deal($available[5], $demo, 'accepted', now()->subDays(6), withSentContract: true);
            }

            // ── Conversations ─────────────────────────────────────────────────
            foreach ($customers->take(8) as $k => $c) {
                $p = $listed[$k % $listed->count()];
                $agentUser = $p->agent?->user;
                $conv = Conversation::create(['property_id' => $p->id, 'customer_id' => $c->id, 'agent_id' => $p->agent_id, 'subject' => "About {$p->title}", 'last_message_at' => now()->subHours(mt_rand(1, 200))]);
                $thread = [
                    [$c->user_id, "Hello, is {$p->title} still available? Could I arrange a viewing this week?"],
                    [$agentUser?->id, "Karibu {$c->name}! Yes, it is available. I can show you on Thursday at 10:00 or Saturday at 14:00."],
                    [$c->user_id, 'Saturday works. Is the price negotiable?'],
                    [$agentUser?->id, 'There is some room for a serious buyer. Let us discuss after the viewing.'],
                ];
                foreach (array_slice($thread, 0, mt_rand(2, 4)) as $j => [$sender, $body]) {
                    Message::create(['conversation_id' => $conv->id, 'sender_id' => $sender, 'body' => $body, 'read_at' => $j < 2 ? now() : null,
                        'created_at' => now()->subHours(mt_rand(1, 200))->addMinutes($j * 20)]);
                }
            }

            // ── Documents (small generated PDFs) awaiting verification ───────
            foreach ($listed->take(5) as $k => $p) {
                $this->document($p, $k % 2 ? 'survey_plan' : 'title_deed', "{$p->reference} ".($k % 2 ? 'survey plan' : 'title deed'), $k < 2 ? 'verified' : 'pending', $p->agent?->user_id);
            }
            foreach ($customers->take(4) as $k => $c) {
                $this->document($c, 'id_document', "{$c->name} — National ID", $k === 0 ? 'verified' : 'pending', $c->user_id ?? $owner->id);
            }
            $this->document($agents->last(), 'agent_license', 'Agent practising licence', 'pending', $agents->last()->user_id);

            // ── A few notifications so the bell isn't empty ──────────────────
            foreach ([$owner, $admin] as $u) {
                foreach ([
                    ['verification.requested', 'Listings awaiting verification', 'Review new listings in Admin › Verifications.', '/admin/verifications'],
                    ['viewing.requested', 'New viewing requests', 'Customers have requested property visits.', '/viewings'],
                    ['sale.offer', 'New offer received', 'A customer made an offer on one of your listings.', '/sales'],
                ] as [$type, $title, $body, $link]) {
                    AppNotification::create(['user_id' => $u->id, 'type' => $type, 'title' => $title, 'body' => $body, 'link' => $link, 'created_at' => now()->subMinutes(mt_rand(5, 600))]);
                }
            }

            AuditLogger::log('organization.seeded', $org, "Demo data created for {$org->name}");
            auth()->forgetUser();
        });
    }

    private function member(User $user, string $role, Carbon $joined): Membership
    {
        return Membership::updateOrCreate(
            ['organization_id' => tenant()->id(), 'user_id' => $user->id],
            ['role' => $role, 'is_active' => true, 'joined_at' => $joined, 'last_switched_at' => $role === 'owner' ? now() : null],
        );
    }

    private function agent(array $spec, string $type, ?Agent $broker, string $status): Agent
    {
        $name = $this->name();
        $user = User::firstOrCreate(['email' => Str::slug($name, '.').'@'.$spec['domain']], ['name' => $name, 'phone' => $this->phone(), 'password' => PlatformSeeder::PASSWORD]);
        $this->member($user, $type, now()->subMonths(mt_rand(1, 7)));

        return Agent::create([
            'user_id' => $user->id, 'broker_id' => $broker?->id, 'type' => $type,
            'license_number' => 'REA/'.mt_rand(2019, 2025).'/'.str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'license_expiry' => now()->addMonths(mt_rand(3, 30))->toDateString(),
            'specialization' => ['Luxury residential', 'Beachfront & holiday homes', 'Commercial & offices', 'Land & plots', 'Rentals & property management'][mt_rand(0, 4)],
            'bio' => "{$name} has helped families and investors across {$spec['city']} find the right property for over ".mt_rand(3, 15).' years. Fluent in English and Swahili.',
            'languages' => ['English', 'Swahili'], 'service_areas' => collect(self::LOCATIONS[$spec['city']])->pluck(0)->take(3)->all(),
            'commission_rate' => $type === 'broker' ? 3.50 : 3.00, 'broker_split' => 30,
            'verification_status' => $status, 'verified_at' => $status === 'verified' ? now()->subMonths(mt_rand(1, 6)) : null,
            'rating' => $status === 'verified' ? mt_rand(38, 50) / 10 : 0,
        ]);
    }

    private function property(array $spec, Agent $agent, int $k, $customers): Property
    {
        $city = $spec['cities'][$k % count($spec['cities'])];
        [$hood, $cityName, $region, $lat, $lng] = self::LOCATIONS[$city][$k % count(self::LOCATIONS[$city])];
        $type = ['house', 'apartment', 'villa', 'apartment', 'townhouse', 'house', 'land', 'office', 'apartment', 'villa', 'commercial', 'house', 'warehouse'][$k % 13];
        $rent = in_array($k % 5, [1, 3], true) && $type !== 'land';
        $beds = in_array($type, ['land', 'office', 'commercial', 'warehouse'], true) ? null : mt_rand($type === 'apartment' ? 1 : 3, $type === 'villa' ? 6 : 4);
        $area = match ($type) { 'land' => null, 'apartment' => mt_rand(65, 220), 'office', 'commercial' => mt_rand(120, 900), 'warehouse' => mt_rand(800, 3000), default => mt_rand(180, 650) };

        $price = $rent
            ? match ($type) { 'apartment' => mt_rand(12, 45) * 100000, 'office', 'commercial', 'warehouse' => mt_rand(30, 150) * 100000, default => mt_rand(25, 90) * 100000 }
            : match ($type) { 'land' => mt_rand(4, 60) * 10000000, 'apartment' => mt_rand(18, 75) * 10000000, 'villa' => mt_rand(90, 450) * 10000000, 'office', 'commercial', 'warehouse' => mt_rand(80, 600) * 10000000, default => mt_rand(35, 180) * 10000000 };

        $label = ['house' => 'family home', 'apartment' => 'apartment', 'villa' => 'villa', 'townhouse' => 'townhouse', 'land' => 'plot', 'office' => 'office space', 'commercial' => 'commercial building', 'warehouse' => 'warehouse'][$type];
        $adjective = ['Modern', 'Spacious', 'Elegant', 'Sea-view', 'Contemporary', 'Charming', 'Prime', 'Newly built', 'Furnished', 'Executive'][$k % 10];
        $title = $beds ? "{$adjective} {$beds}-bedroom {$label} in {$hood}" : ($type === 'land' ? "Prime ".mt_rand(5, 40) * 100 ." m² {$label} in {$hood}" : "{$adjective} {$label} in {$hood}");

        $status = match (true) {
            $k === 2, $k === 9, $k === 15 => 'sold',
            $k === 6 && $rent, $k === 13 => $rent ? 'rented' : 'sold',
            $k === 4, $k === 11 => 'under_offer',
            $k === 20 => 'draft',
            $k === 22 => 'off_market',
            default => 'available',
        };
        $verification = match (true) { $status === 'draft' => 'unverified', $k % 7 === 5 => 'pending', default => 'verified' };

        $features = collect(['Borehole', 'Standby generator', 'Solar water heating', 'Swimming pool', 'Garden', 'Staff quarters', 'CCTV & electric fence', 'Air conditioning', 'Fitted kitchen', 'Ocean view', 'Gym', 'Lift', '24h security', 'Title deed ready'])->shuffle()->take(mt_rand(3, 7))->values()->all();

        $property = Property::create([
            'agent_id' => $agent->id, 'owner_customer_id' => $customers->firstWhere('type', $rent ? 'landlord' : 'seller')?->id,
            'title' => $title, 'property_type' => $type, 'listing_type' => $rent ? 'rent' : 'sale', 'status' => $status,
            'description' => "Welcome to this {$adjective} {$label} in the heart of {$hood}, {$cityName}. ".
                ($beds ? "It offers {$beds} bedrooms, generous living areas filled with natural light and a modern kitchen. " : 'The site is level, well-drained and ready for development. ').
                'Close to schools, shopping, hospitals and main roads, with reliable water and power. Ideal for '.($rent ? 'professionals and families looking for a secure, well-managed rental.' : 'owner-occupiers and investors alike.'),
            'price' => $price, 'currency' => 'TZS', 'price_period' => $rent ? 'month' : 'once',
            'bedrooms' => $beds, 'bathrooms' => $beds ? max(1, $beds - mt_rand(0, 1)) : null, 'parking' => $type === 'land' ? null : mt_rand(1, 4),
            'area_sqm' => $area, 'plot_size_sqm' => in_array($type, ['house', 'villa', 'land', 'townhouse'], true) ? mt_rand(4, 40) * 100 : null,
            'year_built' => $type === 'land' ? null : mt_rand(2005, 2025), 'furnished' => $rent && $k % 2 === 0,
            'address' => 'Plot '.mt_rand(10, 999).', '.$hood, 'neighborhood' => $hood, 'city' => $cityName, 'region' => $region, 'country' => 'Tanzania',
            'latitude' => round($lat + (mt_rand(-150, 150) / 10000), 7), 'longitude' => round($lng + (mt_rand(-150, 150) / 10000), 7),
            'features' => $features, 'amenities' => collect(['School nearby', 'Hospital nearby', 'Shopping mall', 'Beach access', 'Public transport', 'Restaurants'])->shuffle()->take(3)->values()->all(),
            'is_featured' => $k % 4 === 0 && $status === 'available', 'verification_status' => $verification,
            'verified_at' => $verification === 'verified' ? now()->subDays(mt_rand(5, 120)) : null,
            'views_count' => mt_rand(12, 640), 'created_at' => now()->subDays(mt_rand(15, 330)),
        ]);

        $photos = self::PHOTOS[$type];
        $set = [$photos[$k % count($photos)], ...array_slice(self::PHOTOS['interior'], $k % 2, 2)];
        if ($type === 'land' || $type === 'warehouse') {
            $set = [$photos[0]];
        }
        foreach ($set as $j => $id) {
            PropertyMedia::create(['property_id' => $property->id, 'type' => 'image', 'url' => "https://images.unsplash.com/{$id}?auto=format&fit=crop&w=1400&q=70",
                'caption' => $j === 0 ? 'Exterior' : ['Living room', 'Kitchen', 'Bedroom'][$j % 3], 'is_cover' => $j === 0, 'sort_order' => $j]);
        }

        return $property;
    }

    private function deal(Property $p, Customer $c, string $stage, Carbon $at, bool $withSentContract = false): void
    {
        $rental = $p->listing_type === 'rent';
        $offer = round((float) $p->price * ($rental ? 1 : mt_rand(90, 99) / 100), -4);
        $agreed = in_array($stage, ['accepted', 'contract', 'completed'], true) ? $offer : null;
        $deposit = $rental ? $offer * 2 : round($offer * 0.1, -4);

        $sale = Sale::create([
            'property_id' => $p->id, 'customer_id' => $c->id, 'agent_id' => $p->agent_id, 'type' => $rental ? 'rental' : 'sale',
            'offer_amount' => $offer, 'agreed_price' => $agreed, 'deposit_amount' => $deposit, 'currency' => $p->currency,
            'status' => $stage, 'payment_plan' => $rental ? 'monthly_rent' : ['full', 'installments', 'mortgage'][mt_rand(0, 2)],
            'offer_date' => $at->toDateString(), 'accepted_at' => $agreed ? $at->copy()->addDays(2) : null,
            'closed_at' => in_array($stage, ['completed', 'rejected'], true) ? $at->copy()->addDays(mt_rand(20, 60)) : null,
            'created_at' => $at,
        ]);

        if (in_array($stage, ['accepted', 'contract', 'completed'], true)) {
            $this->payment($sale, $deposit, 'deposit', $at->copy()->addDays(3));
        }
        if ($stage === 'completed') {
            $this->payment($sale, max(0, (float) $agreed - $deposit), $rental ? 'rent' : 'full_payment', $sale->closed_at);
            CommissionService::generateForSale($sale->fresh(['agent.broker', 'agent.user']));
            // Older commissions are already approved/paid.
            if ($sale->closed_at->lt(now()->subDays(45))) {
                Commission::where('sale_id', $sale->id)->update(['status' => 'paid', 'approved_by' => auth()->id(), 'approved_at' => $sale->closed_at->copy()->addDays(5), 'paid_at' => $sale->closed_at->copy()->addDays(12), 'payment_reference' => 'BNK'.mt_rand(100000, 999999)]);
            } elseif ($sale->closed_at->lt(now()->subDays(20))) {
                Commission::where('sale_id', $sale->id)->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => $sale->closed_at->copy()->addDays(5)]);
            }
        }

        if (in_array($stage, ['contract', 'completed'], true) || $withSentContract) {
            $type = $rental ? 'lease_agreement' : 'sale_agreement';
            $tpl = ContractTemplates::render($type, $p, $c, $sale->load('agent.user'));
            $signed = $stage !== 'accepted';
            Contract::create([
                'sale_id' => $sale->id, 'property_id' => $p->id, 'customer_id' => $c->id, 'agent_id' => $p->agent_id, 'type' => $type,
                'title' => $tpl['title'], 'body' => $tpl['body'], 'value' => $agreed ?? $offer, 'currency' => $p->currency,
                'status' => $signed ? ($rental && $stage === 'completed' ? 'active' : 'signed') : 'sent',
                'start_date' => $rental ? $at->copy()->addDays(7)->toDateString() : null, 'end_date' => $rental ? $at->copy()->addDays(7)->addYear()->toDateString() : null,
                'customer_signature' => $signed ? $c->name : null, 'customer_signed_at' => $signed ? $at->copy()->addDays(5) : null, 'customer_signed_ip' => $signed ? '197.250.'.mt_rand(1, 254).'.'.mt_rand(1, 254) : null,
                'agency_signature' => $signed ? tenant()->organization()->owner?->name : null, 'agency_signed_at' => $signed ? $at->copy()->addDays(5) : null,
                'sent_at' => $at->copy()->addDays(4),
            ]);
        }
    }

    private function payment(Sale $sale, float $amount, string $purpose, Carbon $at): void
    {
        if ($amount <= 0) {
            return;
        }
        $method = ['mobile_money', 'bank_transfer', 'bank_transfer', 'card'][mt_rand(0, 3)];
        Payment::create([
            'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'reference' => 'PAY-'.$at->format('ymd').'-'.Str::upper(Str::random(6)),
            'amount' => $amount, 'currency' => $sale->currency, 'method' => $method, 'purpose' => $purpose, 'status' => 'completed',
            'provider' => $method === 'mobile_money' ? 'mpesa' : 'manual', 'provider_reference' => $method === 'mobile_money' ? 'Q'.Str::upper(Str::random(9)) : 'BNK'.mt_rand(100000, 999999),
            'payer_phone' => $method === 'mobile_money' ? $sale->customer?->phone : null, 'paid_at' => $at, 'recorded_by' => auth()->id(), 'created_at' => $at,
        ]);
    }

    private function document($owner, string $category, string $name, string $status, ?int $uploader): void
    {
        $path = 'documents/'.tenant()->id().'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $this->tinyPdf($name));
        Document::create([
            'documentable_type' => $owner::class, 'documentable_id' => $owner->id, 'uploaded_by' => $uploader, 'name' => $name,
            'category' => $category, 'path' => $path, 'mime' => 'application/pdf', 'size' => Storage::disk('local')->size($path),
            'verification_status' => $status, 'verified_by' => $status === 'verified' ? auth()->id() : null, 'verified_at' => $status === 'verified' ? now()->subDays(3) : null,
        ]);
    }

    /** A valid one-page PDF containing $text (no dependencies). */
    private function tinyPdf(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text.' — willsrems demo document');
        $stream = "BT /F1 18 Tf 60 760 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$obj}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref\n0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        $pdf = str_replace('xref\n', "xref\n", $pdf);
        foreach ($offsets as $o) {
            $pdf .= str_pad((string) $o, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    /** @var array<string, true> names already handed out (across both agencies) */
    private array $usedNames = [];

    private function name(): string
    {
        do {
            $this->n++;
            $name = self::FIRST[($this->n * 7 + mt_rand(0, 3)) % count(self::FIRST)].' '.self::LAST[($this->n * 3 + mt_rand(0, 5)) % count(self::LAST)];
        } while (isset($this->usedNames[$name]) || User::where('name', $name)->exists());
        $this->usedNames[$name] = true;

        return $name;
    }

    private function phone(): string
    {
        return '+2557'.mt_rand(10, 79).mt_rand(100000, 999999);
    }
}
