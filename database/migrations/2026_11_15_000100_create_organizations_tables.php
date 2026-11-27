<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tenants (agencies), their members and their branding. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 64)->default('Tanzania');
            $table->string('currency', 3)->default('TZS');
            $table->string('timezone', 64)->default('Africa/Dar_es_Salaam');
            $table->string('license_number')->nullable();
            $table->enum('status', ['active', 'pending', 'suspended'])->default('active');
            $table->timestamp('verified_at')->nullable();
            $table->decimal('default_commission_rate', 5, 2)->default(3.00);
            $table->json('settings')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'broker', 'agent', 'customer'])->default('customer');
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_switched_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('brand_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name')->nullable();
            $table->string('tagline')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('light_primary_hex', 7)->default('#1e69d2');
            $table->string('light_accent_hex', 7)->default('#e56c5d');
            $table->string('dark_primary_hex', 7)->default('#5f96e3');
            $table->string('dark_accent_hex', 7)->default('#ef9a8f');
            $table->string('reply_to_email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_settings');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
