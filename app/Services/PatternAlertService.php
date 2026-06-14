<?php

namespace App\Services;

use App\Models\SymbolSetting;
use App\Models\SignalPattern;

class PatternAlertService
{
    public function process(array $data, callable $sendMessage): array
    {
        $symbol = $data['symbol'] ?? null;

        if (!$symbol) {
            return [];
        }

        $setting = SymbolSetting::firstOrCreate(
            ['symbol' => $symbol],
            ['signal_enabled' => true, 'trade_enabled' => false]
        );

        if (!$setting->signal_enabled) {
            return [];
        }

        $enabledTimeframes = \App\Models\AlertTimeframe::where('is_enabled', true)
            ->pluck('name')
            ->toArray();

        $enabledPatterns = SignalPattern::where('is_enabled', true)
            ->get()
            ->keyBy('code');

        $alerts = [];

        foreach ($enabledTimeframes as $timeframe) {
            $candles = $data['timeframes'][$timeframe]['candles'] ?? [];

            if (!is_array($candles) || count($candles) < 3) {
                continue;
            }

            $detected = $this->detect($candles, $data['timeframes'][$timeframe] ?? []);

            foreach ($detected as $item) {
                $pattern = $enabledPatterns[$item['code']] ?? null;

                if (!$pattern) {
                    continue;
                }

                $displayName = $pattern->custom_name ?: $pattern->system_name;
                $last = end($candles);
                $candleTime = $last['time'] ?? ($data['server_time'] ?? now()->format('Y-m-d H:i'));

                $key = $symbol . '_' . $timeframe . '_' . $pattern->code . '_' . $candleTime;

                if ($this->isDuplicate($key)) {
                    continue;
                }

                $message = $symbol . ' ' . $timeframe . ' ' . $displayName;

                $sendMessage($message);

                $alerts[] = [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'code' => $pattern->code,
                    'name' => $displayName,
                ];
            }
        }

        return $alerts;
    }

    public function detect(array $candles, array $timeframeData = []): array
    {
        $count = count($candles);

        if ($count < 3) {
            return [];
        }

        $c1 = $candles[$count - 3];
        $c2 = $candles[$count - 2];
        $c3 = $candles[$count - 1];

        $alerts = [];

        $body = fn($c) => abs((float)$c['close'] - (float)$c['open']);
        $range = fn($c) => max(0.00001, (float)$c['high'] - (float)$c['low']);
        $bull = fn($c) => (float)$c['close'] > (float)$c['open'];
        $bear = fn($c) => (float)$c['close'] < (float)$c['open'];
        $upperWick = fn($c) => (float)$c['high'] - max((float)$c['open'], (float)$c['close']);
        $lowerWick = fn($c) => min((float)$c['open'], (float)$c['close']) - (float)$c['low'];

        if ($bear($c2) && $bull($c3) && (float)$c3['close'] > (float)$c2['open'] && (float)$c3['open'] < (float)$c2['close']) {
            $alerts[] = ['code' => 'B2'];
        }

        if ($bull($c2) && $bear($c3) && (float)$c3['open'] > (float)$c2['close'] && (float)$c3['close'] < (float)$c2['open']) {
            $alerts[] = ['code' => 'S2'];
        }

        if ($bear($c1) && $bull($c2) && $bull($c3) && $body($c3) > $body($c2) * 1.5 && (float)$c3['close'] > (float)$c2['high']) {
            $alerts[] = ['code' => 'B1'];
        }

        if ($bull($c1) && $bear($c2) && $bear($c3) && $body($c3) > $body($c2) * 1.5 && (float)$c3['close'] < (float)$c2['low']) {
            $alerts[] = ['code' => 'S7'];
        }

        if ($bull($c3) && $lowerWick($c3) > $body($c3) * 2 && $upperWick($c3) < $body($c3) * 1.2) {
            $alerts[] = ['code' => 'B3'];
        }

        if ($bear($c3) && $upperWick($c3) > $body($c3) * 2 && $lowerWick($c3) < $body($c3) * 1.2) {
            $alerts[] = ['code' => 'S4'];
        }

        if ($bull($c1) && $bear($c2) && $body($c2) < $body($c1) * 0.7 && $bull($c3) && (float)$c3['close'] > (float)$c1['high']) {
            $alerts[] = ['code' => 'B4'];
        }

        if ($bear($c1) && $bull($c2) && $body($c2) < $body($c1) * 0.7 && $bear($c3) && (float)$c3['close'] < (float)$c1['low']) {
            $alerts[] = ['code' => 'S3'];
        }

        if ((float)$c2['high'] < (float)$c1['high'] && (float)$c2['low'] > (float)$c1['low'] && $bull($c3) && (float)$c3['close'] > (float)$c1['high']) {
            $alerts[] = ['code' => 'B5'];
        }

        if ((float)$c2['high'] < (float)$c1['high'] && (float)$c2['low'] > (float)$c1['low'] && $bear($c3) && (float)$c3['close'] < (float)$c1['low']) {
            $alerts[] = ['code' => 'S6'];
        }

        if ($bull($c3) && $body($c3) > $range($c3) * 0.65 && (float)$c3['close'] > (float)$c2['high']) {
            $alerts[] = ['code' => 'B6'];
        }

        if ($bear($c3) && $body($c3) > $range($c3) * 0.65 && (float)$c3['close'] < (float)$c2['low']) {
            $alerts[] = ['code' => 'S7'];
        }

        $prevLows = array_column(array_slice($candles, -6, 5), 'low');
        $prevHighs = array_column(array_slice($candles, -6, 5), 'high');

        if ($prevLows && (float)$c3['low'] < min($prevLows) && (float)$c3['close'] > min($prevLows) && $bull($c3)) {
            $alerts[] = ['code' => 'B7'];
        }

        if ($prevHighs && (float)$c3['high'] > max($prevHighs) && (float)$c3['close'] < max($prevHighs) && $bear($c3)) {
            $alerts[] = ['code' => 'S8'];
        }

        $swingHigh = (float)($timeframeData['swing_high_30'] ?? 0);
        $swingLow = (float)($timeframeData['swing_low_30'] ?? 0);

        if ($swingHigh > $swingLow && $swingLow > 0) {
            $fibBuy618 = $swingHigh - (($swingHigh - $swingLow) * 0.618);
            $fibSell618 = $swingLow + (($swingHigh - $swingLow) * 0.618);
            $tolerance = (($swingHigh - $swingLow) * 0.015);

            if (abs((float)$c3['low'] - $fibBuy618) <= $tolerance && $bull($c3)) {
                $alerts[] = ['code' => 'B8'];
            }

            if (abs((float)$c3['high'] - $fibSell618) <= $tolerance && $bear($c3)) {
                $alerts[] = ['code' => 'S1'];
                $alerts[] = ['code' => 'S5'];
            }
        }

        return collect($alerts)->unique('code')->values()->all();
    }

    private function isDuplicate(string $key): bool
    {
        $file = storage_path('app/pattern_alert_keys.json');

        $keys = file_exists($file)
            ? (json_decode(file_get_contents($file), true) ?? [])
            : [];

        if (in_array($key, $keys)) {
            return true;
        }

        $keys[] = $key;
        $keys = array_slice($keys, -1000);

        file_put_contents(
            $file,
            json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        return false;
    }
}