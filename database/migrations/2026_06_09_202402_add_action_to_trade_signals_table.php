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
            $table->string('action')->nullable()->after('symbol');
        });
    }

    public function down(): void
    {
        Schema::table('trade_signals', function (Blueprint $table) {
            $table->dropColumn('action');
        });
    }
};
