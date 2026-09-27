<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger of the AI: one row per call to the provider, with what it cost at the prices of
 * that moment. The daily counters in the cache stay the perimeter of the budget; this is the
 * history, the one `cache:clear` cannot wipe and a month or a year can be read from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->string('context', 40)->nullable();     // what the call was for: the caller says (classify, chat, widget...)
            $table->string('provider', 20);
            $table->string('model', 80)->nullable();
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->decimal('cost', 12, 6)->default(0);    // USD, at the prices configured when the call was made
            $table->string('user_id', 64)->nullable();     // who asked; null from a worker or the console
            $table->dateTime('created_at');

            $table->index('created_at');
            $table->index(['context', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
