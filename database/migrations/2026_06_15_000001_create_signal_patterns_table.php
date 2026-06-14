<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('system_name');
            $table->string('custom_name')->nullable();
            $table->string('direction', 10);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        $patterns = [
            ['B1', 'Bull Power Breakout', 'BUY'],
            ['B2', 'Bull Engulf Pro', 'BUY'],
            ['B3', 'Liquidity Hammer Buy', 'BUY'],
            ['B4', 'Mini Pullback Entry', 'BUY'],
            ['B5', 'IB Break Buy', 'BUY'],
            ['B6', 'Momentum Buy', 'BUY'],
            ['B7', 'Stop Hunt Buy', 'BUY'],
            ['B8', 'Fib Rejection Buy', 'BUY'],
            ['S1', 'Fib Rejection Sell', 'SELL'],
            ['S2', 'Bear Engulf Pro', 'SELL'],
            ['S3', 'Twin Rejection Sell', 'SELL'],
            ['S4', 'Hanging Man Pro', 'SELL'],
            ['S5', 'Fib Trap Sell', 'SELL'],
            ['S6', 'Structure Break Sell', 'SELL'],
            ['S7', 'Momentum Crush Sell', 'SELL'],
            ['S8', 'Stop Hunt Reversal', 'SELL'],
        ];

        foreach ($patterns as [$code, $name, $direction]) {
            DB::table('signal_patterns')->insert([
                'code' => $code,
                'system_name' => $name,
                'custom_name' => null,
                'direction' => $direction,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_patterns');
    }
};
