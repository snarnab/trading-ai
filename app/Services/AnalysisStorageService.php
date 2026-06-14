<?php

namespace App\Services;

class AnalysisStorageService
{
    public function getSignalCandleKey(array $data): string
    {
        $symbol = $data['symbol'] ?? 'UNKNOWN';
        $m15Candles = $data['timeframes']['M15']['candles'] ?? [];

        $lastClosedTime = null;

        if (is_array($m15Candles) && count($m15Candles) > 0) {
            $last = end($m15Candles);
            $lastClosedTime = $last['time'] ?? null;
        }

        if (!$lastClosedTime) {
            $lastClosedTime = $data['server_time'] ?? now()->format('Y-m-d H:i');
        }

        return $symbol . '_' . $lastClosedTime;
    }

    public function makeRecord(array $data, string $analysis): array
    {
        return [
            'symbol' => $data['symbol'] ?? 'N/A',
            'price' => $data['price'] ?? null,
            'spread' => $data['spread'] ?? null,
            'analysis' => $analysis,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    public function saveLatest(array $record): void
    {
        $symbol = $record['symbol'] ?? 'UNKNOWN';

        file_put_contents(
            storage_path("app/latest_analysis_{$symbol}.json"),
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        file_put_contents(
            storage_path('app/latest_analysis.json'),
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    public function saveHistory(array $record): void
    {
        $historyFile = storage_path('app/analysis_history.json');
        $oldHistory = [];

        if (file_exists($historyFile)) {
            $oldHistory = json_decode(file_get_contents($historyFile), true) ?? [];
        }

        array_unshift($oldHistory, $record);
        $oldHistory = array_slice($oldHistory, 0, 20);

        file_put_contents(
            $historyFile,
            json_encode($oldHistory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}