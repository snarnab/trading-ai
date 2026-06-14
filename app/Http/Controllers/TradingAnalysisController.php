<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TradeSignal;
use App\Models\SymbolSetting;
use App\Services\PatternAlertService;
use App\Services\TelegramService;
use App\Services\RuleAnalysisService;
use App\Services\TradeSignalService;
use App\Services\SettingsService;
use App\Services\AnalysisStorageService;
use App\Services\GeminiAnalysisService;

class TradingAnalysisController extends Controller
{
    public function analyze(Request $request)
    {
        $data = $request->all();

        $symbol = $data['symbol'] ?? null;

        if (!$symbol) {
            return response()->json([
                'success' => false,
                'message' => 'Symbol missing',
                'received' => $data,
            ], 422);
        }

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

        $storage = app(AnalysisStorageService::class);
        $tradeSignals = app(TradeSignalService::class);
        $telegram = app(TelegramService::class);

        $candleKey = $storage->getSignalCandleKey($data);

        $tradeSignals->updateRunningResults($data, function ($signal, $result, $closedPrice) use ($telegram) {
            $telegram->sendCloseAlert($signal, $result, $closedPrice);
        });

        $patternAlerts = app(PatternAlertService::class)->process($data, function ($message) use ($telegram) {
            $telegram->sendShortMessage($message);
        });

        $ruleAnalysis = app(RuleAnalysisService::class)->analyze($data);

        if (!$tradeSignals->isGoodSetup($ruleAnalysis)) {
            $record = $storage->makeRecord($data, $ruleAnalysis);

            $storage->saveLatest($record);
            $storage->saveHistory($record);

            return response()->json([
                'success' => true,
                'source' => 'rule_engine',
                'analysis' => $ruleAnalysis,
                'pattern_alerts' => $patternAlerts,
            ]);
        }

        $geminiResult = app(GeminiAnalysisService::class)->analyze($data, $ruleAnalysis);

        if (!$geminiResult['success']) {
            $analysis = $ruleAnalysis . "\n\nNote: Gemini quota/error এর কারণে Rule Engine analysis ব্যবহার করা হয়েছে।";

            $record = $storage->makeRecord($data, $analysis);

            $storage->saveLatest($record);
            $storage->saveHistory($record);

            $shouldAlert = $tradeSignals->saveIfGood($analysis, $data, $candleKey);

            if ($shouldAlert) {
                $summary = $tradeSignals->extractSignalSummary($analysis);
                $telegram->sendSignal($record, $summary);
            }

            return response()->json([
                'success' => true,
                'source' => 'rule_engine_fallback',
                'analysis' => $analysis,
                'gemini_status' => $geminiResult['status'],
                'pattern_alerts' => $patternAlerts,
            ]);
        }

        $analysis = $geminiResult['analysis'];

        if (!$analysis) {
            $analysis = $ruleAnalysis . "\n\nNote: Gemini থেকে কোনো valid analysis পাওয়া যায়নি, তাই Rule Engine analysis ব্যবহার করা হয়েছে।";
        }

        $record = $storage->makeRecord($data, $analysis);

        $storage->saveLatest($record);
        $storage->saveHistory($record);

        $shouldAlert = $tradeSignals->saveIfGood($analysis, $data, $candleKey);

        if ($shouldAlert && $tradeSignals->isGoodSetup($analysis)) {
            $summary = $tradeSignals->extractSignalSummary($analysis);
            $telegram->sendSignal($record, $summary);
        }

        return response()->json([
            'success' => true,
            'source' => 'gemini_confirmed',
            'analysis' => $analysis,
            'pattern_alerts' => $patternAlerts,
        ]);
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
                    'candles' => [
                        ['time' => '2026-06-15 01:00', 'open' => 4300, 'high' => 4310, 'low' => 4290, 'close' => 4295],
                        ['time' => '2026-06-15 02:00', 'open' => 4296, 'high' => 4300, 'low' => 4280, 'close' => 4285],
                        ['time' => '2026-06-15 03:00', 'open' => 4284, 'high' => 4320, 'low' => 4280, 'close' => 4315],
                    ],
                ],
                'H4' => [
                    'trend' => 'bearish',
                    'ema50' => 4400,
                    'ema200' => 4500,
                    'rsi14' => 34,
                    'atr14' => 22,
                    'swing_high_30' => 4450,
                    'swing_low_30' => 4210,
                    'candles' => [
                        ['time' => '2026-06-15 01:00', 'open' => 4300, 'high' => 4310, 'low' => 4290, 'close' => 4295],
                        ['time' => '2026-06-15 02:00', 'open' => 4296, 'high' => 4300, 'low' => 4280, 'close' => 4285],
                        ['time' => '2026-06-15 03:00', 'open' => 4284, 'high' => 4320, 'low' => 4280, 'close' => 4315],
                    ],
                ],
                'H1' => [
                    'trend' => 'bearish',
                    'ema50' => 4330,
                    'ema200' => 4420,
                    'rsi14' => 38,
                    'atr14' => 18,
                    'swing_high_30' => 4350,
                    'swing_low_30' => 4240,
                    'candles' => [
                        ['time' => '2026-06-15 01:00', 'open' => 4300, 'high' => 4310, 'low' => 4290, 'close' => 4295],
                        ['time' => '2026-06-15 02:00', 'open' => 4296, 'high' => 4300, 'low' => 4280, 'close' => 4285],
                        ['time' => '2026-06-15 03:00', 'open' => 4284, 'high' => 4320, 'low' => 4280, 'close' => 4315],
                    ],
                ],
                'M15' => [
                    'trend' => 'bearish',
                    'ema50' => 4280,
                    'ema200' => 4310,
                    'rsi14' => 45,
                    'atr14' => 8,
                    'swing_high_30' => 4295,
                    'swing_low_30' => 4255,
                    'candles' => [
                        ['time' => '2026-06-15 01:00', 'open' => 4300, 'high' => 4310, 'low' => 4290, 'close' => 4295],
                        ['time' => '2026-06-15 02:00', 'open' => 4296, 'high' => 4300, 'low' => 4280, 'close' => 4285],
                        ['time' => '2026-06-15 03:00', 'open' => 4284, 'high' => 4320, 'low' => 4280, 'close' => 4315],
                    ],
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
        $tradeSignals = app(TradeSignalService::class);

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
                    ? $tradeSignals->isGoodSetup($latest['analysis'])
                    : false,
                'summary' => $latest && isset($latest['analysis'])
                    ? $tradeSignals->extractSignalSummary($latest['analysis'])
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
                'message' => 'No chart uploaded',
            ], 422);
        }

        $symbol = strtoupper($request->input('symbol', 'XAUUSD'));
        $symbol = str_replace(['/', '\\', ' '], '_', $symbol);

        $timeframe = strtolower($request->input('timeframe', 'm15'));

        if (!in_array($timeframe, ['d1', 'h4', 'h1', 'm15', 'm15_signal'])) {
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

        app(AnalysisStorageService::class)->saveLatest($record);
        app(AnalysisStorageService::class)->saveHistory($record);

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

    public function symbolDashboard($symbol)
    {
        $symbol = urldecode($symbol);
        $latestFile = storage_path("app/latest_analysis_{$symbol}.json");

        $analysis = null;
        $summary = [];

        if (file_exists($latestFile)) {
            $analysis = json_decode(file_get_contents($latestFile), true);

            if ($analysis && isset($analysis['analysis'])) {
                $summary = app(TradeSignalService::class)
                    ->extractSignalSummary($analysis['analysis']);
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

        app(TelegramService::class)->sendSignal($record, $summary);

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