<?php
// php 5.6

/* ================== BOOTSTRAP ================== */
chdir(__DIR__);

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/kucoin_insert.log');
error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');
set_time_limit(120);
ini_set('memory_limit', '512M');

/* ================== CONFIG ================== */
$config = array(
    'db_host'    => 'localhost',
    'db_name'    => 'your_db',
    'db_user'    => 'your_user',
    'db_pass'    => 'your_pass',
    'api_url'    => 'https://api.kucoin.com/api/v1/market/allTickers',
    'min_price'  => 0.001,
    'max_price'  => 5,
    'min_volume' => 100000,
    'max_volume' => 50000000,
    'watch_list' => array('*USDT'),
);

/* ================== DB CONNECT ================== */
try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        )
    );
} catch (PDOException $e) {
    error_log("[DB ERROR] ".$e->getMessage());
    exit;
}

/* ================== HELPER ================== */
function reject($symbol, $reason) {
    error_log("[REJECT] {$symbol} | {$reason}");
}

/* ================== FETCH API ================== */
$ch = curl_init($config['api_url']);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_ENCODING => '',
));

$response = curl_exec($ch);
if ($response === false) {
    error_log("[CURL ERROR] ".curl_error($ch));
    exit;
}
curl_close($ch);

$data = json_decode($response, true);
$tickers = $data['data']['ticker'] ?? array();
if (!$tickers) {
    error_log("[API] empty ticker");
    exit;
}

/* ================== SYMBOL FILTER ================== */
$patterns = array();
foreach ($config['watch_list'] as $p) {
    $patterns[] = '/^' . str_replace('\*', '.*', preg_quote(strtoupper($p), '/')) . '$/';
}

/* ================== PREPARE INSERT ================== */
$stmt = $pdo->prepare("
    INSERT INTO kucoin_watch
    (symbol, last_price, buy_price, sell_price, high_price, low_price, volume, volume_value, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");

/* ================== PROCESS ================== */
$inserted = 0;
$rejected = 0;
$now = date('Y-m-d H:i:s');

foreach ($tickers as $t) {

    $symbol = strtoupper($t['symbol'] ?? '');
    if (!$symbol) continue;

    // watch list
    $ok = false;
    foreach ($patterns as $pt) {
        if (preg_match($pt, $symbol)) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        reject($symbol, 'not watchlist');
        $rejected++;
        continue;
    }

    $last     = (float) ($t['last'] ?? 0);
    $volValue = (float) ($t['volValue'] ?? 0);

    if ($last < $config['min_price']) {
        reject($symbol, 'price < min');
        $rejected++;
        continue;
    }
    if ($last > $config['max_price']) {
        reject($symbol, 'price > max');
        $rejected++;
        continue;
    }
    if ($volValue < $config['min_volume']) {
        reject($symbol, 'volume < min');
        $rejected++;
        continue;
    }
    if ($volValue > $config['max_volume']) {
        reject($symbol, 'volume > max');
        $rejected++;
        continue;
    }

    try {
        $stmt->execute(array(
            $symbol,
            $last,
            (float) ($t['buy'] ?? 0),
            (float) ($t['sell'] ?? 0),
            (float) ($t['high'] ?? 0),
            (float) ($t['low'] ?? 0),
            (float) ($t['vol'] ?? 0),
            $volValue,
            $now
        ));
        $inserted++;
    } catch (PDOException $e) {
        error_log("[INSERT FAIL] {$symbol} | ".$e->getMessage());
        $rejected++;
    }
}

/* ================== SUMMARY ================== */
error_log("[SUMMARY] inserted={$inserted} rejected={$rejected} total=".count($tickers));
