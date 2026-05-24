<?php
/**
 * CUSTOMER — user/wallet.php
 * Wallet wallet — view balance, request top-up, transaction history
 */
require '../config.php';
require_once '../includes/auth_check.php';
require_login();
$uid = $_SESSION['user_id'];
 
// Submit top-up request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_topup'])) {
    $amount  = (float)$_POST['amount'];
    $ref     = $conn->real_escape_string(trim($_POST['reference']));
    $method  = $conn->real_escape_string($_POST['payment_method']);
    $proof   = '';
 
    // Handle proof image upload
    if (!empty($_FILES['proof_image']['name'])) {
        $ext     = strtolower(pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp','gif'];
        if (in_array($ext, $allowed) && $_FILES['proof_image']['size'] <= 5*1024*1024) {
            $filename = 'uploads/topup_proofs/' . uniqid('proof_') . '.' . $ext;
            move_uploaded_file($_FILES['proof_image']['tmp_name'], '../' . $filename);
            $proof = $conn->real_escape_string($filename);
        }
    }
 
    if ($amount >= 50 && !empty($ref) && !empty($proof)) {
        $conn->query("INSERT INTO topup_requests (User_ID, Amount, Reference, Payment_Method, Proof_Image) VALUES ($uid, $amount, '$ref', '$method', '$proof')");
        header('Location: wallet.php?toast=requested'); exit;
    } elseif (empty($proof)) {
        $form_error = 'Please attach a payment screenshot as proof.';
    }
}
 
// Fetch wallet data
$user    = $conn->query("SELECT Full_Name, Wallet_Balance FROM users WHERE User_ID=$uid")->fetch_assoc();
$balance = $user['Wallet_Balance'];
 
$transactions = $conn->query("
    SELECT * FROM wallet_transactions WHERE User_ID=$uid ORDER BY Created_At DESC LIMIT 20
");
 
$requests = $conn->query("
    SELECT * FROM topup_requests WHERE User_ID=$uid ORDER BY Created_At DESC LIMIT 10
");

$is_logged_in = isset($_SESSION['user_id']) && $_SESSION['role'] === 'user';
$cart_count   = 0;
if ($is_logged_in) {
    $uid        = $_SESSION['user_id'];
    $cart_count = $conn->query("SELECT SUM(Quantity) AS c FROM cart WHERE User_ID=$uid")->fetch_assoc()['c'] ?? 0;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Wallet  — Wallet</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
  <style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --water:#0070ff;--water-mid:#1588ff;--water-bright:#0097ff;--water-light:#00b1ff;
  --sky:#e6f4ff;--sky2:#cceeff;--sky3:#b3e5fc;
  --deep:#002d6e;--deep2:#003d8f;--navy:#001a4d;
  --text:#051c3a;--text-soft:#2d5a8e;--text-muted:#5e8ab4;
  --border:#b3d4f0;--border-dark:#7ab3e0;--card:#f5faff;
  --serif:'Playfair Display',serif;--sans:'DM Sans',sans-serif;--mono:'DM Mono',monospace;--radius:16px;
}
body{background:#eef7ff;color:var(--text);font-family:var(--sans);min-height:100vh}
.topbar{background:var(--navy);padding:0 40px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 20px rgba(0,26,77,0.4)}
.logo{font-family:var(--serif);font-size:20px;color:#e6f4ff}.logo span{color:var(--water-light)}
.topbar-right{display:flex;align-items:center;gap:12px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:30px;font-family:var(--sans);font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all 0.2s}
.btn-warm{color:#fff;background:var(--water)}.btn-warm:hover{background:var(--water-mid);transform:translateY(-1px)}
.btn-outline{color:#cce8ff;background:transparent;border:1.5px solid rgba(0,177,255,0.3)}.btn-outline:hover{background:rgba(0,112,255,0.15)}
.btn-brown{width:100%;padding:13px;background:var(--water);color:#fff;border:none;border-radius:30px;font-family:var(--sans);font-size:14px;font-weight:700;cursor:pointer;transition:all 0.2s;margin-top:8px}
.btn-brown:hover{background:var(--deep2);transform:translateY(-1px);box-shadow:0 6px 20px rgba(0,112,255,0.3)}
.logout-link{font-size:12px;color:rgba(168,212,245,0.4);text-decoration:none;font-family:var(--mono)}.logout-link:hover{color:var(--water-light)}
.content{max-width:900px;margin:0 auto;padding:36px 24px}
.back-link{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text-muted);text-decoration:none;margin-bottom:24px;font-family:var(--mono)}.back-link:hover{color:var(--water)}

.wallet-hero{background:linear-gradient(135deg,var(--navy) 0%,var(--deep2) 60%,#005bb5 100%);border-radius:20px;padding:36px;margin-bottom:24px;position:relative;overflow:hidden}
.wallet-hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none'%3E%3Cg fill='%230097ff' fill-opacity='0.07'%3E%3Ccircle cx='30' cy='30' r='4'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")}
.wallet-content{position:relative;z-index:1}
.wallet-label{font-size:12px;font-family:var(--mono);color:rgba(168,212,245,0.55);text-transform:uppercase;letter-spacing:2px;margin-bottom:8px}
.wallet-balance{font-family:var(--serif);font-size:52px;font-weight:700;color:#e6f4ff;letter-spacing:-2px;margin-bottom:4px}
.wallet-balance .currency{font-size:28px;vertical-align:super;margin-right:4px;color:var(--water-light)}
.wallet-name{font-size:14px;color:rgba(168,212,245,0.65);font-family:var(--mono)}
.wallet-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(0,177,255,0.15);border:1px solid rgba(0,177,255,0.3);color:var(--water-light);padding:5px 14px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px}

.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px}
@media(max-width:640px){.grid-2{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:20px}
.card-title{font-family:var(--serif);font-size:18px;color:var(--deep2);margin-bottom:16px}
.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
label{font-size:11px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:1px}
input,select{background:var(--sky);border:1.5px solid var(--border);border-radius:10px;color:var(--text);font-family:var(--sans);font-size:13px;padding:11px 14px;outline:none;width:100%;transition:border-color 0.2s}
input:focus,select:focus{border-color:var(--water-bright)}
select option{background:#e6f4ff}
.helper{font-size:11px;color:var(--text-muted);font-family:var(--mono);margin-top:4px}

.tx-item{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--border)}
.tx-item:last-child{border-bottom:none}
.tx-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;margin-right:12px}
.tx-topup{background:rgba(0,151,255,0.12)}
.tx-purchase{background:rgba(0,112,255,0.1)}
.tx-refund{background:rgba(0,177,255,0.12)}
.tx-info{flex:1;min-width:0}
.tx-label{font-size:13px;font-weight:600;color:var(--text)}
.tx-date{font-size:11px;font-family:var(--mono);color:var(--text-muted)}
.tx-amount-pos{font-family:var(--serif);font-size:15px;font-weight:700;color:var(--water-bright)}
.tx-amount-neg{font-family:var(--serif);font-size:15px;font-weight:700;color:#c04a00}

.req-item{display:flex;align-items:flex-start;justify-content:space-around;padding:5px;border-radius:10px;background:var(--sky);margin-bottom:8px;border:1px solid var(--border)}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:600}
.badge-pending{background:rgba(0,112,255,0.1);color:var(--water);border:1px solid rgba(0,112,255,0.25)}
.badge-approved{background:rgba(0,151,255,0.12);color:var(--water-mid);margin-top: 5px;border:1px solid rgba(0,151,255,0.3)}
.badge-rejected{background:rgba(192,74,0,0.1);color:#c04a00;border:1px solid rgba(192,74,0,0.25)}

.info-box{background:var(--sky);border:1px dashed var(--border-dark);border-radius:10px;padding:14px 16px;font-size:12px;color:var(--text-soft);line-height:1.6;margin-bottom:14px}
.info-box strong{color:var(--deep2)}

.toast-bar{position:fixed;bottom:28px;right:28px;background:var(--navy);color:#e6f4ff;padding:13px 22px;border-radius:30px;font-weight:700;font-size:13px;opacity:0;transition:opacity 0.3s,transform 0.4s cubic-bezier(.34,1.56,.64,1);transform:translateY(80px);pointer-events:none;z-index:999;box-shadow:0 8px 24px rgba(0,26,77,0.3)}
.toast-bar.show{opacity:1;transform:translateY(0)}
.footer{background:var(--navy);color:rgba(168,212,245,0.4);text-align:center;padding:20px;font-size:12px;font-family:var(--mono);margin-top:60px}
.btn-outline1{color:var(--water);background:transparent;border:1.5px solid rgba(0,177,255,0.3);margin-bottom: 20px;color:var(--text-muted)}
.btn-outline1:hover{background:rgba(0,112,255,0.15)}
.cart-count{background:var(--water);color:#fff;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:11px;font-family:var(--mono)}
</style>
</head>
<body>
<body>
<div class="topbar">
  <div class="logo">Aqua<span>Luxe</span></div>
  <div class="topbar-right">
    <a href="shop.php" class="btn btn-outline">Home</a>
    <a href="orders.php" class="btn btn-outline">My Orders</a>
    <a href="wallet.php" class="btn btn-warm">Wallet 💳 ₱<?= number_format($conn->query("SELECT Wallet_Balance FROM users WHERE User_ID=$uid")->fetch_assoc()['Wallet_Balance'],2) ?></a>
    <a href="cart.php" class="btn btn-outline">🛒 Cart <span class="cart-count" id="cartCount"><?= $cart_count ?></span></a>
    <a href="../auth/logout.php" class="logout-link btn btn-outline">logout</a>
  </div>
</div>
 
<div class="content">
  <a href="shop.php" class="btn btn-outline1">← Back to home</a>
 
  <!-- Wallet balance hero -->
  <div class="wallet-hero">
    <div class="wallet-content">
      <div class="wallet-chip">Remaining Balance</div>
      <div class="wallet-balance"><span class="currency">₱</span><?= number_format($balance, 2) ?></div>
      <div class="wallet-name"><?= htmlspecialchars($user['Full_Name']) ?></div>
      
    </div>
  </div>
 
  <div class="grid-2">
 
    <!-- Top-up request form -->
    <div class="card">
      <div class="card-title">Request Top-Up</div>
      <?php if (isset($form_error)): ?>
        <div style="background:rgba(192,74,0,0.1);border:1px solid rgba(192,74,0,0.25);color:#c04a00;padding:12px 16px;border-radius:10px;font-size:13px;font-family:var(--mono);margin-bottom:14px">✕ <?= htmlspecialchars($form_error) ?></div>
      <?php endif; ?>
      <div class="info-box">
        Send your payment via <strong>GCash or Maya</strong> to our number, then fill in your reference number below. Staff will verify and credit your Wallet within a few minutes.
      </div>
      <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
          <label>Payment Method</label>
          <select name="payment_method">
            <option>GCash</option>
            <option>Maya</option>
            <option>Cash</option>
          </select>
        </div>
        <div class="form-group">
          <label>Amount (₱)</label>
          <input type="number" name="amount" min="50" step="50" placeholder="Minimum ₱50" required>
          <span class="helper">Minimum top-up: ₱50</span>
        </div>
        <div class="form-group">
          <label>Reference / Transaction No.</label>
          <input type="text" name="reference" placeholder="e.g. 1234567890" required>
          <span class="helper">From your GCash/Maya SMS confirmation</span>
        </div>
        <div class="form-group">
          <label>Payment Proof / Screenshot <span style="color:#c04a00">*</span></label>
          <input type="file" name="proof_image" accept="image/*" required id="proofInput" onchange="previewProof(this)" style="padding:8px 14px;cursor:pointer">
          <span class="helper">Upload screenshot of your GCash/Maya transfer confirmation</span>
          <div id="proofPreviewWrap" style="display:none;margin-top:10px">
            <img id="proofPreview" src="" alt="Proof preview" style="width:100%;max-height:200px;object-fit:contain;border-radius:10px;border:1.5px solid var(--border)">
          </div>
        </div>
        <button type="submit" name="request_topup" class="btn-brown">Submit Top-Up Request</button>
      </form>
    </div>
 
    <!-- Pending requests -->
    <div class="card">
      <div class="card-title">My Top-Up Requests</div>
      <?php
        $requests->data_seek(0);
        $hasReqs = false;
        while ($req = $requests->fetch_assoc()):
          $hasReqs = true;
          $badgeCls = match($req['Status']) { 'approved'=>'badge-approved','rejected'=>'badge-rejected',default=>'badge-pending' };
      ?>
      <div class="req-item">
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--brown-dark)">₱<?= number_format($req['Amount'],2) ?> via <?= $req['Payment_Method'] ?></div>
          <div style="font-size:11px;font-family:var(--mono);color:var(--text-muted)">Ref: <?= htmlspecialchars($req['Reference']) ?> · <?= date('M d, h:i A', strtotime($req['Created_At'])) ?></div>
          <?php if ($req['Note']): ?><div style="font-size:11px;color:#c04a00;margin-top:3px"><?= htmlspecialchars($req['Note']) ?></div><?php endif; ?>
          <?php if (!empty($req['Proof_Image'])): ?>
            <a href="../<?= htmlspecialchars($req['Proof_Image']) ?>" target="_blank" style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;font-size:11px;font-family:var(--mono);color:var(--water-mid);text-decoration:none">
              <img src="../<?= htmlspecialchars($req['Proof_Image']) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">
            </a>
          <?php endif; ?>
        </div>
        <span class="badge <?= $badgeCls ?>"><?= $req['Status'] ?></span>
      </div>
      <?php endwhile; ?>
      <?php if (!$hasReqs): ?>
        <p style="font-size:13px;color:var(--text-muted);text-align:center;padding:20px">No top-up requests yet.</p>
      <?php endif; ?>
    </div>
 
  </div>
 
  <!-- Transaction history -->
  <div class="card">
    <div class="card-title">Transaction History</div>
    <?php
      $hasTx = false;
      while ($tx = $transactions->fetch_assoc()):
        $hasTx = true;
        $isPos = in_array($tx['Type'], ['topup','refund']);
        $icons = ['topup'=>'💰','purchase'=>'🧋','refund'=>'↩️'];
        $txClasses = ['topup'=>'tx-topup','purchase'=>'tx-purchase','refund'=>'tx-refund'];
        $labels = ['topup'=>'Wallet Top-Up','purchase'=>'Order Payment','refund'=>'Refund'];
    ?>
    <div class="tx-item">
      <div class="tx-icon <?= $txClasses[$tx['Type']] ?>"><?= $icons[$tx['Type']] ?></div>
      <div class="tx-info">
        <div class="tx-label"><?= $labels[$tx['Type']] ?></div>
        <div class="tx-date"><?= date('M d, Y · h:i A', strtotime($tx['Created_At'])) ?><?= $tx['Note'] ? ' — '.htmlspecialchars($tx['Note']) : '' ?></div>
      </div>
      <div>
        <div class="<?= $isPos ? 'tx-amount-pos' : 'tx-amount-neg' ?>"><?= $isPos ? '+' : '-' ?>₱<?= number_format($tx['Amount'],2) ?></div>
        <div style="font-size:10px;font-family:var(--mono);color:var(--text-muted);text-align:right">bal: ₱<?= number_format($tx['Balance_After'],2) ?></div>
      </div>
    </div>
    <?php endwhile; ?>
    <?php if (!$hasTx): ?>
      <p style="font-size:13px;color:var(--text-muted);text-align:center;padding:32px">No transactions yet.</p>
    <?php endif; ?>
  </div>
</div>
 
<div class="footer">🧋 Sip &amp; Savor Milk Tea — Wallet never expire</div>
<div class="toast-bar" id="toast"></div>
 
<script>
const p=new URLSearchParams(location.search);
if(p.get('toast')==='requested'){
  const t=document.getElementById('toast');
  t.textContent='✓ Top-up request submitted! We\'ll credit your account shortly.';
  setTimeout(()=>t.classList.add('show'),100);
  setTimeout(()=>t.classList.remove('show'),4000);
  history.replaceState({},'',location.pathname);
}
function previewProof(input) {
  const wrap = document.getElementById('proofPreviewWrap');
  const img  = document.getElementById('proofPreview');
  if (input.files && input.files[0]) {
    img.src = URL.createObjectURL(input.files[0]);
    wrap.style.display = 'block';
  }
}
</script>
</body>
</html>