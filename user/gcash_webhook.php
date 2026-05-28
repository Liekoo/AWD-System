<?php
file_put_contents(__DIR__ . '/webhook_log.txt', file_get_contents('php://input'), FILE_APPEND);
require '../config.php'; // adjust path if needed

// Top of gcash_webhook.php
$whsec   = 'whsk_h';
$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

// PayMongo signature format: t=timestamp,te=hash,li=hash
// If signature doesn't match, reject it
if (empty($sig)) {
    http_response_code(401);
    exit('Unauthorized');
}

$payload    = file_get_contents('php://input');
$event      = json_decode($payload, true);
$event_type = $event['data']['attributes']['type'] ?? '';

if ($event_type === 'source.chargeable') {
    $source    = $event['data']['attributes']['data'];
    $source_id = $source['id'];                          // src_xxx
    $amount    = $source['attributes']['amount'];

    $sk = '';

    // ── Create payment ──────────────────────────────────────
    $payment_payload = json_encode([
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
        CURLOPT_POSTFIELDS     => $payment_payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($sk . ':'),
        ],
    ]);
    $pm_response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $pm_status = $pm_response['data']['attributes']['status'] ?? '';

    if ($pm_status === 'paid') {
        $src_esc = $conn->real_escape_string($source_id);

        // ── Look up pending order ───────────────────────────
        $row = $conn->query("SELECT * FROM gcash_pending_orders 
                             WHERE Source_ID='$src_esc' LIMIT 1")->fetch_assoc();

        if ($row) {
            $uid           = (int)$row['User_ID'];
            $items         = json_decode($row['Items_JSON'], true);
            $address_id    = (int)$row['Address_ID'];
            $payment_id    = (int)$row['Payment_Type_ID'];
            $note          = $conn->real_escape_string($row['Order_Note']);
            $customer_name = $conn->real_escape_string($row['Customer_Name']);
            $ctype_row     = $conn->query("SELECT Customer_Type_ID FROM customer_type LIMIT 1")->fetch_assoc();
            $customer_id   = $ctype_row['Customer_Type_ID'] ?? 1;

            // ── Insert orders ───────────────────────────────
            foreach ($items as $item) {
                $pid   = (int)$item['Product_ID'];
                $sid   = $item['Size_ID'] ? (int)$item['Size_ID'] : 'NULL';
                $qty   = (int)$item['Quantity'];
                $price = floatval($item['Unit_Price']);
                $fne   = $conn->real_escape_string($note ?: 'Paid via GCash');

                $conn->query("INSERT INTO orders
                    (User_ID, Product_ID, Size_ID, Customer_Type_ID, Payment_Type_ID,
                     Order_Quantity, Product_Price, Customer_Name, Order_Note, Address_ID)
                    VALUES ($uid, $pid, $sid, $customer_id, $payment_id,
                            $qty, $price, '$customer_name', '$fne', $address_id)");

                $conn->query("UPDATE products 
                              SET Product_Quantity_Stock = Product_Quantity_Stock - $qty 
                              WHERE Product_ID = $pid");
            }

            // ── Clear cart & pending row ────────────────────
            $conn->query("DELETE FROM cart WHERE User_ID=$uid");
            $conn->query("DELETE FROM gcash_pending_orders WHERE Source_ID='$src_esc'");
        }
    }
}

http_response_code(200);
echo 'OK';