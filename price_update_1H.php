<?php
// php 5.6 - revised for more robust inserts

chdir(__DIR__);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/kucoin_insert.txt');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Jakarta');
set_time_limit(120);
ini_set('memory_limit', '512M');

$config = array(
    'db_host'    => 'localhost',
    'db_name'    => 'db',
    'db_user'    => 'user',
    'db_pass'    => 'pass',
    'api_url'    => 'https://api.kucoin.com/api/v1/market/allTickers',
    'watch_list' => array('*USDT'),
    // toggle: if you prefer silent skip on duplicate key set to true
    'use_insert_ignore' => true,
    'batch_commit' => 100
);

function log_msg($msg) {
    error_log($msg);
}

function connect_pdo($cfg) {
    $opts = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // prefer native prepares for stability with MySQL
        PDO::ATTR_EMULATE_PREPARES => false,
        // ensure utf8mb4
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    );
    return new PDO(
        "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
        $cfg['db_user'],
        $cfg['db_pass'],
        $opts
    );
}

/* ====== connect ====== */
try {
    $pdo = connect_pdo($config);
} catch (PDOException $e) {
    log_msg("[DB ERROR] " . $e->getMessage());
    exit;
}

/* ====== fetch api ====== */
$ch = curl_init($config['api_url']);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_ENCODING => '',
));
$response = curl_exec($ch);
if ($response === false) {
    log_msg("[CURL ERROR] " . curl_error($ch));
    exit;
}
curl_close($ch);

$data = json_decode($response, true);
$tickers = isset($data['data']['ticker']) ? $data['data']['ticker'] : array();
if (!$tickers) {
    log_msg("[API] empty ticker or unexpected structure");
    exit;
}

/* ====== patterns ====== */
$patterns = array();
foreach ($config['watch_list'] as $p) {
    $patterns[] = '/^' . str_replace('\\*', '.*', preg_quote(strtoupper($p), '/')) . '$/';
}

/* ====== prepare statement (optionally IGNORE) ====== */
$ignore = $config['use_insert_ignore'] ? 'INSERT IGNORE' : 'INSERT';
$sql = $ignore . " INTO kucoin_watch
    (symbol, last_price, buy_price, sell_price, high_price, low_price, volume, volume_value, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
try {
    $stmt = $pdo->prepare($sql);
} catch (PDOException $e) {
    log_msg("[PREPARE FAIL] " . $e->getMessage());
    exit;
}

/* ====== process ====== */
$inserted = 0;
$rejected  = 0;
$now = date('Y-m-d H:i:s');
$batch = 0;

// helper to attempt reconnect on certain errors
function try_reconnect_and_retry(&$pdo, $config, $sql, $params, &$stmt) {
    try {
        $pdo = connect_pdo($config);
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        log_msg("[RETRY FAIL] " . $e->getMessage());
        return false;
    }
}

foreach ($tickers as $t) {
    $symbol = strtoupper(isset($t['symbol']) ? $t['symbol'] : '');
    if (!$symbol) {
        $rejected++;
        log_msg("[REJECT] empty symbol | " . json_encode($t));
        continue;
    }

    $ok = false;
    foreach ($patterns as $pt) {
        if (preg_match($pt, $symbol)) { $ok = true; break; }
    }
    if (!$ok) {
        $rejected++;
        log_msg("[REJECT] {$symbol} | not watchlist");
        continue;
    }

    // ensure values exist and are safe
    $last = isset($t['last']) ? (string) $t['last'] : '0';
    $buy  = isset($t['buy'])  ? (string) $t['buy']  : '0';
    $sell = isset($t['sell']) ? (string) $t['sell'] : '0';
    $high = isset($t['high']) ? (string) $t['high'] : '0';
    $low  = isset($t['low'])  ? (string) $t['low']  : '0';
    $vol  = isset($t['vol'])  ? (string) $t['vol']  : '0';
    $volv = isset($t['volValue']) ? (string) $t['volValue'] : '0';

    $params = array($symbol, $last, $buy, $sell, $high, $low, $vol, $volv, $now);

    try {
        $stmt->execute($params);
        $inserted++;
    } catch (PDOException $e) {
        $code = $e->getCode();
        $msg  = $e->getMessage();
        log_msg("[INSERT FAIL] {$symbol} | code={$code} msg={$msg} data=" . json_encode($t));

        // transient recovery example: reconnect on "gone away" or server lost
        if (strpos($msg, 'MySQL server has gone away') !== false ||
            strpos($msg, 'server has gone away') !== false ||
            $code == '2006') {
            // attempt reconnect + retry once
            $success = try_reconnect_and_retry($pdo, $config, $sql, $params, $stmt);
            if ($success) {
                $inserted++;
            } else {
                $rejected++;
            }
        } else {
            $rejected++;
        }
    }

    // optional batch commit logic (if you move to transaction mode)
    $batch++;
    if ($config['batch_commit'] > 0 && $batch >= $config['batch_commit']) {
        // nothing to commit here because we're running single-statement autocommit,
        // but if you change to explicit transaction, you can commit here.
        $batch = 0;
    }
}

function purge_old_data(PDO $pdo, $days = 6) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM kucoin_watch
            WHERE created_at < (NOW() - INTERVAL ? DAY)
        ");
        $stmt->execute(array((int)$days));
        error_log("[PURGE] deleted_rows=" . $stmt->rowCount());
    } catch (PDOException $e) {
        error_log("[PURGE ERROR] " . $e->getMessage());
    }
}

purge_old_data($pdo, 6);

log_msg("[SUMMARY] inserted={$inserted} rejected={$rejected} total=" . count($tickers));
