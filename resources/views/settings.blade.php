<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Symbol Settings</title>

    <style>
        body {
            background:#020617;
            color:#e5e7eb;
            font-family:Arial;
            padding:30px;
        }

        .container {
            max-width:900px;
            margin:auto;
        }

        h1 {
            color:#38bdf8;
        }

        a {
            color:#38bdf8;
            text-decoration:none;
            margin-right:15px;
            font-weight:bold;
        }

        .card {
            background:#111827;
            border:1px solid #334155;
            border-radius:16px;
            padding:22px;
        }

        table {
            width:100%;
            border-collapse:collapse;
            margin-top:20px;
        }

        th, td {
            border:1px solid #334155;
            padding:14px;
            text-align:center;
        }

        th {
            background:#0f172a;
            color:#38bdf8;
        }

        .symbol {
            text-align:left;
            font-weight:bold;
            font-size:18px;
        }

        input[type="checkbox"] {
            transform:scale(1.5);
        }

        button {
            margin-top:20px;
            background:#22c55e;
            color:#052e16;
            border:0;
            padding:12px 22px;
            border-radius:10px;
            font-weight:bold;
            cursor:pointer;
        }

        .success {
            background:#064e3b;
            color:#6ee7b7;
            padding:12px;
            border-radius:10px;
            margin-bottom:15px;
        }
    </style>
</head>
<body>
<div class="container">

    <p>
        <a href="/dashboard">← Dashboard</a>
        <a href="/signals">Signals</a>
        <a href="/stats">Stats</a>
    </p>

    <h1>Symbol Control Panel</h1>

    <div class="card">

        @if(session('success'))
            <div class="success">{{ session('success') }}</div>
        @endif

        <form method="POST" action="/settings">
            @csrf

            <table>
                <tr>
                    <th>Symbol</th>
                    <th>Signal</th>
                    <th>Auto Trade</th>
                </tr>

                @foreach($settings as $setting)
                    <tr>
                        <td class="symbol">{{ $setting->symbol }}</td>

                        <td>
                            <input
                                type="checkbox"
                                name="settings[{{ $setting->symbol }}][signal_enabled]"
                                {{ $setting->signal_enabled ? 'checked' : '' }}
                            >
                        </td>

                        <td>
                            <input
                                type="checkbox"
                                name="settings[{{ $setting->symbol }}][trade_enabled]"
                                {{ $setting->trade_enabled ? 'checked' : '' }}
                            >
                        </td>
                    </tr>
                @endforeach
            </table>

            <button type="submit">Save Settings</button>
        </form>
    </div>
</div>
</body>
</html>