<?php
$payload = file_get_contents('php://input'); // ← read ONCE
file_put_contents(__DIR__ . '/webhook_log.txt', $payload . PHP_EOL, FILE_APPEND);

require '../config.php';
require_once '../env.php';

// ── Signature verification ─────────────────────────────────
$whsec = $_ENV['PAYMONGO_WEBHOOK_SECRET'];

$headers          = getallheaders();
$signature_header = $headers['Paymongo-Signature'] ?? $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if (empty($signature_header)) {
    http_response_code(400);
    exit('Missing Webhook Signature');
}

parse_str(str_replace(',', '&', $signature_header), $sig_parts);
$timestamp        = $sig_parts['t'] ?? '';
$live_signature   = $sig_parts['li'] ?? '';
$data_to_sign     = $timestamp . '.' . $payload;
$expected         = hash_hmac('sha256', $data_to_sign, $whsec);

if (!hash_equals($expected, $live_signature)) {
    http_response_code(401);
    exit('Signature Mismatch');
}

// ── Parse event ────────────────────────────────────────────
$event      = json_decode($payload, true);
$event_type = $event['data']['attributes']['type'] ?? '';

// ── Helper: insert orders from pending row ─────────────────
function fulfillOrder($conn, $row) {
    $uid           = (int)$row['User_ID'];
    $items         = json_decode($row['Items_JSON'], true);
    $address_id    = (int)$row['Address_ID'];
    $payment_id    = (int)$row['Payment_Type_ID'];
    $note          = $conn->real_escape_string($row['Order_Note']);
    $customer_name = $conn->real_escape_string($row['Customer_Name']);
    $ctype_row     = $conn->query("SELECT Customer_Type_ID FROM customer_type LIMIT 1")->fetch_assoc();
    $customer_id   = $ctype_row['Customer_Type_ID'] ?? 1;

    foreach ($items as $item) {
        $pid   = (int)$item['Product_ID'];
        $sid   = $item['Size_ID'] ? (int)$item['Size_ID'] : 'NULL';
        $qty   = (int)$item['Quantity'];
        $price = floatval($item['Unit_Price']);
        $fne   = $conn->real_escape_string($note ?: 'Paid via PayMongo');

        $conn->query("INSERT INTO orders
            (User_ID, Product_ID, Size_ID, Customer_Type_ID, Payment_Type_ID,
             Order_Quantity, Product_Price, Customer_Name, Order_Note, Address_ID)
            VALUES ($uid, $pid, $sid, $customer_id, $payment_id,
                    $qty, $price, '$customer_name', '$fne', $address_id)");

        $conn->query("UPDATE products 
                      SET Product_Quantity_Stock = Product_Quantity_Stock - $qty 
                      WHERE Product_ID = $pid");
    }

    $conn->query("DELETE FROM cart WHERE User_ID = $uid");
}

// ── GCash via Sources API ──────────────────────────────────
if ($event_type === 'source.chargeable') {
    $source    = $event['data']['attributes']['data'];
    $source_id = $source['id'];
    $amount    = $source['attributes']['amount'];
    $sk        = $_ENV['PAYMONGO_SECRET_KEY'];

    // Create payment against the source
    $pm_payload = json_encode([
        'data' => ['attributes' => [
            'amount'      => $amount,
            'currency'    => 'PHP',
            'source'      => ['id' => $source_id, 'type' => 'source'],
            'description' => 'AquaLuxe GCash Order',
        ]],
    ]);

    $ch = curl_init('https://api.paymongo.com/v1/payments');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $pm_payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($sk . ':'),
        ],
    ]);
    $pm_res    = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (($pm_res['data']['attributes']['status'] ?? '') === 'paid') {
        $src_esc = $conn->real_escape_string($source_id);
        $row     = $conn->query("SELECT * FROM gcash_pending_orders 
                                 WHERE Source_ID='$src_esc' LIMIT 1")->fetch_assoc();
        if ($row) {
            fulfillOrder($conn, $row);
            $conn->query("DELETE FROM gcash_pending_orders WHERE Source_ID='$src_esc'");
        }
    }

// ── QR Ph / GCash via Payment Intents API ─────────────────
} elseif ($event_type === 'payment_intent.succeeded') {
    $intent_id  = $event['data']['attributes']['data']['id'] ?? '';
    $intent_esc = $conn->real_escape_string($intent_id);

    $row = $conn->query("SELECT * FROM gcash_pending_orders 
                         WHERE Source_ID='$intent_esc' LIMIT 1")->fetch_assoc();
    if ($row) {
        fulfillOrder($conn, $row);
        $conn->query("DELETE FROM gcash_pending_orders WHERE Source_ID='$intent_esc'");
    }
}

http_response_code(200);
echo 'OK';