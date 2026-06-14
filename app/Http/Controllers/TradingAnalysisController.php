<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\TradeSignal;
use App\Models\SymbolSetting;
use App\Models\SignalPattern;
use App\Models\AlertTimeframe;
use App\Services\PatternAlertService;
use App\Services\TelegramService;
use App\Services\RuleAnalysisService;
use App\Services\TradeSignalService;
use App\Services\SettingsService;
use App\Services\AnalysisStorageService;

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

        $candleKey = app(AnalysisStorageService::class)->getSignalCandleKey($data);

        app(TradeSignalService::class)->updateRunningResults($data, function ($signal, $result, $closedPrice) {
            app(TelegramService::class)->sendCloseAlert($signal, $result, $closedPrice);
        });

        if (!isset($data['symbol'])) {
            return response()->json([
                'success' => false,
                'message' => 'Symbol missing',
                'received' => $data,
            ], 422);
        }

        // Pattern alert engine: selected symbol + selected timeframe + enabled signal pattern
        $patternAlerts = app(PatternAlertService::class)->process($data, function ($message) {
            app(TelegramService::class)->sendShortMessage($message);
        });

        // 1) Rule Engine first
        $ruleAnalysis = app(RuleAnalysisService::class)->analyze($data);

        // 2) If rule engine says no trade, do not spend Gemini quota
        if (!$this->isGoodSetup($ruleAnalysis)) {
            $record = app(AnalysisStorageService::class)->makeRecord($data, $ruleAnalysis);

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
            $record = app(AnalysisStorageService::class)->makeRecord($data, $analysis);
            $this->saveLatestAnalysis($record);
            $this->saveHistory($record);
            $shouldAlert = app(TradeSignalService::class)->saveIfGood($analysis, $data, $candleKey);

            if ($shouldAlert) {
                $summary = $this->extractSignalSummary($analysis);
                app(TelegramService::class)->sendSignal($record, $summary);
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

        $record = app(AnalysisStorageService::class)->makeRecord($data, $analysis);

        app(AnalysisStorageService::class)->saveLatest($record);
        app(AnalysisStorageService::class)->saveHistory($record);
        app(TradeSignalService::class)->saveIfGood($analysis, $data, $candleKey);

        if (app(TradeSignalService::class)->isGoodSetup($analysis)) {
            $summary = $this->extractSignalSummary($analysis);
            $this->sendTelegramSignal($record, $summary);
        }

        return response()->json([
            'success' => true,
            'source' => 'gemini_confirmed',
            'analysis' => $analysis,
        ]);
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
        return view('settings', app(SettingsService::class)->pageData());
    }

    public function updateSettings(Request $request)
    {
        $result = app(SettingsService::class)->update($request->all());

        if (!$result['success']) {
            return redirect('/settings')->with('error', $result['message']);
        }

        return redirect('/settings')->with('success', $result['message']);
    }

}
