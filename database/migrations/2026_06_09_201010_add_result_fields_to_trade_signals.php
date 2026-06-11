<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::table('trade_signals', function (Blueprint $table) {
        $table->string('direction')->nullable();
        $table->string('result')->default('RUNNING'); // RUNNING/WIN/LOSS
        $table->decimal('entry_price', 12, 3)->nullable();
        $table->decimal('sl_price', 12, 3)->nullable();
        $table->decimal('tp1_price', 12, 3)->nullable();
        $table->decimal('tp2_price', 12, 3)->nullable();
        $table->timestamp('closed_at')->nullable();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_signals', function (Blueprint $table) {
            //
        });
    }
};
