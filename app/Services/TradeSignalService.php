<?php

namespace App\Services;

use App\Models\TradeSignal;

class TradeSignalService
{
    public function saveIfGood(string $analysis, array $data, string $candleKey): bool
    {
        if (!$this->isGoodSetup($analysis)) {
            return false;
        }

        $symbol = $data['symbol'] ?? 'XAUUSD';
        $action = $this->extractAction($analysis);
        $grade = $this->extractGrade($analysis);

        if ($action === 'NO_TRADE' || !$grade) {
            return false;
        }

        if (TradeSignal::where('symbol', $symbol)->where('result', 'RUNNING')->exists()) {
            return false;
        }

        $signalKey = $symbol . '_' . $action . '_' . $grade . '_' . $candleKey;

        if ($this->isDuplicateSignalKey($signalKey)) {
            return false;
        }

        TradeSignal::create([
            'symbol' => $symbol,
            'action' => $action,
            'direction' => $action,
            'bias' => $this->extractBias($analysis),
            'entry' => $this->extractNumber($analysis, 'Entry'),
            'entry_price' => $this->extractNumber($analysis, 'Entry'),
            'sl' => $this->extractNumber($analysis, 'SL'),
            'sl_price' => $this->extractNumber($analysis, 'SL'),
            'tp1' => $this->extractNumber($analysis, 'TP1'),
            'tp1_price' => $this->extractNumber($analysis, 'TP1'),
            'tp2' => $this->extractNumber($analysis, 'TP2'),
            'tp2_price' => $this->extractNumber($analysis, 'TP2'),
            'grade' => $grade,
            'result' => 'RUNNING',
            'analysis' => $analysis,
        ]);

        return true;
    }

    public function updateRunningResults(array $data, callable $closeAlert): void
    {
        $symbol = $data['symbol'] ?? null;
        $price = (float) ($data['price'] ?? 0);

        if (!$symbol || $price <= 0) {
            return;
        }

        $signals = TradeSignal::where('symbol', $symbol)
            ->where('result', 'RUNNING')
            ->get();

        foreach ($signals as $signal) {
            $action = strtoupper($signal->action ?? $signal->direction ?? '');
            $sl = (float) ($signal->sl ?? $signal->sl_price ?? 0);
            $tp1 = (float) ($signal->tp1 ?? $signal->tp1_price ?? 0);
            $tp2 = (float) ($signal->tp2 ?? $signal->tp2_price ?? 0);

            if (!$action || $sl <= 0 || $tp1 <= 0) {
                continue;
            }

            if ($action === 'BUY') {
                if ($tp2 > 0 && $price >= $tp2) {
                    $this->closeSignal($signal, 'BIG_WIN', $price, $closeAlert);
                } elseif ($price >= $tp1) {
                    $this->closeSignal($signal, 'WIN', $price, $closeAlert);
                } elseif ($price <= $sl) {
                    $this->closeSignal($signal, 'LOSS', $price, $closeAlert);
                }
            }

            if ($action === 'SELL') {
                if ($tp2 > 0 && $price <= $tp2) {
                    $this->closeSignal($signal, 'BIG_WIN', $price, $closeAlert);
                } elseif ($price <= $tp1) {
                    $this->closeSignal($signal, 'WIN', $price, $closeAlert);
                } elseif ($price >= $sl) {
                    $this->closeSignal($signal, 'LOSS', $price, $closeAlert);
                }
            }
        }
    }

    private function closeSignal(TradeSignal $signal, string $result, float $closedPrice, callable $closeAlert): void
    {
        if ($signal->result !== 'RUNNING') {
            return;
        }

        $signal->update([
            'result' => $result,
            'closed_at' => now(),
        ]);

        $closeAlert($signal, $result, $closedPrice);
    }

    public function isGoodSetup(string $analysis): bool
    {
        $text = mb_strtoupper($analysis);

        if (str_contains($text, 'কোন ট্রেড নয়') || str_contains($text, 'NO TRADE')) {
            return false;
        }

        return str_contains($text, 'A+') ||
            str_contains($text, 'TRADE GRADE: A') ||
            str_contains($text, 'ট্রেড গ্রেড: A') ||
            str_contains($text, 'TRADE GRADE: A+');
    }

    public function extractAction(string $analysis): string
    {
        $upper = mb_strtoupper($analysis);

        if (str_contains($upper, 'SELL'))
            return 'SELL';
        if (str_contains($upper, 'BUY'))
            return 'BUY';
        if (str_contains($analysis, 'সেল'))
            return 'SELL';
        if (str_contains($analysis, 'বাই'))
            return 'BUY';

        return 'NO_TRADE';
    }

    public function extractGrade(string $analysis): ?string
    {
        $upper = mb_strtoupper($analysis);

        if (str_contains($upper, 'A+'))
            return 'A+';

        if (str_contains($upper, 'TRADE GRADE: A') || str_contains($upper, 'ট্রেড গ্রেড: A')) {
            return 'A';
        }

        return null;
    }

    public function extractBias(string $analysis): ?string
    {
        $upper = mb_strtoupper($analysis);

        if (str_contains($upper, 'বুলিশ') || str_contains($upper, 'BULLISH'))
            return 'Bullish';
        if (str_contains($upper, 'বিয়ারিশ') || str_contains($upper, 'BEARISH'))
            return 'Bearish';

        return null;
    }

    public function extractNumber(string $analysis, string $label): ?float
    {
        $pattern = '/' . preg_quote($label, '/') . '\s*:\s*([0-9]+(?:\.[0-9]+)?)/i';

        if (preg_match($pattern, $analysis, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    private function isDuplicateSignalKey(string $signalKey): bool
    {
        $file = storage_path('app/signal_keys.json');
        $keys = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];

        if (in_array($signalKey, $keys)) {
            return true;
        }

        $keys[] = $signalKey;
        $keys = array_slice($keys, -500);

        file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return false;
    }
    public function extractSignalSummary(string $analysis): array
    {
        return [
            'entry' => $this->extractTextAfterLabel($analysis, 'Entry'),
            'sl' => $this->extractTextAfterLabel($analysis, 'SL'),
            'tp1' => $this->extractTextAfterLabel($analysis, 'TP1'),
            'tp2' => $this->extractTextAfterLabel($analysis, 'TP2'),
            'grade' => $this->extractTextAfterLabel($analysis, 'Trade Grade'),
            'plan' => $this->extractTextAfterLabel($analysis, 'Entry Plan'),
            'rr' => $this->extractTextAfterLabel($analysis, 'R:R'),
        ];
    }

    private function extractTextAfterLabel(string $text, string $label): ?string
    {
        $pattern = '/' . preg_quote($label, '/') . '\s*:\s*(.+)/i';

        if (preg_match($pattern, $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}