<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>AI Trading Dashboard</title>
    <meta http-equiv="refresh" content="30">
    <link href="https://fonts.maateen.me/kalpurush/font.css" rel="stylesheet">

    <style>
        body {
            margin: 0;
            padding: 22px;
            font-family: 'Kalpurush', Arial, sans-serif;
            background: #020617;
            color: #e5e7eb;
        }

        .container { max-width: 1500px; margin: auto; }

        h1 { color: #38bdf8; margin: 0; font-size: 32px; }
        .subtitle { color: #94a3b8; margin-top: 6px; }

        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 24px 0;
        }

        .nav a {
            color: #38bdf8;
            text-decoration: none;
            font-weight: bold;
            background: #0f172a;
            border: 1px solid #334155;
            padding: 10px 15px;
            border-radius: 12px;
        }

        .topbar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 14px;
            margin-bottom: 28px;
        }

        .stat, .card, .empty {
            background: linear-gradient(180deg, #111827, #0f172a);
            border: 1px solid #334155;
            border-radius: 18px;
        }

        .stat { padding: 18px; }
        .stat span { display: block; color: #94a3b8; margin-bottom: 8px; }
        .stat strong { font-size: 30px; color: #e5e7eb; }

        .section-title {
            margin: 28px 0 15px;
            font-size: 23px;
            font-weight: bold;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 18px;
        }

        .card {
            padding: 20px;
            text-decoration: none;
            color: #e5e7eb;
            display: block;
            transition: .2s;
        }

        .card:hover {
            transform: translateY(-3px);
            border-color: #38bdf8;
        }

        .running-card {
            border: 2px solid #facc15;
            box-shadow: 0 0 25px rgba(250,204,21,.12);
        }

        .good {
            border: 2px solid #22c55e;
            box-shadow: 0 0 24px rgba(34,197,94,.12);
        }

        .buy { border-left: 7px solid #22c55e; }
        .sell { border-left: 7px solid #ef4444; }
        .neutral { border-left: 7px solid #64748b; }

        .symbol {
            font-size: 29px;
            font-weight: bold;
            color: #38bdf8;
            margin-bottom: 10px;
        }

        .badge {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 20px;
            font-weight: bold;
            margin: 0 6px 12px 0;
            font-size: 14px;
        }

        .badge-good { background: #064e3b; color: #6ee7b7; }
        .badge-wait { background: #334155; color: #cbd5e1; }
        .badge-running { background: #713f12; color: #fde68a; }
        .badge-win { background: #14532d; color: #bbf7d0; }
        .badge-loss { background: #7f1d1d; color: #fecaca; }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 7px 0;
            border-bottom: 1px solid rgba(51,65,85,.6);
            color: #cbd5e1;
        }

        .row b { color: #94a3b8; font-weight: normal; }
        .row strong { text-align: right; }

        .profit { color: #22c55e; }
        .loss-text { color: #ef4444; }

        .price {
            color: #94a3b8;
            font-size: 14px;
            margin-top: 13px;
            line-height: 1.6;
        }

        .empty {
            padding: 24px;
            color: #fde68a;
            margin-bottom: 20px;
        }

        @media(max-width:650px) {
            body { padding: 14px; }
            h1 { font-size: 25px; }
            .symbol { font-size: 24px; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>
<div class="container">

    <h1>AI Trading Dashboard</h1>
    <div class="subtitle">Multi-symbol M15 scanner • Rule Engine V2 • Telegram alerts</div>

    <div class="nav">
        <a href="/dashboard">Dashboard</a>
        <a href="/history">History</a>
        <a href="/signals">Signals</a>
        <a href="/stats">Stats</a>
        <a href="/performance">Performance</a>
        <a href="/settings">Settings</a>
    </div>

    @php
        $collection = collect($cards);

        $runningCards = $collection->filter(fn($c) =>
            $c['signal'] && ($c['signal']->result ?? null) === 'RUNNING'
        );

        $activeSetups = $collection->where('is_good', true);

        $buyCount = 0;
        $sellCount = 0;

        foreach ($cards as $c) {
            $plan = strtoupper($c['summary']['plan'] ?? ($c['signal']->action ?? ''));
            if (str_contains($plan, 'BUY')) $buyCount++;
            if (str_contains($plan, 'SELL')) $sellCount++;
        }
    @endphp

    <div class="topbar">
        <div class="stat"><span>Total Symbols</span><strong>{{ count($cards) }}</strong></div>
        <div class="stat"><span>Running Trades</span><strong>{{ $runningCards->count() }}</strong></div>
        <div class="stat"><span>A/A+ Setups</span><strong>{{ $activeSetups->count() }}</strong></div>
        <div class="stat"><span>Buy Setups</span><strong>{{ $buyCount }}</strong></div>
        <div class="stat"><span>Sell Setups</span><strong>{{ $sellCount }}</strong></div>
    </div>

    <div class="section-title">🔥 Active Trade Panel</div>

    @if($runningCards->count() > 0)
        <div class="grid">
            @foreach($runningCards as $card)
                @php
                    $symbol = $card['symbol'];
                    $latest = $card['latest'];
                    $signal = $card['signal'];
                    $action = $signal->action ?? 'N/A';
                    $pips = $card['running_pips'] ?? null;

                    $class = 'neutral';
                    if (str_contains(strtoupper($action), 'BUY')) $class = 'buy';
                    if (str_contains(strtoupper($action), 'SELL')) $class = 'sell';
                @endphp

                <a class="card running-card {{ $class }}" href="/dashboard/{{ urlencode($symbol) }}">
                    <div class="symbol">{{ $symbol }}</div>

                    <div class="badge badge-running">RUNNING</div>
                    <div class="badge badge-good">{{ $signal->grade ?? 'N/A' }}</div>

                    <div class="row"><b>Action</b><strong>{{ $action }}</strong></div>
                    <div class="row"><b>Entry</b><strong>{{ $signal->entry ?? $signal->entry_price ?? 'N/A' }}</strong></div>
                    <div class="row"><b>SL</b><strong>{{ $signal->sl ?? $signal->sl_price ?? 'N/A' }}</strong></div>
                    <div class="row"><b>TP1</b><strong>{{ $signal->tp1 ?? $signal->tp1_price ?? 'N/A' }}</strong></div>
                    <div class="row"><b>TP2</b><strong>{{ $signal->tp2 ?? $signal->tp2_price ?? 'N/A' }}</strong></div>

                    <div class="row">
                        <b>Live P/L</b>
                        <strong class="{{ ($pips ?? 0) >= 0 ? 'profit' : 'loss-text' }}">
                            {{ $pips !== null ? $pips : 'N/A' }}
                        </strong>
                    </div>

                    <div class="price">
                        Current Price: {{ $latest['price'] ?? 'N/A' }}<br>
                        Signal Time: {{ $signal->created_at ?? 'N/A' }}
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <div class="empty">এখন কোনো RUNNING trade নেই।</div>
    @endif

    <div class="section-title">📊 All Symbols</div>

    <div class="grid">
        @foreach($cards as $card)
            @php
                $symbol = $card['symbol'];
                $latest = $card['latest'];
                $signal = $card['signal'];
                $summary = $card['summary'];
                $isGood = $card['is_good'];

                $action = $summary['plan'] ?? ($signal->action ?? 'NO TRADE');
                $result = $signal->result ?? null;

                $class = 'neutral';
                if (str_contains(strtoupper($action), 'BUY')) $class = 'buy';
                if (str_contains(strtoupper($action), 'SELL')) $class = 'sell';

                $resultClass = 'badge-wait';
                if ($result === 'RUNNING') $resultClass = 'badge-running';
                if ($result === 'WIN' || $result === 'BIG_WIN') $resultClass = 'badge-win';
                if ($result === 'LOSS') $resultClass = 'badge-loss';
            @endphp

            <a class="card {{ $class }} {{ $isGood ? 'good' : '' }}" href="/dashboard/{{ urlencode($symbol) }}">
                <div class="symbol">{{ $symbol }}</div>

                @if($isGood)
                    <div class="badge badge-good">A/A+ Setup</div>
                @else
                    <div class="badge badge-wait">No A/A+ Setup</div>
                @endif

                @if($result)
                    <div class="badge {{ $resultClass }}">{{ $result }}</div>
                @endif

                <div class="row"><b>Action</b><strong>{{ $action }}</strong></div>
                <div class="row"><b>Grade</b><strong>{{ $summary['grade'] ?? ($signal->grade ?? 'N/A') }}</strong></div>
                <div class="row"><b>Entry</b><strong>{{ $summary['entry'] ?? ($signal->entry ?? 'N/A') }}</strong></div>
                <div class="row"><b>SL</b><strong>{{ $summary['sl'] ?? ($signal->sl ?? 'N/A') }}</strong></div>
                <div class="row"><b>TP1</b><strong>{{ $summary['tp1'] ?? ($signal->tp1 ?? 'N/A') }}</strong></div>
                <div class="row"><b>TP2</b><strong>{{ $summary['tp2'] ?? ($signal->tp2 ?? 'N/A') }}</strong></div>

                <div class="row">
                    <b>Win Rate</b>
                    <strong>{{ $card['win_rate'] ?? 0 }}%</strong>
                </div>

                <div class="price">
                    Price: {{ $latest['price'] ?? 'N/A' }}<br>
                    Time: {{ $latest['created_at'] ?? 'N/A' }}
                </div>
            </a>
        @endforeach
    </div>

</div>
</body>
</html>