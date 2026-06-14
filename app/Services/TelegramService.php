<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\TradeSignal;

class TelegramService
{
    public function sendShortMessage(string $message): void
    {
        if (!env('TELEGRAM_BOT_TOKEN') || !env('TELEGRAM_CHAT_ID')) {
            return;
        }

        Http::withoutVerifying()
            ->timeout(20)
            ->post(
                'https://api.telegram.org/bot' . env('TELEGRAM_BOT_TOKEN') . '/sendMessage',
                [
                    'chat_id' => env('TELEGRAM_CHAT_ID'),
                    'text' => $message,
                ]
            );
    }

    public function sendSignal(array $record, array $summary): void
    {
        if (!env('TELEGRAM_BOT_TOKEN') || !env('TELEGRAM_CHAT_ID')) {
            return;
        }

        $symbol = $record['symbol'] ?? 'XAUUSD';
        $safeSymbol = strtoupper(str_replace(['/', '\\', ' '], '_', $symbol));

        $message =
            "🔥 MT5 AI Trading Alert\n\n" .
            "Symbol: " . ($record['symbol'] ?? 'N/A') . "\n" .
            "Price: " . ($record['price'] ?? 'N/A') . "\n" .
            "Plan: " . ($summary['plan'] ?? 'N/A') . "\n" .
            "Entry: " . ($summary['entry'] ?? 'N/A') . "\n" .
            "SL: " . ($summary['sl'] ?? 'N/A') . "\n" .
            "TP1: " . ($summary['tp1'] ?? 'N/A') . "\n" .
            "TP2: " . ($summary['tp2'] ?? 'N/A') . "\n" .
            "R:R: " . ($summary['rr'] ?? 'N/A') . "\n" .
            "Grade: " . ($summary['grade'] ?? 'N/A');

        $signalChart = public_path("charts/{$safeSymbol}_m15_signal.png");
        $normalChart = public_path("charts/{$safeSymbol}_m15.png");

        $chartPath = file_exists($signalChart) ? $signalChart : $normalChart;

        if (file_exists($chartPath)) {
            Http::withoutVerifying()
                ->timeout(30)
                ->attach(
                    'photo',
                    file_get_contents($chartPath),
                    "{$safeSymbol}_m15.png"
                )
                ->post(
                    'https://api.telegram.org/bot' . env('TELEGRAM_BOT_TOKEN') . '/sendPhoto',
                    [
                        'chat_id' => env('TELEGRAM_CHAT_ID'),
                        'caption' => $message,
                    ]
                );

            return;
        }

        $this->sendShortMessage($message);
    }

    public function sendCloseAlert(TradeSignal $signal, string $result, float $closedPrice): void
    {
        if (!env('TELEGRAM_BOT_TOKEN') || !env('TELEGRAM_CHAT_ID')) {
            return;
        }

        $icon = 'ℹ️';

        if ($result === 'WIN') {
            $icon = '✅';
        }

        if ($result === 'BIG_WIN') {
            $icon = '🏆';
        }

        if ($result === 'LOSS') {
            $icon = '❌';
        }

        $message =
            $icon . " MT5 Signal Closed\n\n" .
            "Symbol: " . ($signal->symbol ?? 'N/A') . "\n" .
            "Action: " . ($signal->action ?? 'N/A') . "\n" .
            "Grade: " . ($signal->grade ?? 'N/A') . "\n\n" .
            "Entry: " . ($signal->entry ?? $signal->entry_price ?? 'N/A') . "\n" .
            "SL: " . ($signal->sl ?? $signal->sl_price ?? 'N/A') . "\n" .
            "TP1: " . ($signal->tp1 ?? $signal->tp1_price ?? 'N/A') . "\n" .
            "TP2: " . ($signal->tp2 ?? $signal->tp2_price ?? 'N/A') . "\n\n" .
            "Closed Price: " . $closedPrice . "\n" .
            "Result: " . $result . "\n" .
            "Closed At: " . now()->format('Y-m-d H:i:s');

        $this->sendShortMessage($message);
    }
}