<?php

class KuCoinAPI {
    private $apiKey;
    private $secretKey;
    private $passphrase;
    private $base = "https://api.kucoin.com";

    public function __construct($apiKey, $secretKey, $passphrase) {
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;
        $this->passphrase = $passphrase;
    }

    private function sign($method, $endpoint, $body = "") {
        $timestamp = sprintf('%.0f', microtime(true) * 1000);

        $strToSign = $timestamp . strtoupper($method) . $endpoint . $body;
        $signature = base64_encode(hash_hmac('sha256', $strToSign, $this->secretKey, true));
        $passphrase = base64_encode(hash_hmac('sha256', $this->passphrase, $this->secretKey, true));

        return [
            "timestamp" => $timestamp,
            "signature" => $signature,
            "passphrase" => $passphrase
        ];
    }

    private function request($method, $endpoint, $data = []) {
        $body = !empty($data) ? json_encode($data) : "";
        $sign = $this->sign($method, $endpoint, $body);

        $headers = [
            "Content-Type: application/json",
            "KC-API-KEY: {$this->apiKey}",
            "KC-API-SIGN: {$sign['signature']}",
            "KC-API-TIMESTAMP: {$sign['timestamp']}",
            "KC-API-PASSPHRASE: {$sign['passphrase']}",
            "KC-API-KEY-VERSION: 2"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->base . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);

        if (!$result) {
            return ["error" => curl_error($ch)];
        }

        curl_close($ch);
        return json_decode($result, true);
    }

    /* ================================
       GET ALL BALANCES
    =================================*/
    public function getAllBalances() {
        $res = $this->request("GET", "/api/v1/accounts");

        if (!isset($res['data'])) {
            return [];
        }

        $balances = [];

        foreach ($res['data'] as $row) {
            // Ambil total = available + hold
            if (!isset($balances[$row['currency']])) {
                $balances[$row['currency']] = [
                    "available" => 0,
                    "hold"      => 0,
                    "total"     => 0
                ];
            }

            $balances[$row['currency']]["available"] += (float)$row["available"];
            $balances[$row['currency']]["hold"]      += (float)$row["holds"];
            $balances[$row['currency']]["total"]      = 
                $balances[$row['currency']]["available"] + 
                $balances[$row['currency']]["hold"];
        }

        return $balances;
    }

    /* ================================
       GET BALANCE OF A SINGLE ASSET
    =================================*/
    public function getAssetBalance($coin) {
        $coin = strtoupper($coin);
        $all = $this->getAllBalances();

        if (isset($all[$coin])) {
            return $all[$coin];
        }
        return ["available" => 0, "hold" => 0, "total" => 0];
    }

    /* ================================
       GET USDT BALANCE ONLY
    =================================*/
    public function getUsdtBalance() {
        return $this->getAssetBalance("USDT");
    }

    /* ================================
       MARKET BUY (USDT)
    =================================*/
    public function marketBuy($symbol, $usdtAmount) {
        $data = [
            "clientOid" => uniqid(),
            "side" => "buy",
            "symbol" => $symbol,
            "type" => "market",
            "funds" => strval($usdtAmount)
        ];
        return $this->request("POST", "/api/v1/orders", $data);
    }

    /* ================================
       MARKET SELL (COIN SIZE)
    =================================*/
    public function marketSell($symbol, $size) {
        $data = [
            "clientOid" => uniqid(),
            "side" => "sell",
            "symbol" => $symbol,
            "type" => "market",
            "size" => strval($size)
        ];
        return $this->request("POST", "/api/v1/orders", $data);
    }

    public function testSignature()
	{
	    $method = "GET";
	    $endpoint = "/api/v1/timestamp";
	    $body = "";

	    $timestamp = sprintf('%.0f', microtime(true) * 1000);
	    $strToSign = $timestamp . strtoupper($method) . $endpoint . $body;
	    $signature = base64_encode(hash_hmac('sha256', $strToSign, $this->secretKey, true));
	    $passphrase = base64_encode(hash_hmac('sha256', $this->passphrase, $this->secretKey, true));

	    return [
	        "timestamp" => $timestamp,
	        "string_to_sign" => $strToSign,
	        "signature" => $signature,
	        "passphrase_signed" => $passphrase
	    ];
	}

	public function checkApi()
	{
	    // Endpoint private yang paling ringan dan aman
	    $endpoint = "/api/v1/accounts";
	    $result = $this->request("GET", $endpoint);

	    if (!isset($result["code"])) {
	        return [
	            "status" => false,
	            "message" => "Tidak ada respon dari server",
	            "raw" => $result
	        ];
	    }

	    if ($result["code"] === "200000") {
	        return [
	            "status" => true,
	            "message" => "API KEY VALID. Signature dan passphrase benar.",
	            "data" => $result["data"]
	        ];
	    }

	    return [
	        "status" => false,
	        "message" => "API KEY atau passphrase SALAH",
	        "error" => $result
	    ];
	}


}

/* ===========================
   CARA PAKAI
=========================== */

$trade_api = new KuCoinAPI("", "", "");

// buy code
// $balance = $api->getUsdtBalance();
// $funds  = floor(($balance['available'] * 0.995) * 100) / 100; // leave fee + round
// $buy = $api->marketBuy("SAND-USDT", $funds);
// print_r($buy);

// sell code
// $sand = $api->getAssetBalance("SAND");
// $sell = $api->marketSell("SAND-USDT", $sand["available"]);
// print_r($sell);


// echo "=== TEST SIGNATURE ===\n";
// print_r($api->testSignature());

// echo "=== CHECK API ===\n";
// print_r($api->checkApi());

// $all = $api->getAllBalances();
// print_r($all);

// $usdt = $api->getUsdtBalance();
// print_r($usdt);

// $woo = $api->getAssetBalance("WOO");
// print_r($woo);

/* ================= TRADE BLOCK (OUTSIDE CORE) ================= */
function handleBuy($trade_api, $symbol, PDO $pdo, array $config){

    // $candidate = getBestBuyCandidate($pdo, $config['min_volume'], $config['max_volume']);
    // if (!$candidate || $candidate['symbol'] !== $symbol ){
    //     return false;
    // }

    // // Ambil saldo USDT
    // $balance = $trade_api->getUsdtBalance();

    // if (!isset($balance['available']) || $balance['available'] <= 0) {
    //     return false;
    // }

    // // Gunakan 99.5% saldo (fee buffer)
    // $funds = floor(($balance['available'] * 0.995) * 100) / 100;

    // if ($funds < 1) { // KuCoin umumnya min ~1 USDT
    //     return false;
    // }

    // // Market BUY (funds-based)
    // return $trade_api->marketBuy($symbol, $funds);

}

function handleSell($trade_api, $symbol, PDO $pdo = null){

    // // Extract asset dari symbol (XXXX-USDT)
    // $parts = explode('-', $symbol);
    // if (count($parts) !== 2) {
    //     return false;
    // }

    // $asset = $parts[0];

    // // Ambil saldo asset
    // $balance = $trade_api->getAssetBalance($asset);

    // if (!isset($balance['available']) || $balance['available'] <= 0) {
    //     return false;
    // }

    // // Market SELL (size-based)
    // return $trade_api->marketSell($symbol, $balance['available']);
    
}

?>
