<?php
// user/refill_action.php
session_start();
require '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    echo json_encode(['success' => false, 'message' => 'Please sign in first.']);
    exit;
}

$uid    = (int) $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action !== 'refill') {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── Inputs ──────────────────────────────────────────────────────
$pid     = (int) ($_POST['product_id'] ?? 0);
$qty     = max(1, (int) ($_POST['quantity'] ?? 1));
$payment = ($_POST['payment'] ?? 'cod') === 'wallet' ? 'wallet' : 'cod';

// ── Validate product ─────────────────────────────────────────────
$product = $conn->query(
    "SELECT Product_ID, Product_Name, Product_Price
     FROM products
     WHERE Product_ID = $pid AND Product_Status = 'Active'"
)->fetch_assoc();

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

$unit_price   = (float) $product['Product_Price'];
$order_total  = $unit_price * $qty;
$customer_name = $_SESSION['full_name'] ?? '';

// ── Get user's default shipping address ──────────────────────────
$addr = $conn->query(
    "SELECT Address_ID FROM shipping_addresses
     WHERE User_ID = $uid AND Is_Default = 1
     LIMIT 1"
)->fetch_assoc();
$address_id = $addr ? (int)$addr['Address_ID'] : null;
$address_sql = $address_id ? $address_id : 'NULL';

// ── Payment Type IDs (from your payments_type table) ─────────────
// 1 = Cash On Delivery,  7 = Wallet  (adjust if yours differs)
$payment_type_id = ($payment === 'wallet') ? 7 : 1;
$customer_type_id = 2; // 'customer'

// ── WALLET PAYMENT ───────────────────────────────────────────────
if ($payment === 'wallet') {
    $wallet_row = $conn->query(
        "SELECT Wallet_Balance FROM users WHERE User_ID = $uid"
    )->fetch_assoc();
    $balance = (float) $wallet_row['Wallet_Balance'];

    if ($balance < $order_total) {
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient wallet balance. You have ₱' . number_format($balance, 2)
                       . ' but need ₱' . number_format($order_total, 2) . '.'
        ]);
        exit;
    }

    $conn->begin_transaction();
    try {
        // Deduct wallet
        $conn->query(
            "UPDATE users SET Wallet_Balance = Wallet_Balance - $order_total WHERE User_ID = $uid"
        );
        $new_balance = $balance - $order_total;

        // Log wallet transaction (matches your wallet_transactions table)
        $note = 'Refill payment via Wallet';
        $conn->query(
            "INSERT INTO wallet_transactions (User_ID, Type, Amount, Balance_After, Note, Status)
             VALUES ($uid, 'purchase', $order_total, $new_balance, '$note', 'approved')"
        );

        // Insert into orders with Order_Type = 'refill'
        $note_escaped = $conn->real_escape_string('Refill order — Paid with Wallet. Staff will pick up container.');
        $name_escaped = $conn->real_escape_string($customer_name);

        $conn->query(
            "INSERT INTO orders
               (User_ID, Product_ID, Customer_Type_ID, Customer_Name, Payment_Type_ID,
                Order_Quantity, Product_Price, Order_Status, Order_Type, Order_Note, Address_ID)
             VALUES
               ($uid, $pid, $customer_type_id, '$name_escaped', $payment_type_id,
                $qty, $unit_price, 'Pending', 'refill', '$note_escaped', $address_sql)"
        );

        $conn->commit();

        echo json_encode([
            'success'     => true,
            'message'     => '🔄 Refill requested! Our staff will come pick up your container.',
            'new_balance' => number_format($new_balance, 2)
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed. Please try again.']);
    }
    exit;
}

// ── COD PAYMENT ──────────────────────────────────────────────────
$note_escaped = $conn->real_escape_string('Refill order — Cash on Pickup. Staff will pick up container.');
$name_escaped = $conn->real_escape_string($customer_name);

$ok = $conn->query(
    "INSERT INTO orders
       (User_ID, Product_ID, Customer_Type_ID, Customer_Name, Payment_Type_ID,
        Order_Quantity, Product_Price, Order_Status, Order_Type, Order_Note, Address_ID)
     VALUES
       ($uid, $pid, $customer_type_id, '$name_escaped', $payment_type_id,
        $qty, $unit_price, 'Pending', 'refill', '$note_escaped', $address_sql)"
);

if ($ok) {
    echo json_encode([
        'success' => true,
        'message' => '🔄 Refill requested! Please have ₱' . number_format($order_total, 2) . ' ready for our staff.'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Could not place refill. Please try again.']);
}