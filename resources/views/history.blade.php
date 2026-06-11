<!DOCTYPE html>
<html>
<head>
    <title>MT5 Analysis History</title>
    <meta charset="UTF-8">

    <link href="https://fonts.maateen.me/kalpurush/font.css" rel="stylesheet">

    <style>
        body {
            font-family: 'Kalpurush', 'Noto Sans Bengali', Arial, sans-serif;
            background: #0f172a;
            color: #e5e7eb;
            padding: 30px;
        }

        .container {
            max-width: 1000px;
            margin: auto;
        }

        .card {
            background: #111827;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        h1 {
            color: #38bdf8;
        }

        .meta {
            color: #94a3b8;
            margin-bottom: 12px;
        }

        pre {
            background: #020617;
            padding: 15px;
            border-radius: 10px;
            white-space: pre-wrap;
            line-height: 1.6;
        }

        a {
            color: #38bdf8;
            text-decoration: none;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>MT5 AI Analysis History</h1>

    <p>
        <a href="/dashboard">← Dashboard এ ফিরে যান</a>
    </p>

    @forelse($history as $item)
        <div class="card">
            <div class="meta">
                <strong>Symbol:</strong> {{ $item['symbol'] ?? 'N/A' }} |
                <strong>Price:</strong> {{ $item['price'] ?? 'N/A' }} |
                <strong>Spread:</strong> {{ $item['spread'] ?? 'N/A' }} |
                <strong>Time:</strong> {{ $item['created_at'] ?? 'N/A' }}
            </div>

            <pre>{{ $item['analysis'] ?? '' }}</pre>
        </div>
    @empty
        <p>এখনো কোনো analysis history নেই।</p>
    @endforelse
</div>

</body>
</html>