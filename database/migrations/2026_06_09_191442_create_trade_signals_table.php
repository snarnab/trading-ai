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
        Schema::create('trade_signals', function (Blueprint $table) {
            $table->id();

            $table->string('symbol');
            $table->string('bias')->nullable();

            $table->double('entry')->nullable();
            $table->double('sl')->nullable();
            $table->double('tp1')->nullable();
            $table->double('tp2')->nullable();

            $table->string('grade')->nullable();

            $table->longText('analysis');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_signals');
    }
};
