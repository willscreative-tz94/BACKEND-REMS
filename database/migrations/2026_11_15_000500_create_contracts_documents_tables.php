<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Contracts (sale/lease agreements, mandates) and supporting documents. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->enum('type', ['sale_agreement', 'lease_agreement', 'listing_mandate', 'offer_letter'])->default('sale_agreement');
            $table->string('title');
            $table->longText('body');
            $table->enum('status', ['draft', 'sent', 'signed', 'active', 'expired', 'terminated'])->default('draft');
            $table->decimal('value', 15, 2)->nullable();
            $table->string('currency', 3)->default('TZS');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('customer_signature')->nullable();
            $table->timestamp('customer_signed_at')->nullable();
            $table->string('customer_signed_ip', 45)->nullable();
            $table->string('agency_signature')->nullable();
            $table->timestamp('agency_signed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'number']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('documentable');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->enum('category', ['title_deed', 'id_document', 'tax_certificate', 'sale_agreement', 'lease_agreement', 'valuation_report', 'survey_plan', 'building_permit', 'receipt', 'agent_license', 'other'])->default('other');
            $table->string('path');
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('is_private')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('contracts');
    }
};
