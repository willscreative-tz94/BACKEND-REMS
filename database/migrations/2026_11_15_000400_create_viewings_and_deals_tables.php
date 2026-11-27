<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Property visits, sales/rental deals, payments and commissions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(45);
            $table->enum('type', ['in_person', 'virtual'])->default('in_person');
            $table->enum('status', ['requested', 'confirmed', 'completed', 'cancelled', 'no_show'])->default('requested');
            $table->text('customer_notes')->nullable();
            $table->text('agent_notes')->nullable();
            $table->text('feedback')->nullable();
            $table->unsignedTinyInteger('interest_level')->nullable()->comment('1-5 after the visit');
            $table->timestamps();
            $table->index(['organization_id', 'scheduled_at']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 32);
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->enum('type', ['sale', 'rental'])->default('sale');
            $table->decimal('offer_amount', 15, 2);
            $table->decimal('agreed_price', 15, 2)->nullable();
            $table->decimal('deposit_amount', 15, 2)->nullable();
            $table->string('currency', 3)->default('TZS');
            $table->enum('status', ['offer', 'negotiating', 'accepted', 'contract', 'completed', 'cancelled', 'rejected'])->default('offer');
            $table->enum('payment_plan', ['full', 'installments', 'mortgage', 'monthly_rent'])->default('full');
            $table->date('offer_date')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 40)->unique();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('TZS');
            $table->enum('method', ['mobile_money', 'card', 'bank_transfer', 'cash', 'cheque'])->default('mobile_money');
            $table->enum('purpose', ['booking_fee', 'deposit', 'installment', 'full_payment', 'rent', 'other'])->default('deposit');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'refunded'])->default('pending');
            $table->string('provider', 32)->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('payer_phone', 32)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->enum('role', ['selling_agent', 'listing_agent', 'broker_override'])->default('selling_agent');
            $table->decimal('rate', 5, 2);
            $table->decimal('base_amount', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('TZS');
            $table->enum('status', ['pending', 'approved', 'paid', 'cancelled'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('viewings');
    }
};
