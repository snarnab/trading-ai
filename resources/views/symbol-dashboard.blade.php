<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>{{ $symbol }} Analysis</title>
    <meta http-equiv="refresh" content="30">
    <link href="https://fonts.maateen.me/kalpurush/font.css" rel="stylesheet">

    <style>
        body {
            margin:0;
            padding:22px;
            font-family:'Kalpurush', Arial, sans-serif;
            background:#020617;
            color:#e5e7eb;
        }

        .container { max-width:1450px; margin:auto; }

        .nav {
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-bottom:22px;
        }

        .nav a {
            color:#38bdf8;
            text-decoration:none;
            font-weight:bold;
            background:#0f172a;
            border:1px solid #334155;
            padding:10px 14px;
            border-radius:10px;
        }

        h1,h2 { color:#38bdf8; margin-top:0; }

        .card {
            background:linear-gradient(180deg,#111827,#0f172a);
            border:1px solid #334155;
            border-radius:16px;
            padding:22px;
            margin-bottom:20px;
        }

        .summary {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(170px,1fr));
            gap:12px;
            margin-bottom:18px;
        }

        .item {
            background:#020617;
            border:1px solid #334155;
            border-radius:12px;
            padding:13px;
        }

        .item span {
            display:block;
            color:#94a3b8;
            margin-bottom:6px;
            font-size:14px;
        }

        .item strong {
            font-size:20px;
            color:#e5e7eb;
        }

        .badge {
            display:inline-block;
            padding:7px 12px;
            border-radius:20px;
            font-weight:bold;
            margin:0 6px 10px 0;
        }

        .running { background:#713f12; color:#fde68a; }
        .win { background:#14532d; color:#bbf7d0; }
        .loss { background:#7f1d1d; color:#fecaca; }
        .buy { background:#064e3b; color:#6ee7b7; }
        .sell { background:#7f1d1d; color:#fecaca; }

        .charts-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(420px,1fr));
            gap:18px;
        }

        img {
            width:100%;
            border-radius:14px;
            border:1px solid #334155;
            background:#020617;
        }

        pre {
            background:#020617;
            border:1px solid #1e293b;
            border-radius:12px;
            padding:18px;
            white-space:pre-wrap;
            line-height:1.7;
            max-height:520px;
            overflow:auto;
        }

        table {
            width:100%;
            border-collapse:collapse;
            background:#020617;
            font-size:15px;
        }

        th,td {
            padding:10px;
            border:1px solid #334155;
            text-align:left;
        }

        th { color:#38bdf8; }

        .table-wrap { overflow-x:auto; }

        .empty {
            color:#fde68a;
            background:#020617;
            border:1px solid #334155;
            border-radius:12px;
            padding:16px;
        }

        @media(max-width:650px) {
            body { padding:14px; }
            h1 { font-size:25px; }
            .charts-grid { grid-template-columns:1fr; }
        }
    </style>
</head>

<body>
<div class="container">

    <div class="nav">
        <a href="/dashboard">← Dashboard</a>
        <a href="/signals">Signals</a>
        <a href="/stats">Stats</a>
        <a href="/performance">Performance</a>
    </div>

    <h1>{{ $symbol }} Full Analysis</h1>

    @php
        $running = $signals->where('result', 'RUNNING')->first();
        $wins = $signals->whereIn('result', ['WIN', 'BIG_WIN'])->count();
        $losses = $signals->where('result', 'LOSS')->count();
        $closed = $wins + $losses;
        $winRate = $closed > 0 ? round(($wins / $closed) * 100, 2) : 0;
    @endphp

    <div class="card">
        <h2>Current Running Trade</h2>

        @if($running)
            @php
                $actionClass = strtolower($running->action ?? '') === 'buy' ? 'buy' : 'sell';
            @endphp

            <div class="badge running">RUNNING</div>
            <div class="badge {{ $actionClass }}">{{ $running->action }}</div>
            <div class="badge">{{ $running->grade ?? 'N/A' }}</div>

            <div class="summary">
                <div class="item"><span>Entry</span><strong>{{ $running->entry ?? $running->entry_price ?? 'N/A' }}</strong></div>
                <div class="item"><span>SL</span><strong>{{ $running->sl ?? $running->sl_price ?? 'N/A' }}</strong></div>
                <div class="item"><span>TP1</span><strong>{{ $running->tp1 ?? $running->tp1_price ?? 'N/A' }}</strong></div>
                <div class="item"><span>TP2</span><strong>{{ $running->tp2 ?? $running->tp2_price ?? 'N/A' }}</strong></div>
                <div class="item"><span>Opened At</span><strong>{{ $running->created_at }}</strong></div>
            </div>
        @else
            <div class="empty">এই symbol এর কোনো RUNNING trade নেই।</div>
        @endif
    </div>

    <div class="card">
        <h2>Latest Market Snapshot</h2>

        @if($analysis)
            <div class="summary">
                <div class="item"><span>Price</span><strong>{{ $analysis['price'] ?? 'N/A' }}</strong></div>
                <div class="item"><span>Spread</span><strong>{{ $analysis['spread'] ?? 'N/A' }}</strong></div>
                <div class="item"><span>Last Update</span><strong>{{ $analysis['created_at'] ?? 'N/A' }}</strong></div>
                <div class="item"><span>Plan</span><strong>{{ $summary['plan'] ?? 'N/A' }}</strong></div>
                <div class="item"><span>Grade</span><strong>{{ $summary['grade'] ?? 'N/A' }}</strong></div>
                <div class="item"><span>Entry</span><strong>{{ $summary['entry'] ?? 'N/A' }}</strong></div>
                <div class="item"><span>SL</span><strong>{{ $summary['sl'] ?? 'N/A' }}</strong></div>
                <div class="item"><span>TP1</span><strong>{{ $summary['tp1'] ?? 'N/A' }}</strong></div>
                <div class="item"><span>TP2</span><strong>{{ $summary['tp2'] ?? 'N/A' }}</strong></div>
            </div>
        @else
            <div class="empty">এই symbol এর কোনো latest analysis পাওয়া যায়নি।</div>
        @endif
    </div>

    <div class="card">
        <h2>Signal Chart</h2>

        @if(file_exists(public_path('charts/' . strtoupper($symbol) . '_m15_signal.png')))
            <img src="/charts/{{ strtoupper($symbol) }}_m15_signal.png?{{ time() }}">
        @else
            <div class="empty">Signal chart এখনো নেই।</div>
        @endif
    </div>

    <div class="card">
        <h2>H4 / H1 / M15 Charts</h2>

        <div class="charts-grid">
            <div>
                <h2>H4</h2>
                @if(file_exists(public_path('charts/' . strtoupper($symbol) . '_h4.png')))
                    <img src="/charts/{{ strtoupper($symbol) }}_h4.png?{{ time() }}">
                @else
                    <div class="empty">H4 chart নেই।</div>
                @endif
            </div>

            <div>
                <h2>H1</h2>
                @if(file_exists(public_path('charts/' . strtoupper($symbol) . '_h1.png')))
                    <img src="/charts/{{ strtoupper($symbol) }}_h1.png?{{ time() }}">
                @else
                    <div class="empty">H1 chart নেই।</div>
                @endif
            </div>

            <div>
                <h2>M15</h2>
                @if(file_exists(public_path('charts/' . strtoupper($symbol) . '_m15.png')))
                    <img src="/charts/{{ strtoupper($symbol) }}_m15.png?{{ time() }}">
                @else
                    <div class="empty">M15 chart নেই।</div>
                @endif
            </div>
        </div>
    </div>

    <div class="card">
        <h2>AI / Rule Engine Analysis</h2>

        @if($analysis)
            <pre>{{ $analysis['analysis'] }}</pre>
        @else
            <div class="empty">Analysis নেই।</div>
        @endif
    </div>

    <div class="card">
        <h2>Symbol Performance</h2>

        <div class="summary">
            <div class="item"><span>Total Signals</span><strong>{{ $signals->count() }}</strong></div>
            <div class="item"><span>Wins</span><strong>{{ $wins }}</strong></div>
            <div class="item"><span>Losses</span><strong>{{ $losses }}</strong></div>
            <div class="item"><span>Win Rate</span><strong>{{ $winRate }}%</strong></div>
        </div>
    </div>

    <div class="card">
        <h2>Last 20 Signals</h2>

        <div class="table-wrap">
            <table>
                <tr>
                    <th>Time</th>
                    <th>Action</th>
                    <th>Grade</th>
                    <th>Entry</th>
                    <th>SL</th>
                    <th>TP1</th>
                    <th>TP2</th>
                    <th>Result</th>
                    <th>Closed</th>
                </tr>

                @forelse($signals as $signal)
                    <tr>
                        <td>{{ $signal->created_at }}</td>
                        <td>{{ $signal->action ?? 'N/A' }}</td>
                        <td>{{ $signal->grade ?? 'N/A' }}</td>
                        <td>{{ $signal->entry ?? 'N/A' }}</td>
                        <td>{{ $signal->sl ?? 'N/A' }}</td>
                        <td>{{ $signal->tp1 ?? 'N/A' }}</td>
                        <td>{{ $signal->tp2 ?? 'N/A' }}</td>
                        <td>{{ $signal->result ?? 'RUNNING' }}</td>
                        <td>{{ $signal->closed_at ?? 'N/A' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">কোনো signal নেই।</td>
                    </tr>
                @endforelse
            </table>
        </div>
    </div>

</div>
</body>
</html>