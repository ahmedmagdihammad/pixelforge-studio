<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Sent to Pixelforge so retries of the same submission can never create a second generation.
            $table->uuid('idempotency_key')->unique();
            $table->string('pixelforge_id')->nullable()->unique();

            // submitting (ours, before Pixelforge accepts it) -> queued -> processing -> succeeded | failed
            $table->string('status')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // Money is in nanodollars. Estimates are user charges (with markup).
            $table->bigInteger('estimated_min_charge_nano');
            $table->bigInteger('estimated_max_charge_nano');
            $table->bigInteger('provider_cost_nano')->nullable(); // what Pixelforge charges us
            $table->bigInteger('charge_nano')->nullable();        // what we charge the user
            $table->timestamp('charged_at')->nullable();

            $table->json('usage')->nullable();
            $table->json('rates')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('submit_attempts')->default(0);

            // Pixelforge's own timestamps. completed_at decides which UTC day Pixelforge bills it on.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
