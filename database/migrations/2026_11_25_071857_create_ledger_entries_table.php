<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every change to a user's balance, so the balance can always be explained and audited.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // purchase | generation
            $table->bigInteger('amount_nano'); // positive adds credits, negative spends them
            // Unique: the database itself refuses to charge one generation twice.
            $table->foreignId('generation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
