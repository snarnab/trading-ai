<!DOCTYPE html>
<html>
<head>
    <title>Performance Analytics</title>

    <meta http-equiv="refresh" content="30">

    <style>
        body{
            background:#020617;
            color:white;
            font-family:Arial;
            padding:30px;
        }

        .card{
            background:#111827;
            padding:20px;
            border-radius:15px;
            margin-bottom:20px;
        }

        .grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
            gap:15px;
        }

        .box{
            background:#0f172a;
            padding:15px;
            border-radius:12px;
        }

        h1,h2{
            color:#38bdf8;
        }

        table{
            width:100%;
            border-collapse:collapse;
        }

        th,td{
            border:1px solid #334155;
            padding:10px;
        }

        th{
            background:#1e293b;
        }

        .green{
            color:#22c55e;
        }

        .red{
            color:#ef4444;
        }

        .yellow{
            color:#facc15;
        }
    </style>
</head>

<body>

<a href="/dashboard">← Dashboard</a>

<h1>Trading Performance Analytics</h1>

<div class="card">

    <div class="grid">

        <div class="box">
            <h3>Total Signals</h3>
            <h2>{{ $total }}</h2>
        </div>

        <div class="box">
            <h3>Running</h3>
            <h2 class="yellow">{{ $running }}</h2>
        </div>

        <div class="box">
            <h3>Wins</h3>
            <h2 class="green">{{ $wins }}</h2>
        </div>

        <div class="box">
            <h3>Big Wins</h3>
            <h2 class="green">{{ $bigWins }}</h2>
        </div>

        <div class="box">
            <h3>Losses</h3>
            <h2 class="red">{{ $losses }}</h2>
        </div>

        <div class="box">
            <h3>Win Rate</h3>
            <h2>{{ $winRate }}%</h2>
        </div>

    </div>

</div>

<div class="card">

    <h2>Symbol Performance Ranking</h2>

    <table>

        <tr>
            <th>Rank</th>
            <th>Symbol</th>
            <th>Wins</th>
            <th>Losses</th>
            <th>Total</th>
            <th>Win Rate</th>
        </tr>

        @foreach($performance as $index => $row)

        <tr>

            <td>#{{ $index + 1 }}</td>

            <td>{{ $row['symbol'] }}</td>

            <td class="green">{{ $row['wins'] }}</td>

            <td class="red">{{ $row['losses'] }}</td>

            <td>{{ $row['total'] }}</td>

            <td>
                <strong>{{ $row['win_rate'] }}%</strong>
            </td>

        </tr>

        @endforeach

    </table>

</div>

</body>
</html>