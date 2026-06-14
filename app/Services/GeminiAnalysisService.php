<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GeminiAnalysisService
{
    public function analyze(array $data, string $ruleAnalysis): array
    {
        $prompt = $this->buildPrompt($data, $ruleAnalysis);
        $parts = $this->buildParts($prompt, $data['symbol'] ?? 'XAUUSD');

        $response = Http::withoutVerifying()
            ->timeout(90)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key=' . env('GEMINI_API_KEY'),
                [
                    'contents' => [
                        [
                            'parts' => $parts,
                        ],
                    ],
                ]
            );

        if (!$response->successful()) {
            return [
                'success' => false,
                'status' => $response->status(),
                'analysis' => null,
            ];
        }

        return [
            'success' => true,
            'status' => $response->status(),
            'analysis' => $response->json('candidates.0.content.parts.0.text'),
        ];
    }

    private function buildParts(string $prompt, ?string $symbol = null): array
    {
        $parts = [
            ['text' => $prompt],
        ];

        $symbol = $symbol ? strtoupper($symbol) : 'XAUUSD';

        $chartFiles = [
            'H4' => public_path("charts/{$symbol}_h4.png"),
            'H1' => public_path("charts/{$symbol}_h1.png"),
            'M15' => public_path("charts/{$symbol}_m15.png"),
        ];

        foreach ($chartFiles as $tf => $path) {
            if (file_exists($path)) {
                $parts[] = [
                    'text' => $tf . ' chart image নিচে দেওয়া হলো।',
                ];

                $parts[] = [
                    'inline_data' => [
                        'mime_type' => 'image/png',
                        'data' => base64_encode(file_get_contents($path)),
                    ],
                ];
            }
        }

        return $parts;
    }

    private function buildPrompt(array $data, string $ruleAnalysis): string
    {
        return "
আপনি একজন অভিজ্ঞ Pro Trader এবং Multi-Timeframe Trading Analyst.

Rule Engine already found a possible setup.
আপনি শুধু confirm করবেন setup A/A+ কিনা।
যদি weak হয়, অবশ্যই লিখবেন: কোন ট্রেড নয়।

Rule Engine Analysis:
{$ruleAnalysis}

ভাষা নির্দেশনা:
- পুরো analysis অবশ্যই বাংলায় লিখবেন।
- English sentence ব্যবহার করবেন না।
- তবে trading terms যেমন D1, H4, H1, M15, EMA, RSI, ATR, FVG, Order Block, BOS, CHOCH, Entry, SL, TP ইংরেজিতে রাখা যাবে।
- জোর করে buy/sell setup দিবেন না।
- যদি chart image পাওয়া যায়, তাহলে chart image এবং MT5 JSON data দুইটা মিলিয়ে analysis করবেন।
- H4/H1/M15 chart image দেওয়া থাকলে image দেখে Structure, Liquidity, OB, FVG, BOS/CHOCH verify করবেন।
- JSON data এবং chart image conflict করলে chart image কে priority দিবেন।

Rules:
- শুধু A বা A+ setup হলে Entry, SL, TP দিবেন।
- setup A/A+ না হলে Entry দিবেন না।
- market unclear হলে কোন ট্রেড নয় বলবেন।
- answer সংক্ষিপ্ত, practical এবং trader-style হবে।

Output format ঠিক এইভাবে রাখবেন:

Symbol:
Entry Plan:

Entry:
SL:
TP1:
TP2:

HTF Bias:
Structure:
Liquidity:
Key Levels:
Setup Zone:
Trade Grade:
Reason:

MT5 Data:
" . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}