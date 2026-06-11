<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Trade Signals</title>
    <link href="https://fonts.maateen.me/kalpurush/font.css" rel="stylesheet">
    <style>
        body { font-family: 'Kalpurush', Arial, sans-serif; background:#0f172a; color:#e5e7eb; padding:30px; }
        .container { max-width:1100px; margin:auto; }
        .card { background:#111827; border:1px solid #334155; border-radius:12px; padding:20px; margin-bottom:15px; }
        a { color:#38bdf8; margin-right:15px; text-decoration:none; }
        pre { white-space:pre-wrap; background:#020617; padding:15px; border-radius:10px; }
    </style>
</head>
<body>
<div class="container">
    <h1>A/A+ Trade Signals</h1>

    <p>
        <a href="/dashboard">Dashboard</a>
        <a href="/history">History</a>
        <a href="/stats">Stats</a>
    </p>

    @forelse($signals as $signal)
        <div class="card">
            <strong>{{ $signal->symbol }}</strong> |
            Grade: {{ $signal->grade ?? 'N/A' }} |
            Bias: {{ $signal->bias ?? 'N/A' }} |
            Entry: {{ $signal->entry ?? 'N/A' }} |
            SL: {{ $signal->sl ?? 'N/A' }} |
            TP1: {{ $signal->tp1 ?? 'N/A' }} |
            TP2: {{ $signal->tp2 ?? 'N/A' }} |
            Time: {{ $signal->created_at }}

            <pre>{{ $signal->analysis }}</pre>
        </div>
    @empty
        <p>এখনো কোনো A/A+ signal save হয়নি।</p>
    @endforelse
</div>
</body>
</html>