<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Agent/broker profiles and buyer/customer (CRM) records. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->enum('type', ['agent', 'broker'])->default('agent');
            $table->string('license_number')->nullable();
            $table->date('license_expiry')->nullable();
            $table->string('specialization')->nullable();
            $table->text('bio')->nullable();
            $table->json('languages')->nullable();
            $table->json('service_areas')->nullable();
            $table->decimal('commission_rate', 5, 2)->default(3.00);
            $table->decimal('broker_split', 5, 2)->default(30.00)->comment('% of agent commission that goes to the broker');
            $table->enum('verification_status', ['pending', 'verified', 'rejected', 'suspended'])->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('national_id')->nullable();
            $table->enum('type', ['buyer', 'tenant', 'investor', 'seller', 'landlord'])->default('buyer');
            $table->enum('status', ['lead', 'active', 'negotiating', 'closed', 'lost'])->default('lead');
            $table->string('source')->nullable()->comment('website, referral, walk-in, social, portal');
            $table->decimal('budget_min', 15, 2)->nullable();
            $table->decimal('budget_max', 15, 2)->nullable();
            $table->string('preferred_type')->nullable();
            $table->string('preferred_locations')->nullable();
            $table->unsignedTinyInteger('preferred_bedrooms')->nullable();
            $table->enum('kyc_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('agents');
    }
};
