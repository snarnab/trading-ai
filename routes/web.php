<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\TradingAnalysisController;

Route::get('/mt5/test', function () {
    return response()->json([
        'status' => 'API working'
    ]);
});

Route::post('/mt5/analyze', [TradingAnalysisController::class, 'analyze'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::post('/mt5/upload-chart', [TradingAnalysisController::class, 'uploadChart'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/mt5/latest-signal', [TradingAnalysisController::class, 'latestSignal']);

Route::get('/dashboard', [TradingAnalysisController::class, 'dashboard']);
Route::get('/history', [TradingAnalysisController::class, 'history']);
Route::get('/signals', [TradingAnalysisController::class, 'signals']);
Route::get('/stats', [TradingAnalysisController::class, 'stats']);
Route::get('/performance', [TradingAnalysisController::class, 'performance']);

Route::get('/analyze-test', [TradingAnalysisController::class, 'testAnalyze']);
Route::get('/test-save', [TradingAnalysisController::class, 'testSave']);

Route::get('/gemini-test', function () {
    $response = Http::withoutVerifying()
        ->post(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . env('GEMINI_API_KEY'),
            [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => 'Reply only with Gemini Connected Successfully'
                            ]
                        ]
                    ]
                ]
            ]
        );

    return $response->json();
});

Route::get('/models', function () {
    $response = Http::withoutVerifying()->get(
        'https://generativelanguage.googleapis.com/v1beta/models?key=' . env('GEMINI_API_KEY')
    );

    return $response->json();
});

Route::get('/telegram-test', function () {
    Http::withoutVerifying()->post(
        'https://api.telegram.org/bot' . env('TELEGRAM_BOT_TOKEN') . '/sendMessage',
        [
            'chat_id' => env('TELEGRAM_CHAT_ID'),
            'text' => '✅ MT5 AI Telegram Connected Successfully'
        ]
    );

    return 'Message Sent';
});


Route::get('/', function () {
    return redirect('/dashboard');
});

Route::get('/dashboard', [TradingAnalysisController::class,'dashboard']);
Route::get('/dashboard/{symbol}', [TradingAnalysisController::class,'symbolDashboard']);

Route::get('/history', [TradingAnalysisController::class,'history']);
Route::get('/signals', [TradingAnalysisController::class,'signals']);
Route::get('/performance', [TradingAnalysisController::class,'performance']);
Route::get('/stats', [TradingAnalysisController::class,'stats']);
Route::get('/', fn () => redirect('/dashboard'));

Route::post('/mt5/analyze', [TradingAnalysisController::class, 'analyze'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::post('/mt5/upload-chart', [TradingAnalysisController::class, 'uploadChart'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/mt5/latest-signal', [TradingAnalysisController::class, 'latestSignal']);

Route::get('/dashboard', [TradingAnalysisController::class, 'dashboard']);
Route::get('/dashboard/{symbol}', [TradingAnalysisController::class, 'symbolDashboard']);

Route::get('/history', [TradingAnalysisController::class, 'history']);
Route::get('/signals', [TradingAnalysisController::class, 'signals']);
Route::get('/stats', [TradingAnalysisController::class, 'stats']);
Route::get('/performance', [TradingAnalysisController::class, 'performance']);



Route::get('/telegram-chart-test/{symbol}', [TradingAnalysisController::class, 'telegramChartTest']);
Route::get('/settings', [TradingAnalysisController::class, 'settings']);
Route::post('/settings', [TradingAnalysisController::class, 'updateSettings']);


