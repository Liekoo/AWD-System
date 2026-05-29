<?php
require '../config.php';
session_start();

$status    = $_GET['status'] ?? 'failed';
$source_id = $_GET['source_id'] ?? '';
$intent_id = $_GET['payment_intent_id'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
  <title>Payment Return — AquaLuxe</title>
  <meta http-equiv="refresh" content="5;url=orders.php">
  <style>
    body { font-family:'DM Sans',sans-serif; background:#eef7ff;
           display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
    .box { background:#fff; border-radius:20px; padding:48px 40px; text-align:center;
           max-width:400px; box-shadow:0 8px 40px rgba(0,112,255,0.12); }
    .icon { font-size:56px; display:block; margin-bottom:16px; }
    h2 { font-size:22px; margin-bottom:8px; color:#002d6e; }
    p  { color:#5e8ab4; font-size:14px; }
    a  { color:#0070ff; }
  </style>
</head>
<body>
<div class="box">
  <?php if ($status === 'success'): ?>
    <span class="icon">⏳</span>
    <h2>Payment Processing</h2>
    <p>Your payment is being confirmed. Your order will appear in
       <a href="orders.php">My Orders</a> once verified.<br><br>
       Redirecting in 5 seconds…</p>
  <?php else: ?>
    <span class="icon">❌</span>
    <h2>Payment Cancelled</h2>
    <p>Your payment was not completed. <a href="cart.php">Go back to cart</a>.</p>
  <?php endif; ?>
</div>
</body>
</html>