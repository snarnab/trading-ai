<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Signal Performance</title>
    <link href="https://fonts.maateen.me/kalpurush/font.css" rel="stylesheet">
    <style>
        body { font-family:'Kalpurush', Arial; background:#0f172a; color:#e5e7eb; padding:30px; }
        .container { max-width:1100px; margin:auto; }
        .card { background:#111827; border:1px solid #334155; border-radius:12px; padding:18px; margin-bottom:15px; }
        a { color:#38bdf8; margin-right:15px; text-decoration:none; }
    </style>
</head>
<body>
<div class="container">
    <h1>Signal Performance</h1>

    <p>
        <a href="/dashboard">Dashboard</a>
        <a href="/signals">Signals</a>
        <a href="/stats">Stats</a>
    </p>

    @forelse($signals as $signal)
        <div class="card">
            <b>{{ $signal->symbol }}</b> |
            Grade: {{ $signal->grade ?? 'N/A' }} |
            Direction: {{ $signal->direction ?? 'N/A' }} |
            Entry: {{ $signal->entry_price ?? 'N/A' }} |
            SL: {{ $signal->sl_price ?? 'N/A' }} |
            TP1: {{ $signal->tp1_price ?? 'N/A' }} |
            Result: {{ $signal->result ?? 'RUNNING' }}
        </div>
    @empty
        <p>এখনো কোনো signal নেই।</p>
    @endforelse
</div>
</body>
</html>