<?php

namespace App\Services;

use App\Models\SymbolSetting;
use App\Models\SignalPattern;
use App\Models\AlertTimeframe;

class SettingsService
{
    public function ensureDefaults(): void
    {
        foreach (['XAUUSD','BTCUSD','ETHUSD','EURUSD','GBPUSD','USDJPY','GBPJPY','USOIL.cash'] as $symbol) {
            SymbolSetting::firstOrCreate(
                ['symbol' => $symbol],
                ['signal_enabled' => true, 'trade_enabled' => false]
            );
        }

        foreach (['M15' => false, 'M30' => false, 'H1' => true, 'H4' => true, 'D1' => true, 'W1' => false] as $tf => $enabled) {
            AlertTimeframe::firstOrCreate(
                ['name' => $tf],
                ['is_enabled' => $enabled]
            );
        }

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
            SignalPattern::firstOrCreate(
                ['code' => $code],
                [
                    'system_name' => $name,
                    'direction' => $direction,
                    'is_enabled' => true,
                ]
            );
        }
    }

    public function pageData(): array
    {
        $this->ensureDefaults();

        return [
            'settings' => SymbolSetting::orderBy('symbol')->get(),
            'timeframes' => AlertTimeframe::orderBy('id')->get(),
            'patterns' => SignalPattern::orderBy('code')->get(),
        ];
    }

    public function update(array $data): array
    {
        $symbolSettings = $data['settings'] ?? [];

        foreach (SymbolSetting::all() as $setting) {
            $values = $symbolSettings[$setting->symbol] ?? [];

            $setting->update([
                'signal_enabled' => isset($values['signal_enabled']),
                'trade_enabled' => isset($values['trade_enabled']),
            ]);
        }

        $selectedTimeframes = $data['timeframes'] ?? [];

        if (count($selectedTimeframes) > 5) {
            return [
                'success' => false,
                'message' => 'Maximum 5 ta timeframe select kora jabe.',
            ];
        }

        foreach (AlertTimeframe::all() as $timeframe) {
            $timeframe->update([
                'is_enabled' => in_array($timeframe->name, $selectedTimeframes),
            ]);
        }

        $patternInput = $data['patterns'] ?? [];

        foreach (SignalPattern::all() as $pattern) {
            $values = $patternInput[$pattern->code] ?? [];

            $pattern->update([
                'custom_name' => trim($values['custom_name'] ?? '') ?: null,
                'is_enabled' => isset($values['is_enabled']),
            ]);
        }

        return [
            'success' => true,
            'message' => 'Settings updated successfully.',
        ];
    }
}