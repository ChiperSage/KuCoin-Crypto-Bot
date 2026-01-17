<?php
// strategy_entry_1h.php
// PHP 5.6 — ENTRY STRATEGY VALID 1H

chdir(__DIR__);
date_default_timezone_set('Asia/Jakarta');
error_reporting(E_ALL);
ini_set('display_errors', 0);

/* =========================
   HELPER
========================= */
function getSelectedStrategy()
{
    if (!isset($_GET['strategy'])) return null;
    $s = strtoupper(trim($_GET['strategy']));
    $allowed = array('BREAKOUT', 'TREND_FOLLOW', 'BUY_DIP');
    return in_array($s, $allowed, true) ? $s : null;
}

/* =========================
   CORE
========================= */
class EntryAnalyzer1H
{
    private $pdo;

    public function __construct()
    {
        $this->pdo = new PDO(
            "mysql:host=localhost;dbname=db;charset=utf8",
            "user",
            "pass",
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
    }

    /* ===== EMA ===== */
    private function ema($prices, $p)
    {
        $k = 2 / ($p + 1);
        $ema = $prices[0];
        for ($i = 1; $i < count($prices); $i++) {
            $ema = ($prices[$i] * $k) + ($ema * (1 - $k));
        }
        return $ema;
    }

    /* ===== RSI ===== */
    private function rsi($prices, $p)
    {
        if (count($prices) < $p + 1) return null;

        $gain = $loss = 0;
        for ($i = 1; $i <= $p; $i++) {
            $d = $prices[$i] - $prices[$i - 1];
            $d > 0 ? $gain += $d : $loss += abs($d);
        }
        if ($loss == 0) return 100;

        $rs = ($gain / $p) / ($loss / $p);
        return 100 - (100 / (1 + $rs));
    }

    /* =========================
       MAIN ENTRY LOGIC
    ========================= */
    public function getReport($filter = null)
    {
        $symbols = $this->pdo
            ->query("SELECT DISTINCT symbol FROM kucoin_watch ORDER BY symbol")
            ->fetchAll(PDO::FETCH_COLUMN);

        $out = array();

        foreach ($symbols as $sym) {

            /* ===== BUILD 1H CANDLE (24 JAM) ===== */
            $stmt = $this->pdo->prepare("
                SELECT
                    MAX(created_at) AS t,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(last_price ORDER BY created_at DESC),
                        ',', 1
                    ) AS close_price,
                    SUM(volume) AS volume
                FROM kucoin_watch
                WHERE symbol = ?
                GROUP BY UNIX_TIMESTAMP(created_at) DIV 3600
                ORDER BY t DESC
                LIMIT 24
            ");
            $stmt->execute(array($sym));
            $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

            if (count($rows) < 20) continue;

            $prices = array_map('floatval', array_column($rows, 'close_price'));
            $vols   = array_map('floatval', array_column($rows, 'volume'));

            $price = end($prices);

            $ema12 = $this->ema(array_slice($prices, -12), 12);
            $ema26 = $this->ema(array_slice($prices, -26), 26);
            $rsi14 = $this->rsi($prices, 14);

            $avgVol = array_sum($vols) / count($vols);
            $volSpike = $avgVol > 0 ? end($vols) / $avgVol : 0;

            /* ===== STRATEGY DETECTION (1H) ===== */
            if ($volSpike >= 1.8 && $rsi14 !== null && $rsi14 >= 55) {
                $strategy = 'BREAKOUT';
            } elseif ($ema12 > $ema26 && $rsi14 >= 45) {
                $strategy = 'TREND_FOLLOW';
            } elseif ($rsi14 !== null && $rsi14 <= 40) {
                $strategy = 'BUY_DIP';
            } else {
                continue;
            }

            if ($filter && $strategy !== $filter) continue;

            /* ===== ENTRY VALIDATION ===== */
            $overpriced = $ema26 > 0
                ? (($price - $ema26) / $ema26) >= 0.05
                : false;

            $entry = false;

            if ($strategy === 'BREAKOUT')
                $entry = !$overpriced && $rsi14 >= 55;

            if ($strategy === 'TREND_FOLLOW') {
                $dist = abs(($price - $ema26) / $ema26);
                $entry = !$overpriced && $ema12 > $ema26 && $dist <= 0.03;
            }

            if ($strategy === 'BUY_DIP')
                $entry = $price <= $ema26 && $rsi14 >= 30 && $rsi14 <= 45;

            if (!$entry) continue;

            /* ===== OPTIONAL PRICE FILTER ===== */
            if ($price < 0.5 || $price > 20) continue;

            $out[] = array(
                'symbol'   => $sym,
                'price'    => number_format($price, 6),
                'strategy' => $strategy,
                'rsi'      => round($rsi14, 2),
                'entry'    => 'YA'
            );
        }

        return $out;
    }
}

/* =========================
   UI
========================= */
$filter = getSelectedStrategy();
$an = new EntryAnalyzer1H();
$data = $an->getReport($filter);

echo '<!doctype html><html><head><meta charset="utf-8">
<style>
body{font-family:Segoe UI,Arial;background:#f5f7fa;padding:20px}
table{width:100%;border-collapse:collapse;background:#fff;
box-shadow:0 6px 16px rgba(0,0,0,.08);border-radius:10px}
th{background:#eef2f6;padding:12px;font-size:13px;color:#555}
td{padding:12px;border-top:1px solid #eee}
tr:hover{background:#f9fbff}
.BREAKOUT{color:#16a085;font-weight:600}
.TREND_FOLLOW{color:#2980b9;font-weight:600}
.BUY_DIP{color:#c0392b;font-weight:600}
.YA{color:#27ae60;font-weight:bold}
.menu a{margin-right:10px;text-decoration:none;font-weight:600;color:#34495e}
.menu a:hover{text-decoration:underline}
</style>
</head><body>';

echo '<div class="menu">
<a href="?">ALL</a>
<a href="?strategy=breakout">BREAKOUT</a>
<a href="?strategy=trend_follow">TREND_FOLLOW</a>
<a href="?strategy=buy_dip">BUY_DIP</a>
</div><br>';

echo '<table>
<tr>
<th>Symbol</th>
<th>Price</th>
<th>Strategy</th>
<th>RSI (1H)</th>
<th>Entry</th>
</tr>';

foreach ($data as $r) {
    echo "<tr>
        <td><b>{$r['symbol']}</b></td>
        <td>{$r['price']}</td>
        <td class='{$r['strategy']}'>{$r['strategy']}</td>
        <td>{$r['rsi']}</td>
        <td class='YA'>YA</td>
    </tr>";
}

echo '</table></body></html>';
