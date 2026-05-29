<?php
require '../config.php';
require_once '../includes/auth_check.php';
require_login();

$qr = $_SESSION['qrph_data'] ?? null;
if (!$qr) { header('Location: cart.php'); exit; }
?>
<!DOCTYPE html>
<html>
<head>
  <title>Scan QR to Pay — AquaLuxe</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@400;600&family=DM+Mono&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{background:#eef7ff;font-family:'DM Sans',sans-serif;min-height:100vh;
         display:flex;align-items:center;justify-content:center;padding:20px}
    .box{background:#fff;border-radius:20px;padding:40px 36px;text-align:center;
         max-width:440px;width:100%;box-shadow:0 8px 40px rgba(0,112,255,0.12)}
    h2{font-family:'Playfair Display',serif;font-size:22px;color:#002d6e;margin-bottom:6px}
    .sub{color:#5e8ab4;font-size:13px;margin-bottom:24px}
    .qr-wrap{background:#f5faff;border:2px solid #b3d4f0;border-radius:14px;
             padding:16px;display:inline-block;margin-bottom:20px}
    .qr-img{width:220px;height:220px;display:block}
    .amount{font-size:30px;font-weight:700;color:#0070ff;
            font-family:'Playfair Display',serif;margin-bottom:4px}
    .intent-id{font-size:10px;font-family:'DM Mono',monospace;
               color:#7ab3e0;margin-bottom:20px}
    .expires{font-size:11px;font-family:'DM Mono',monospace;
             color:#5e8ab4;margin-bottom:20px}
    .status-bar{padding:13px 20px;border-radius:10px;background:#e6f4ff;
                color:#5e8ab4;font-size:13px;font-weight:600;transition:all 0.3s}
    .status-bar.paid{background:#e6fff5;color:#009e77}
    .status-bar.expired{background:#fff0e6;color:#c04a00}
    .btn{display:inline-block;margin-top:20px;padding:12px 28px;
         background:#0070ff;color:#fff;border-radius:30px;
         text-decoration:none;font-weight:700;font-size:13px}
    .apps{margin-top:20px;font-size:11px;color:#7ab3e0;font-family:'DM Mono',monospace}
  </style>
</head>
<body>
<div class="box">
  <h2>📱 Scan QR Ph to Pay</h2>
  <p class="sub">Open GCash, Maya, or any QR Ph-supported app and scan:</p>

  <div class="qr-wrap">
    <img class="qr-img" src="<?= htmlspecialchars($qr['qr_image']) ?>" alt="QR Code">
  </div>

  <div class="amount">₱<?= number_format($qr['total'], 2) ?></div>
  <div class="intent-id"><?= htmlspecialchars($qr['intent_id']) ?></div>

  <?php if (!empty($qr['expires_at'])): ?>
    <div class="expires">⏱ Expires: <?= date('h:i A', strtotime($qr['expires_at'])) ?></div>
  <?php endif; ?>

  <div class="status-bar" id="statusBar">⏳ Waiting for payment…</div>
  <div id="backBtn" style="display:none">
    <a href="orders.php" class="btn">View My Orders →</a>
  </div>

  <div class="apps">Works with GCash · Maya · BDO · BPI · UnionBank · and more</div>
</div>

<script>
const intentId = '<?= htmlspecialchars($qr['intent_id']) ?>';
let polling;
let attempts = 0;
const MAX_ATTEMPTS = 75; // ~5 minutes at 4s intervals

async function checkStatus() {
  attempts++;
  if (attempts > MAX_ATTEMPTS) {
    clearInterval(polling);
    document.getElementById('statusBar').textContent = '⏱ QR code expired. Please try again.';
    document.getElementById('statusBar').className = 'status-bar expired';
    document.getElementById('backBtn').style.display = 'block';
    document.getElementById('backBtn').innerHTML = '<a href="cart.php" class="btn">Back to Cart</a>';
    return;
  }

  try {
    const res  = await fetch('qrph_check.php?intent_id=' + encodeURIComponent(intentId));
    const data = await res.json();
    const bar  = document.getElementById('statusBar');

    if (data.status === 'succeeded') {
      bar.textContent = '✅ Payment confirmed! Your order has been placed.';
      bar.className   = 'status-bar paid';
      document.getElementById('backBtn').style.display = 'block';
      clearInterval(polling);
      // Clear session QR data
      fetch('qrph_clear.php');
    } else if (data.status === 'processing') {
      bar.textContent = '🔄 Payment processing…';
    }
    // else still awaiting — keep polling silently
  } catch (e) {
    // Network error — keep polling
  }
}

polling = setInterval(checkStatus, 4000);
checkStatus(); // immediate first check
</script>
</body>
</html>