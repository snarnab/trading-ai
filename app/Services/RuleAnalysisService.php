<?php

namespace App\Services;

class RuleAnalysisService
{
    public function analyze(array $data): string
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
        $rrText = 'N/A';;
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

}