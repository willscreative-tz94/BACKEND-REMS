<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Listings, their photos/videos, and customers' saved favourites. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('owner_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('reference', 32);
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->enum('property_type', ['house', 'apartment', 'villa', 'townhouse', 'land', 'commercial', 'office', 'warehouse'])->default('house');
            $table->enum('listing_type', ['sale', 'rent'])->default('sale');
            $table->enum('status', ['draft', 'available', 'under_offer', 'sold', 'rented', 'off_market'])->default('draft');
            $table->decimal('price', 15, 2);
            $table->string('currency', 3)->default('TZS');
            $table->enum('price_period', ['once', 'month', 'year'])->default('once');
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedTinyInteger('parking')->nullable();
            $table->decimal('area_sqm', 10, 2)->nullable();
            $table->decimal('plot_size_sqm', 12, 2)->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();
            $table->boolean('furnished')->default(false);
            $table->string('address')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('country', 64)->default('Tanzania');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('features')->nullable();
            $table->json('amenities')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->enum('verification_status', ['unverified', 'pending', 'verified', 'rejected'])->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'reference']);
            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status', 'listing_type']);
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('property_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['image', 'video', 'virtual_tour', 'floor_plan'])->default('image');
            $table->string('path')->nullable()->comment('Uploaded file on the public disk');
            $table->string('url')->nullable()->comment('External media (YouTube, Vimeo, Matterport)');
            $table->string('caption')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('is_cover')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('property_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_favorites');
        Schema::dropIfExists('property_media');
        Schema::dropIfExists('properties');
    }
};
