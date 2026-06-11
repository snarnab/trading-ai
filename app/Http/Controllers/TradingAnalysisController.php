<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\TradeSignal;
use App\Models\SymbolSetting;
class TradingAnalysisController extends Controller
{
    public function analyze(Request $request)
    {
        $data = $request->all();

        $symbol = $data['symbol'] ?? null;

        if ($symbol) {
            $setting = SymbolSetting::firstOrCreate(
                ['symbol' => $symbol],
                [
                    'signal_enabled' => true,
                    'trade_enabled' => false,
                ]
            );

            if (!$setting->signal_enabled) {
                return response()->json([
                    'success' => true,
                    'source' => 'disabled',
                    'analysis' => 'Signal disabled for ' . $symbol,
                ]);
            }
        }

        $candleKey = $this->getSignalCandleKey($data);

        $this->updateRunningSignalResults($data);

        if (!isset($data['symbol'])) {
            return response()->json([
                'success' => false,
                'message' => 'Symbol missing',
                'received' => $data,
            ], 422);
        }

        // 1) Rule Engine first
        $ruleAnalysis = $this->ruleBasedAnalysis($data);

        // 2) If rule engine says no trade, do not spend Gemini quota
        if (!$this->isGoodSetup($ruleAnalysis)) {
            $record = $this->makeRecord($data, $ruleAnalysis);

            $this->saveLatestAnalysis($record);
            $this->saveHistory($record);

            return response()->json([
                'success' => true,
                'source' => 'rule_engine',
                'analysis' => $ruleAnalysis,
            ]);
        }

        // 3) Rule engine found possible setup, now ask Gemini for confirmation if quota available
        $prompt = $this->buildPrompt($data, $ruleAnalysis);
        $parts = $this->buildGeminiParts($prompt, $data['symbol'] ?? 'XAUUSD');

        $geminiResponse = Http::withoutVerifying()
            ->timeout(90)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key=' . env('GEMINI_API_KEY'),
                [
                    'contents' => [
                        [
                            'parts' => $parts
                        ]
                    ]
                ]
            );

        // 4) If Gemini fails/quota exceeded, fallback to Rule Engine
        if (!$geminiResponse->successful()) {
            $analysis = $ruleAnalysis . "\n\nNote: Gemini quota/error এর কারণে Rule Engine analysis ব্যবহার করা হয়েছে।";
            $record = $this->makeRecord($data, $analysis);

            $this->saveLatestAnalysis($record);
            $this->saveHistory($record);
            $shouldAlert = $this->saveTradeSignalIfGood($analysis, $data, $candleKey);

            if ($shouldAlert) {
                $summary = $this->extractSignalSummary($analysis);
                $this->sendTelegramSignal($record, $summary);
            }

            return response()->json([
                'success' => true,
                'source' => 'rule_engine_fallback',
                'analysis' => $analysis,
                'gemini_status' => $geminiResponse->status(),
            ]);
        }

        $analysis = $geminiResponse->json('candidates.0.content.parts.0.text');

        if (!$analysis) {
            $analysis = $ruleAnalysis . "\n\nNote: Gemini থেকে কোনো valid analysis পাওয়া যায়নি, তাই Rule Engine analysis ব্যবহার করা হয়েছে।";
        }

        $record = $this->makeRecord($data, $analysis);

        $this->saveLatestAnalysis($record);
        $this->saveHistory($record);
        $this->saveTradeSignalIfGood($analysis, $data);

        if ($this->isGoodSetup($analysis)) {
            $summary = $this->extractSignalSummary($analysis);
            $this->sendTelegramSignal($record, $summary);
        }

        return response()->json([
            'success' => true,
            'source' => 'gemini_confirmed',
            'analysis' => $analysis,
        ]);
    }

    private function getSignalCandleKey(array $data): string
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

    private function makeRecord(array $data, string $analysis): array
    {
        return [
            'symbol' => $data['symbol'] ?? 'N/A',
            'price' => $data['price'] ?? null,
            'spread' => $data['spread'] ?? null,
            'analysis' => $analysis,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    private function buildGeminiParts(string $prompt, ?string $symbol = null): array
    {
        $parts = [
            ['text' => $prompt]
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
                    'text' => $tf . ' chart image নিচে দেওয়া হলো।'
                ];

                $parts[] = [
                    'inline_data' => [
                        'mime_type' => 'image/png',
                        'data' => base64_encode(file_get_contents($path)),
                    ]
                ];
            }
        }

        return $parts;
    }

    public function testAnalyze()
    {
        $request = new Request([
            'symbol' => 'XAUUSD',
            'price' => 4270.00,
            'spread' => 50,
            'timeframes' => [
                'D1' => [
                    'trend' => 'bearish',
                    'ema50' => 4500,
                    'ema200' => 4550,
                    'rsi14' => 35,
                    'atr14' => 25,
                    'swing_high_30' => 4600,
                    'swing_low_30' => 4200,
                ],
                'H4' => [
                    'trend' => 'bearish',
                    'ema50' => 4400,
                    'ema200' => 4500,
                    'rsi14' => 34,
                    'atr14' => 22,
                    'swing_high_30' => 4450,
                    'swing_low_30' => 4210,
                ],
                'H1' => [
                    'trend' => 'bearish',
                    'ema50' => 4330,
                    'ema200' => 4420,
                    'rsi14' => 38,
                    'atr14' => 18,
                    'swing_high_30' => 4350,
                    'swing_low_30' => 4240,
                ],
                'M15' => [
                    'trend' => 'bearish',
                    'ema50' => 4280,
                    'ema200' => 4310,
                    'rsi14' => 45,
                    'atr14' => 8,
                    'swing_high_30' => 4295,
                    'swing_low_30' => 4255,
                ],
            ],
        ]);

        return $this->analyze($request);
    }

    public function dashboard()
    {
        $symbols = [
            'XAUUSD',
            'BTCUSD',
            'ETHUSD',
            'EURUSD',
            'GBPUSD',
            'USDJPY',
            'GBPJPY',
            'USOIL.cash',
        ];

        $cards = [];

        foreach ($symbols as $symbol) {
            $latestFile = storage_path("app/latest_analysis_{$symbol}.json");
            $latest = null;

            if (file_exists($latestFile)) {
                $latest = json_decode(file_get_contents($latestFile), true);
            }

            $signal = TradeSignal::where('symbol', $symbol)
                ->latest()
                ->first();

            $wins = TradeSignal::where('symbol', $symbol)
                ->whereIn('result', ['WIN', 'BIG_WIN'])
                ->count();

            $losses = TradeSignal::where('symbol', $symbol)
                ->where('result', 'LOSS')
                ->count();

            $winRate = ($wins + $losses) > 0
                ? round(($wins / ($wins + $losses)) * 100, 2)
                : 0;

            $runningPips = null;

            if ($signal && $signal->result === 'RUNNING' && $latest && isset($latest['price'])) {
                $currentPrice = (float) $latest['price'];
                $entry = (float) ($signal->entry ?? $signal->entry_price ?? 0);
                $action = strtoupper($signal->action ?? $signal->direction ?? '');

                if ($entry > 0) {
                    if ($action === 'BUY') {
                        $runningPips = round($currentPrice - $entry, 3);
                    }

                    if ($action === 'SELL') {
                        $runningPips = round($entry - $currentPrice, 3);
                    }
                }
            }

            $cards[] = [
                'symbol' => $symbol,
                'latest' => $latest,
                'signal' => $signal,
                'is_good' => $latest && isset($latest['analysis'])
                    ? $this->isGoodSetup($latest['analysis'])
                    : false,
                'summary' => $latest && isset($latest['analysis'])
                    ? $this->extractSignalSummary($latest['analysis'])
                    : [],
                'win_rate' => $winRate,
                'running_pips' => $runningPips,
            ];
        }

        return view('dashboard', compact('cards'));
    }


    public function history()
    {
        $file = storage_path('app/analysis_history.json');
        $history = [];

        if (file_exists($file)) {
            $history = json_decode(file_get_contents($file), true) ?? [];
        }

        return view('history', compact('history'));
    }
    public function stats()
    {
        $total = TradeSignal::count();

        $running = TradeSignal::where('result', 'RUNNING')->count();

        $wins = TradeSignal::where('result', 'WIN')->count();

        $bigWins = TradeSignal::where('result', 'BIG_WIN')->count();

        $losses = TradeSignal::where('result', 'LOSS')->count();

        $closedTrades = $wins + $bigWins + $losses;

        $winRate = $closedTrades > 0
            ? round((($wins + $bigWins) / $closedTrades) * 100, 2)
            : 0;

        $symbolStats = TradeSignal::select('symbol')
            ->distinct()
            ->pluck('symbol');

        $performance = [];

        foreach ($symbolStats as $symbol) {

            $w = TradeSignal::where('symbol', $symbol)
                ->whereIn('result', ['WIN', 'BIG_WIN'])
                ->count();

            $l = TradeSignal::where('symbol', $symbol)
                ->where('result', 'LOSS')
                ->count();

            $t = $w + $l;

            $performance[] = [
                'symbol' => $symbol,
                'wins' => $w,
                'losses' => $l,
                'total' => $t,
                'win_rate' => $t > 0
                    ? round(($w / $t) * 100, 2)
                    : 0,
            ];
        }

        usort($performance, function ($a, $b) {
            return $b['win_rate'] <=> $a['win_rate'];
        });

        return view('stats', [
            'total' => $total,
            'running' => $running,
            'wins' => $wins,
            'bigWins' => $bigWins,
            'losses' => $losses,
            'winRate' => $winRate,
            'performance' => $performance,
        ]);
    }

    public function signals()
    {
        $signals = TradeSignal::latest()->take(50)->get();

        return view('signals', compact('signals'));
    }

    public function performance()
    {
        $signals = TradeSignal::latest()->take(100)->get();

        return view('performance', compact('signals'));
    }

    public function uploadChart(Request $request)
    {
        if (!$request->hasFile('chart')) {
            return response()->json([
                'success' => false,
                'message' => 'No chart uploaded'
            ], 422);
        }

        $symbol = strtoupper($request->input('symbol', 'XAUUSD'));
        $symbol = str_replace(['/', '\\', ' '], '_', $symbol);

        $timeframe = strtolower($request->input('timeframe', 'm15'));

        if (!in_array($timeframe, ['h4', 'h1', 'm15', 'm15_signal'])) {
            $timeframe = 'm15';
        }

        if (!file_exists(public_path('charts'))) {
            mkdir(public_path('charts'), 0777, true);
        }

        $fileName = $symbol . '_' . $timeframe . '.png';

        $request->file('chart')->move(
            public_path('charts'),
            $fileName
        );

        return response()->json([
            'success' => true,
            'message' => 'Chart uploaded',
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'file' => $fileName,
        ]);
    }

    public function testSave()
    {
        $record = [
            'symbol' => 'XAUUSD',
            'price' => 4270.50,
            'spread' => 50,
            'analysis' => "Symbol: XAUUSD\nEntry Plan: কোন ট্রেড নয়\n\nEntry: N/A\nSL: N/A\nTP1: N/A\nTP2: N/A\n\nHTF Bias: বিয়ারিশ\nTrade Grade: কোন ট্রেড নয়\nReason: Test analysis saved successfully.",
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];

        $this->saveLatestAnalysis($record);
        $this->saveHistory($record);

        return response()->json(['success' => true]);
    }

    public function latestSignal()
    {
        $signal = TradeSignal::whereIn('grade', ['A', 'A+'])
            ->where('result', 'RUNNING')
            ->latest()
            ->first();

        if (!$signal) {
            return response()->json([
                'action' => 'NO_TRADE',
                'entry' => 0,
                'sl' => 0,
                'tp1' => 0,
                'tp2' => 0,
            ]);
        }

        $setting = SymbolSetting::firstOrCreate(
            ['symbol' => $signal->symbol],
            [
                'signal_enabled' => true,
                'trade_enabled' => false,
            ]
        );

        if (!$setting->trade_enabled) {
            return response()->json([
                'action' => 'NO_TRADE',
                'reason' => 'Auto trade disabled for ' . $signal->symbol,
                'entry' => 0,
                'sl' => 0,
                'tp1' => 0,
                'tp2' => 0,
            ]);
        }

        return response()->json([
            'action' => $signal->action ?? $signal->direction ?? 'NO_TRADE',
            'symbol' => $signal->symbol,
            'entry' => $signal->entry ?? $signal->entry_price ?? 0,
            'sl' => $signal->sl ?? $signal->sl_price ?? 0,
            'tp1' => $signal->tp1 ?? $signal->tp1_price ?? 0,
            'tp2' => $signal->tp2 ?? $signal->tp2_price ?? 0,
            'grade' => $signal->grade,
            'result' => $signal->result,
        ]);
    }


    private function calculateFibLimitEntry(
        string $action,
        float $swingLow,
        float $swingHigh,
        float $sl,
        float $tp1,
        float $minRR = 2.0
    ): array {
        if ($swingLow <= 0 || $swingHigh <= 0 || $swingHigh <= $swingLow) {
            return [
                'valid' => false,
                'entry' => 0,
                'rr' => 0,
                'order_type' => $action,
                'reason' => 'Valid swing high/low পাওয়া যায়নি।'
            ];
        }

        $range = $swingHigh - $swingLow;

        $fib618 = $swingHigh - ($range * 0.618);
        $fib705 = $swingHigh - ($range * 0.705);
        $fib786 = $swingHigh - ($range * 0.786);

        if ($action === 'BUY') {
            $candidates = [$fib618, $fib705, $fib786];

            foreach ($candidates as $entry) {
                $risk = $entry - $sl;
                $reward = $tp1 - $entry;

                if ($risk > 0 && $reward > 0) {
                    $rr = round($reward / $risk, 2);

                    if ($rr >= $minRR) {
                        return [
                            'valid' => true,
                            'entry' => $entry,
                            'rr' => $rr,
                            'order_type' => 'BUY LIMIT',
                            'reason' => 'Fibonacci discount zone থেকে BUY LIMIT পাওয়া গেছে।'
                        ];
                    }
                }
            }
        }

        if ($action === 'SELL') {
            $candidates = [$fib382 = $swingLow + ($range * 0.382), $fib50 = $swingLow + ($range * 0.50), $fib618_sell = $swingLow + ($range * 0.618)];

            foreach ($candidates as $entry) {
                $risk = $sl - $entry;
                $reward = $entry - $tp1;

                if ($risk > 0 && $reward > 0) {
                    $rr = round($reward / $risk, 2);

                    if ($rr >= $minRR) {
                        return [
                            'valid' => true,
                            'entry' => $entry,
                            'rr' => $rr,
                            'order_type' => 'SELL LIMIT',
                            'reason' => 'Fibonacci premium zone থেকে SELL LIMIT পাওয়া গেছে।'
                        ];
                    }
                }
            }
        }

        return [
            'valid' => false,
            'entry' => 0,
            'rr' => 0,
            'order_type' => $action,
            'reason' => 'Fibonacci entry দিয়ে minimum 1:2 R:R পাওয়া যায়নি।'
        ];
    }

    private function ruleBasedAnalysis(array $data): string
    {
        file_put_contents(
            storage_path('app/debug.json'),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $symbol = $data['symbol'] ?? 'XAUUSD';
        $price = (float) ($data['price'] ?? 0);

        $d1 = $data['timeframes']['D1'] ?? [];
        $h4 = $data['timeframes']['H4'] ?? [];
        $h1 = $data['timeframes']['H1'] ?? [];
        $m15 = $data['timeframes']['M15'] ?? [];

        $d1Trend = $d1['trend'] ?? 'neutral';
        $h4Trend = $h4['trend'] ?? 'neutral';
        $h1Trend = $h1['trend'] ?? 'neutral';
        $m15Trend = $m15['trend'] ?? 'neutral';

        $h1Ema50 = (float) ($h1['ema50'] ?? 0);
        $h1Ema200 = (float) ($h1['ema200'] ?? 0);
        $m15Rsi = (float) ($m15['rsi14'] ?? 50);
        $m15Atr = (float) ($m15['atr14'] ?? 10);

        $h1High = (float) ($h1['swing_high_30'] ?? 0);
        $h1Low = (float) ($h1['swing_low_30'] ?? 0);

        $m15Candles = $m15['candles'] ?? [];
        $h1Candles = $h1['candles'] ?? [];

        $m15Structure = $this->detectStructure($m15Candles);
        $h1Structure = $this->detectStructure($h1Candles);

        $liquidity = $this->detectLiquiditySweep($m15Candles);
        $fib = $this->calculateFibZone($h1Low, $h1High, $price);

        $bias = 'Neutral';
        $action = 'NO TRADE';
        $grade = 'কোন ট্রেড নয়';

        $entry = 'N/A';
        $sl = 'N/A';
        $tp1 = 'N/A';
        $tp2 = 'N/A';

        $score = 0;
        $reason = [];

        if ($d1Trend === $h4Trend && $h4Trend === $h1Trend && $h1Trend !== 'neutral') {
            $score += 2;
            $bias = $h1Trend === 'bullish' ? 'বুলিশ' : 'বিয়ারিশ';
            $reason[] = 'D1, H4 এবং H1 একই direction দেখাচ্ছে।';
        } elseif ($h4Trend === $h1Trend && $h1Trend !== 'neutral') {
            $score += 1;
            $bias = $h1Trend === 'bullish' ? 'বুলিশ' : 'বিয়ারিশ';
            $reason[] = 'H4 এবং H1 একই direction দেখাচ্ছে।';
        }

        if ($h1Trend === 'bearish' && $price < $h1Ema50 && $h1Ema50 < $h1Ema200) {
            $score += 2;
            $reason[] = 'Price H1 EMA50 এবং EMA200 এর নিচে আছে।';
        }

        if ($h1Trend === 'bullish' && $price > $h1Ema50 && $h1Ema50 > $h1Ema200) {
            $score += 2;
            $reason[] = 'Price H1 EMA50 এবং EMA200 এর উপরে আছে।';
        }

        if ($m15Structure['bos'] ?? false) {
            $score += 1;
            $reason[] = 'M15 BOS detect হয়েছে।';
        }

        if ($m15Structure['choch'] ?? false) {
            $score += 1;
            $reason[] = 'M15 CHOCH detect হয়েছে।';
        }

        if ($liquidity['sweep'] ?? false) {
            $score += 2;
            $reason[] = $liquidity['description'];
        }

        if ($fib['in_zone'] ?? false) {
            $score += 1;
            $reason[] = $fib['description'];
        }

        if ($m15Rsi > 35 && $m15Rsi < 65) {
            $score += 1;
            $reason[] = 'M15 RSI extreme না, continuation এর জন্য acceptable।';
        }

        $hasConfirmation =
            ($liquidity['sweep'] ?? false) ||
            ($m15Structure['bos'] ?? false) ||
            ($m15Structure['choch'] ?? false) ||
            ($fib['in_zone'] ?? false);

        // SELL SETUP
        // SELL SETUP
        if (
            $h4Trend === 'bearish' &&
            $h1Trend === 'bearish' &&
            $price < $h1Ema50 &&
            $h1Ema50 < $h1Ema200 &&
            $score >= 6 &&
            $hasConfirmation
        ) {
            $action = 'SELL';
            $grade = $score >= 8 ? 'A+' : 'A';

            $slPrice = $h1High;
            $tp1Price = $h1Low;
            $tp2Price = $h1Low - (($h1High - $h1Low) * 0.5);

            $fibEntry = $this->calculateFibLimitEntry(
                'SELL',
                $h1Low,
                $h1High,
                $slPrice,
                $tp1Price,
                2.0
            );

            if (!$fibEntry['valid']) {
                $action = 'NO TRADE';
                $grade = 'কোন ট্রেড নয়';

                $entry = 'N/A';
                $sl = 'N/A';
                $tp1 = 'N/A';
                $tp2 = 'N/A';

                $reason[] = $fibEntry['reason'];
            } else {
                $action = $fibEntry['order_type'];

                $entry = number_format($fibEntry['entry'], 3, '.', '');
                $sl = number_format($slPrice, 3, '.', '');
                $tp1 = number_format($tp1Price, 3, '.', '');
                $tp2 = number_format($tp2Price, 3, '.', '');
                $rrText = $fibEntry['rr'];
                $reason[] = $fibEntry['reason'];
                $reason[] = "R:R {$fibEntry['rr']} confirmed.";
            }
        }

        // BUY SETUP
        elseif (
            $h4Trend === 'bullish' &&
            $h1Trend === 'bullish' &&
            $price > $h1Ema50 &&
            $h1Ema50 > $h1Ema200 &&
            $score >= 6 &&
            $hasConfirmation
        ) {
            $action = 'BUY';
            $grade = $score >= 8 ? 'A+' : 'A';

            $slPrice = $h1Low;
            $tp1Price = $h1High;
            $tp2Price = $h1High + (($h1High - $h1Low) * 0.5);

            $fibEntry = $this->calculateFibLimitEntry(
                'BUY',
                $h1Low,
                $h1High,
                $slPrice,
                $tp1Price,
                2.0
            );

            if (!$fibEntry['valid']) {
                $action = 'NO TRADE';
                $grade = 'কোন ট্রেড নয়';

                $entry = 'N/A';
                $rrText = 'N/A';
                $sl = 'N/A';
                $tp1 = 'N/A';
                $tp2 = 'N/A';

                $reason[] = $fibEntry['reason'];
            } else {
                $action = $fibEntry['order_type'];

                $entry = number_format($fibEntry['entry'], 3, '.', '');
                $sl = number_format($slPrice, 3, '.', '');
                $tp1 = number_format($tp1Price, 3, '.', '');
                $tp2 = number_format($tp2Price, 3, '.', '');
                $rrText = $fibEntry['rr'];
                $reason[] = $fibEntry['reason'];
                $reason[] = "R:R {$fibEntry['rr']} confirmed.";
            }
        }

        return "
Symbol: {$symbol}
Entry Plan: {$action}

Entry: {$entry}
SL: {$sl}
TP1: {$tp1}
TP2: {$tp2}

HTF Bias: {$bias}
Structure: D1={$d1Trend}, H4={$h4Trend}, H1={$h1Trend}, M15={$m15Trend}; H1 Structure={$h1Structure['type']}, M15 Structure={$m15Structure['type']}
Liquidity: {$liquidity['description']}
Key Levels: H1 Swing High {$h1High}, H1 Swing Low {$h1Low}
Setup Zone: {$fib['description']}
Trade Grade: {$grade}
R:R: {$rrText}
Reason: Score {$score}/9. " . implode(' ', $reason);
    }

    private function detectStructure(array $candles): array
    {
        if (count($candles) < 6) {
            return [
                'type' => 'unknown',
                'bos' => false,
                'choch' => false,
            ];
        }

        $recent = array_slice($candles, -6);

        $highs = array_column($recent, 'high');
        $lows = array_column($recent, 'low');

        $lastHigh = (float) end($highs);
        $lastLow = (float) end($lows);

        $prevHigh = (float) $highs[count($highs) - 2];
        $prevLow = (float) $lows[count($lows) - 2];

        $oldHigh = (float) $highs[0];
        $oldLow = (float) $lows[0];

        $type = 'range';
        $bos = false;
        $choch = false;

        if ($lastHigh > $prevHigh && $prevLow > $oldLow) {
            $type = 'bullish';
        }

        if ($lastLow < $prevLow && $prevHigh < $oldHigh) {
            $type = 'bearish';
        }

        if ($lastHigh > max(array_slice($highs, 0, -1))) {
            $bos = true;
        }

        if ($lastLow < min(array_slice($lows, 0, -1))) {
            $bos = true;
        }

        if ($type === 'bullish' && $lastLow < $prevLow) {
            $choch = true;
        }

        if ($type === 'bearish' && $lastHigh > $prevHigh) {
            $choch = true;
        }

        return [
            'type' => $type,
            'bos' => $bos,
            'choch' => $choch,
        ];
    }

    private function detectLiquiditySweep(array $candles): array
    {
        if (count($candles) < 5) {
            return [
                'sweep' => false,
                'description' => 'Liquidity sweep detect করার মতো যথেষ্ট candle নেই।',
            ];
        }

        $recent = array_slice($candles, -5);

        $last = end($recent);

        $lastHigh = (float) ($last['high'] ?? 0);
        $lastLow = (float) ($last['low'] ?? 0);
        $lastClose = (float) ($last['close'] ?? 0);

        $previous = array_slice($recent, 0, -1);

        $prevHighs = array_column($previous, 'high');
        $prevLows = array_column($previous, 'low');

        $prevHigh = max($prevHighs);
        $prevLow = min($prevLows);

        if ($lastHigh > $prevHigh && $lastClose < $prevHigh) {
            return [
                'sweep' => true,
                'description' => 'Buy-side liquidity sweep হয়েছে; high sweep করে candle নিচে close করেছে।',
            ];
        }

        if ($lastLow < $prevLow && $lastClose > $prevLow) {
            return [
                'sweep' => true,
                'description' => 'Sell-side liquidity sweep হয়েছে; low sweep করে candle উপরে close করেছে।',
            ];
        }

        return [
            'sweep' => false,
            'description' => 'Clear liquidity sweep পাওয়া যায়নি।',
        ];
    }

    private function calculateFibZone(float $low, float $high, float $price): array
    {
        if ($low <= 0 || $high <= 0 || $high <= $low) {
            return [
                'in_zone' => false,
                'description' => 'Valid Fibonacci zone calculate করা যায়নি।',
            ];
        }

        $range = $high - $low;

        $fib50 = $high - ($range * 0.50);
        $fib618 = $high - ($range * 0.618);
        $fib705 = $high - ($range * 0.705);

        $min = min($fib50, $fib618, $fib705);
        $max = max($fib50, $fib618, $fib705);

        if ($price >= $min && $price <= $max) {
            return [
                'in_zone' => true,
                'description' => 'Price Fibonacci 0.50–0.705 retracement zone এর ভিতরে আছে।',
            ];
        }

        return [
            'in_zone' => false,
            'description' => 'Price Fibonacci premium/discount zone এর বাইরে আছে।',
        ];
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

    private function saveLatestAnalysis(array $record): void
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

    private function saveHistory(array $record): void
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

    private function saveTradeSignalIfGood(string $analysis, array $data, string $candleKey): bool
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

        // One active signal per symbol
        $runningSignal = TradeSignal::where('symbol', $symbol)
            ->where('result', 'RUNNING')
            ->first();

        if ($runningSignal) {
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

    private function isDuplicateSignalKey(string $signalKey): bool
    {
        $file = storage_path('app/signal_keys.json');

        $keys = [];

        if (file_exists($file)) {
            $keys = json_decode(file_get_contents($file), true) ?? [];
        }

        if (in_array($signalKey, $keys)) {
            return true;
        }

        $keys[] = $signalKey;

        $keys = array_slice($keys, -500);

        file_put_contents(
            $file,
            json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        return false;
    }

    private function isGoodSetup(string $analysis): bool
    {
        $text = mb_strtoupper($analysis);

        if (str_contains($text, 'কোন ট্রেড নয়')) {
            return false;
        }

        if (str_contains($text, 'NO TRADE')) {
            return false;
        }

        return str_contains($text, 'A+') ||
            str_contains($text, 'TRADE GRADE: A') ||
            str_contains($text, 'ট্রেড গ্রেড: A') ||
            str_contains($text, 'TRADE GRADE: A+');
    }

    private function extractAction(string $analysis): string
    {
        $upper = mb_strtoupper($analysis);

        if (str_contains($upper, 'SELL')) {
            return 'SELL';
        }

        if (str_contains($upper, 'BUY')) {
            return 'BUY';
        }

        if (str_contains($analysis, 'সেল')) {
            return 'SELL';
        }

        if (str_contains($analysis, 'বাই')) {
            return 'BUY';
        }

        return 'NO_TRADE';
    }

    private function extractGrade(string $analysis): ?string
    {
        $upper = mb_strtoupper($analysis);

        if (str_contains($upper, 'A+')) {
            return 'A+';
        }

        if (str_contains($upper, 'TRADE GRADE: A') || str_contains($upper, 'ট্রেড গ্রেড: A')) {
            return 'A';
        }

        return null;
    }

    private function extractBias(string $analysis): ?string
    {
        $upper = mb_strtoupper($analysis);

        if (str_contains($upper, 'বুলিশ') || str_contains($upper, 'BULLISH')) {
            return 'Bullish';
        }

        if (str_contains($upper, 'বিয়ারিশ') || str_contains($upper, 'BEARISH')) {
            return 'Bearish';
        }

        return null;
    }

    private function extractNumber(string $analysis, string $label): ?float
    {
        $pattern = '/' . preg_quote($label, '/') . '\s*:\s*([0-9]+(?:\.[0-9]+)?)/i';

        if (preg_match($pattern, $analysis, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    private function extractSignalSummary(string $analysis): array
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

    private function sendTelegramSignal(array $record, array $summary): void
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

    private function isDuplicateSignal(string $analysis): bool
    {
        $hashFile = storage_path('app/last_signal_hash.txt');
        $newHash = md5($analysis);

        if (file_exists($hashFile)) {
            $oldHash = trim(file_get_contents($hashFile));

            if ($oldHash === $newHash) {
                return true;
            }
        }

        file_put_contents($hashFile, $newHash);

        return false;
    }

    private function updateRunningSignalResults(array $data): void
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
                    $this->closeSignal($signal, 'BIG_WIN', $price);
                } elseif ($price >= $tp1) {
                    $this->closeSignal($signal, 'WIN', $price);
                } elseif ($price <= $sl) {
                    $this->closeSignal($signal, 'LOSS', $price);
                }
            }

            if ($action === 'SELL') {
                if ($tp2 > 0 && $price <= $tp2) {
                    $this->closeSignal($signal, 'BIG_WIN', $price);
                } elseif ($price <= $tp1) {
                    $this->closeSignal($signal, 'WIN', $price);
                } elseif ($price >= $sl) {
                    $this->closeSignal($signal, 'LOSS', $price);
                }
            }
        }
    }

    private function closeSignal(TradeSignal $signal, string $result, float $closedPrice): void
    {
        if ($signal->result !== 'RUNNING') {
            return;
        }

        $signal->update([
            'result' => $result,
            'closed_at' => now(),
        ]);

        $this->sendTelegramCloseAlert($signal, $result, $closedPrice);
    }

    private function sendTelegramCloseAlert(TradeSignal $signal, string $result, float $closedPrice): void
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


    public function symbolDashboard($symbol)
    {
        $symbol = urldecode($symbol);

        $latestFile = storage_path("app/latest_analysis_{$symbol}.json");

        $analysis = null;
        $summary = [];

        if (file_exists($latestFile)) {
            $analysis = json_decode(file_get_contents($latestFile), true);

            if ($analysis && isset($analysis['analysis'])) {
                $summary = $this->extractSignalSummary($analysis['analysis']);
            }
        }

        $signals = TradeSignal::where('symbol', $symbol)
            ->latest()
            ->take(20)
            ->get();

        return view('symbol-dashboard', compact('symbol', 'analysis', 'summary', 'signals'));
    }

    public function telegramChartTest($symbol)
    {
        $record = [
            'symbol' => $symbol,
            'price' => 4180.00,
        ];

        $summary = [
            'plan' => 'SELL',
            'entry' => '4180.00',
            'sl' => '4200.00',
            'tp1' => '4150.00',
            'tp2' => '4130.00',
            'grade' => 'A',
        ];

        $this->sendTelegramSignal($record, $summary);

        return response()->json([
            'success' => true,
            'message' => 'Telegram chart test sent',
            'symbol' => $symbol,
        ]);
    }


    public function settings()
    {
        $symbols = [
            'XAUUSD',
            'BTCUSD',
            'ETHUSD',
            'EURUSD',
            'GBPUSD',
            'USDJPY',
            'GBPJPY',
            'USOIL.cash',
        ];

        foreach ($symbols as $symbol) {
            SymbolSetting::firstOrCreate(
                ['symbol' => $symbol],
                [
                    'signal_enabled' => true,
                    'trade_enabled' => false,
                ]
            );
        }

        $settings = SymbolSetting::orderBy('symbol')->get();

        return view('settings', compact('settings'));
    }

    public function updateSettings(Request $request)
    {
        $settings = $request->input('settings', []);

        foreach ($settings as $symbol => $values) {
            SymbolSetting::updateOrCreate(
                ['symbol' => $symbol],
                [
                    'signal_enabled' => isset($values['signal_enabled']),
                    'trade_enabled' => isset($values['trade_enabled']),
                ]
            );
        }

        return redirect('/settings')->with('success', 'Settings updated successfully.');
    }

}