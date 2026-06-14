<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Alert Settings</title>

    <style>
        body { background:#020617; color:#e5e7eb; font-family:Arial; padding:30px; }
        .container { max-width:1150px; margin:auto; }
        h1, h2 { color:#38bdf8; }
        a { color:#38bdf8; text-decoration:none; margin-right:15px; font-weight:bold; }
        .grid { display:grid; grid-template-columns:1fr; gap:22px; }
        .card { background:#111827; border:1px solid #334155; border-radius:16px; padding:22px; }
        table { width:100%; border-collapse:collapse; margin-top:15px; }
        th, td { border:1px solid #334155; padding:12px; text-align:center; }
        th { background:#0f172a; color:#38bdf8; }
        .left { text-align:left; }
        input[type="checkbox"] { transform:scale(1.4); }
        input[type="text"] { width:95%; background:#020617; color:#e5e7eb; border:1px solid #334155; border-radius:8px; padding:9px; }
        button { margin-top:20px; background:#22c55e; color:#052e16; border:0; padding:13px 24px; border-radius:10px; font-weight:bold; cursor:pointer; }
        .success { background:#064e3b; color:#6ee7b7; padding:12px; border-radius:10px; margin-bottom:15px; }
        .error { background:#7f1d1d; color:#fecaca; padding:12px; border-radius:10px; margin-bottom:15px; }
        .hint { color:#94a3b8; font-size:14px; }
        .code { font-weight:bold; color:#facc15; }
    </style>
</head>
<body>
<div class="container">

    <p>
        <a href="/dashboard">← Dashboard</a>
        <a href="/signals">Signals</a>
        <a href="/stats">Stats</a>
    </p>

    <h1>Alert Control Panel</h1>

    @if(session('success'))
        <div class="success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="error">{{ session('error') }}</div>
    @endif

    <form method="POST" action="/settings">
        @csrf

        <div class="grid">
            <div class="card">
                <h2>1. Pair / Symbol Control</h2>
                <p class="hint">Signal off করলে ওই pair থেকে Telegram alert যাবে না। Auto Trade আলাদা।</p>

                <table>
                    <tr>
                        <th>Symbol</th>
                        <th>Telegram Signal</th>
                        <th>Auto Trade</th>
                    </tr>
                    @foreach($settings as $setting)
                        <tr>
                            <td class="left"><strong>{{ $setting->symbol }}</strong></td>
                            <td>
                                <input type="checkbox" name="settings[{{ $setting->symbol }}][signal_enabled]" {{ $setting->signal_enabled ? 'checked' : '' }}>
                            </td>
                            <td>
                                <input type="checkbox" name="settings[{{ $setting->symbol }}][trade_enabled]" {{ $setting->trade_enabled ? 'checked' : '' }}>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            <div class="card">
                <h2>2. Timeframe Alert</h2>
                <p class="hint">Maximum 5 ta timeframe select kora jabe। EA currently D1, H4, H1, M15 data pathachhe। M30/W1 use korte hole EA teo add korte hobe।</p>

                <table>
                    <tr>
                        <th>Timeframe</th>
                        <th>Alert On/Off</th>
                    </tr>
                    @foreach($timeframes as $timeframe)
                        <tr>
                            <td class="left"><strong>{{ $timeframe->name }}</strong></td>
                            <td>
                                <input type="checkbox" name="timeframes[]" value="{{ $timeframe->name }}" {{ $timeframe->is_enabled ? 'checked' : '' }}>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            <div class="card">
                <h2>3. Signal Pattern Names</h2>
                <p class="hint">System code same thakbe। Custom name dile Telegram-e custom name jabe। Blank rakhle default name jabe।</p>

                <table>
                    <tr>
                        <th>On/Off</th>
                        <th>Code</th>
                        <th>Direction</th>
                        <th>Default Name</th>
                        <th>Custom Name</th>
                    </tr>
                    @foreach($patterns as $pattern)
                        <tr>
                            <td>
                                <input type="checkbox" name="patterns[{{ $pattern->code }}][is_enabled]" {{ $pattern->is_enabled ? 'checked' : '' }}>
                            </td>
                            <td class="code">{{ $pattern->code }}</td>
                            <td>{{ $pattern->direction }}</td>
                            <td class="left">{{ $pattern->system_name }}</td>
                            <td>
                                <input type="text" name="patterns[{{ $pattern->code }}][custom_name]" value="{{ $pattern->custom_name }}" placeholder="Your custom signal name">
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>

        <button type="submit">Save Settings</button>
    </form>
</div>
</body>
</html>
