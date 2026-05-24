<?php
session_start(); // 1. FIX: Initialize session to use $_SESSION
require '../config.php';
$pageTitle = 'Wallet Management';

// Ensure the admin is actually logged in
if (!isset($_SESSION['user_id'])) {
    die("Access Denied: Please log in as admin.");
}

$pageTitle = 'Wallet Management';
$admin = (int)$_SESSION['user_id']; // 2. FIX: Store admin ID early

// Approve / reject top-up request
if (isset($_GET['approve'])) {
    $rid = (int)$_GET['approve'];
    $req = $conn->query("SELECT * FROM topup_requests WHERE Request_ID=$rid AND Status='pending'")->fetch_assoc();
    
    if ($req) {
        $uid    = $req['User_ID'];
        $amount = $req['Amount'];
        $ref    = $conn->real_escape_string($req['Reference']);
        
        // 3. FIX: Ensure clear spacing in the UPDATE query
        $conn->query("UPDATE topup_requests SET Status='approved', Reviewed_By=$admin, Reviewed_At=NOW() WHERE Request_ID=$rid");
        
        $userRow = $conn->query("SELECT Wallet_Balance FROM users WHERE User_ID=$uid")->fetch_assoc();
        $newBal = $userRow['Wallet_Balance'] + $amount;
        
        $conn->query("UPDATE users SET Wallet_Balance=$newBal WHERE User_ID=$uid");
        
        $method = $conn->real_escape_string($req['Payment_Method']);
        $note = $conn->real_escape_string("Top-up via $method ref:$ref");
        
        $conn->query("INSERT INTO wallet_transactions (User_ID, Type, Amount, Balance_After, Reference, Note, Status) 
                      VALUES ($uid, 'topup', $amount, $newBal, '$ref', '$note', 'approved')");
        
        header('Location: wallet.php?toast=approved'); exit;
    }
}

if (isset($_GET['reject'])) {
    $rid  = (int)$_GET['reject'];
    $note = $conn->real_escape_string($_GET['note'] ?? 'Rejected by admin');
    
    // 3. FIX: Ensure clear spacing here as well
    $conn->query("UPDATE topup_requests SET Status='rejected', Reviewed_By=$admin, Reviewed_At=NOW(), Note='$note' WHERE Request_ID=$rid AND Status='pending'");
    
    header('Location: wallet.php?toast=rejected'); exit;
}

// Manual top-up by admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_topup'])) {
    $uid    = (int)$_POST['User_ID'];
    $amount = (float)$_POST['amount'];
    $note   = $conn->real_escape_string(trim($_POST['note']));
    if ($uid && $amount > 0) {
        $newBal = $conn->query("SELECT Wallet_Balance FROM users WHERE User_ID=$uid")->fetch_assoc()['Wallet_Balance'] + $amount;
        $conn->query("UPDATE users SET Wallet_Balance=$newBal WHERE User_ID=$uid");
        $conn->query("INSERT INTO wallet_transactions (User_ID,Type,Amount,Balance_After,Note,Status) VALUES ($uid,'topup',$amount,$newBal,'$note','approved')");
        header('Location: wallet.php?toast=topped'); exit;
    }
}

$pendingRequests = $conn->query("
    SELECT tr.*, u.Full_Name, u.Username
    FROM topup_requests tr
    JOIN users u ON tr.User_ID = u.User_ID
    WHERE tr.Status = 'pending'
    ORDER BY tr.Created_At ASC
");

$allRequests = $conn->query("
    SELECT tr.*, u.Full_Name, u.Username
    FROM topup_requests tr
    JOIN users u ON tr.User_ID = u.User_ID
    ORDER BY tr.Created_At DESC
    LIMIT 30
");

$users = $conn->query("SELECT User_ID, Full_Name, Username, Wallet_Balance FROM users WHERE Role='user' ORDER BY Full_Name");

$pendingCount = $conn->query("SELECT COUNT(*) AS c FROM topup_requests WHERE Status='pending'")->fetch_assoc()['c'];

require '../includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Wallet admin</title>
<style>
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal-box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px;width:100%;max-width:480px;box-shadow:0 24px 60px rgba(0,0,0,0.5)}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border)}
.modal-title{font-size:15px;font-weight:600;color:var(--text);font-family:var(--mono)}
.modal-close{background:none;border:none;color:var(--muted);font-size:22px;cursor:pointer;line-height:1;transition:color 0.15s}.modal-close:hover{color:var(--danger)}
.toast-bar{position:fixed;bottom:28px;right:28px;padding:13px 22px;border-radius:30px;font-weight:700;font-size:13px;z-index:999;box-shadow:0 8px 24px rgba(0,0,0,0.3);transform:translateY(80px);opacity:0;transition:transform 0.4s cubic-bezier(.34,1.56,.64,1),opacity 0.3s;pointer-events:none}
.toast-bar.show{transform:translateY(0);opacity:1}
.toast-success{background:var(--accent);color:#0e0f11}
.toast-danger{background:var(--danger);color:#fff}
.toast-info{background:var(--accent2);color:#0e0f11}
.proof-thumb{width:52px;height:52px;object-fit:cover;border-radius:8px;border:1px solid var(--border);cursor:pointer;transition:transform 0.15s}
.proof-thumb:hover{transform:scale(1.08)}
.proof-btn{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;background:rgba(34,211,238,0.1);color:var(--accent2);border:1px solid rgba(34,211,238,0.25);border-radius:20px;font-size:11px;font-family:var(--mono);cursor:pointer;text-decoration:none;transition:all 0.15s}
.proof-btn:hover{background:rgba(34,211,238,0.2)}
.lightbox-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:600;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
.lightbox-overlay.open{display:flex}
.lightbox-img{max-width:90vw;max-height:85vh;border-radius:12px;object-fit:contain;box-shadow:0 24px 60px rgba(0,0,0,0.5)}
.lightbox-close{position:absolute;top:20px;right:24px;background:rgba(255,255,255,0.1);border:none;color:#fff;font-size:24px;cursor:pointer;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:background 0.15s}
.lightbox-close:hover{background:rgba(255,255,255,0.2)}
.no-proof{font-size:11px;font-family:var(--mono);color:var(--muted);font-style:italic}
</style>
</head>
<div class="page-header">
  <h1 class="page-title">Sip <span>Credits</span></h1>
  <button class="btn btn-primary" onclick="document.getElementById('manualModal').classList.add('open')">+ Manual Top-Up</button>
</div>

<?php if ($pendingCount > 0): ?>
<div style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.25);border-radius:10px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between">
  <span style="font-size:13px;color:var(--warn);font-family:var(--mono)">🕐 <?= $pendingCount ?> top-up request<?= $pendingCount!=1?'s':'' ?> waiting for approval</span>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start">

  <!-- Pending requests -->
  <div class="card">
    <div class="card-title">Pending Top-Up Requests</div>
    <?php
      $hasPending = false;
      $pendingRequests->data_seek(0);
      while ($req = $pendingRequests->fetch_assoc()):
        $hasPending = true;
    ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:10px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px">
        <div>
          <div style="font-size:14px;font-weight:600;color:var(--text)"><?= htmlspecialchars($req['Full_Name']) ?> <span style="font-size:11px;color:var(--muted);font-family:var(--mono)">@<?= $req['Username'] ?></span></div>
          <div style="font-size:13px;color:var(--accent);font-family:var(--mono);margin-top:2px">₱<?= number_format($req['Amount'],2) ?> via <?= $req['Payment_Method'] ?></div>
          <div style="font-size:11px;color:var(--muted);font-family:var(--mono);margin-top:2px">Ref: <?= htmlspecialchars($req['Reference']) ?></div>
          <div style="font-size:11px;color:var(--muted);font-family:var(--mono)"><?= date('M d, Y h:i A', strtotime($req['Created_At'])) ?></div>
        </div>
      </div>
      <?php if (!empty($req['Proof_Image'])): ?>
        <div style="margin:8px 0 10px">
          <img src="../<?= htmlspecialchars($req['Proof_Image']) ?>" class="proof-thumb"
               onclick="openLightbox('../<?= htmlspecialchars($req['Proof_Image']) ?>')" title="Click to view full proof">
          <a href="../<?= htmlspecialchars($req['Proof_Image']) ?>" target="_blank" class="proof-btn" style="margin-left:8px">🖼 View Proof</a>
        </div>
      <?php else: ?>
        <div class="no-proof" style="margin-bottom:10px;font-size:11px;font-family:var(--mono);color:var(--muted)">No proof image attached</div>
      <?php endif; ?>
      <div style="display:flex;gap:8px">
        <a href="?approve=<?= $req['Request_ID'] ?>" class="btn btn-primary btn-sm"
           onclick="return confirm('Approve ₱<?= number_format($req['Amount'],2) ?> for <?= htmlspecialchars($req['Full_Name']) ?>?')">
          ✓ Approve
        </a>
        <button class="btn btn-danger btn-sm" onclick="openRejectModal(<?= $req['Request_ID'] ?>, '<?= htmlspecialchars($req['Full_Name']) ?>')">
          ✕ Reject
        </button>
      </div>
    </div>
    <?php endwhile; ?>
    <?php if (!$hasPending): ?>
      <p style="color:var(--muted);font-size:13px;font-family:var(--mono);text-align:center;padding:20px">No pending requests.</p>
    <?php endif; ?>
  </div>

  <!-- User balances -->
  <div class="card">
    <div class="card-title">Customer Wallet Balances</div>
    <table>
      <thead><tr><th>Customer</th><th>Balance</th></tr></thead>
      <tbody>
        <?php $users->data_seek(0); while ($u=$users->fetch_assoc()): ?>
        <tr>
          <td>
            <div style="font-size:13px;font-weight:600"><?= htmlspecialchars($u['Full_Name']) ?></div>
            <div style="font-size:11px;font-family:var(--mono);color:var(--muted)">@<?= $u['Username'] ?></div>
          </td>
          <td class="mono" style="color:var(--accent);font-weight:600">₱<?= number_format($u['Wallet_Balance'],2) ?></td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>

</div>

<!-- All requests log -->
<div class="card">
  <div class="card-title">All Top-Up Requests</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Customer</th><th>Amount</th><th>Method</th><th>Reference</th><th>Proof</th><th>Status</th><th>Note</th></tr></thead>
      <tbody>
        <?php $allRequests->data_seek(0); while ($req=$allRequests->fetch_assoc()):
          $b=match($req['Status']){'approved'=>'badge-green','rejected'=>'badge-red',default=>'badge-yellow'};
        ?>
        <tr>
          <td class="mono" style="font-size:11px"><?= date('M d, Y h:i A',strtotime($req['Created_At'])) ?></td>
          <td><?= htmlspecialchars($req['Full_Name']) ?></td>
          <td class="mono">₱<?= number_format($req['Amount'],2) ?></td>
          <td><?= $req['Payment_Method'] ?></td>
          <td class="mono"><?= htmlspecialchars($req['Reference']) ?></td>
          <td><span class="badge <?= $b ?>"><?= $req['Status'] ?></span></td>
          <td>
            <?php if (!empty($req['Proof_Image'])): ?>
              <img src="../<?= htmlspecialchars($req['Proof_Image']) ?>" class="proof-thumb"
                   onclick="openLightbox('../<?= htmlspecialchars($req['Proof_Image']) ?>')" title="View proof">
            <?php else: ?>
              <span class="no-proof">—</span>
            <?php endif; ?>
          </td>

        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Manual Top-Up Modal -->
<div class="modal-overlay" id="manualModal">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">Manual Top-Up</span>
      <button class="modal-close" onclick="document.getElementById('manualModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <div class="form-group">
        <label>Customer</label>
        <select name="User_ID" required>
          <option value="">— Select customer —</option>
          <?php $users->data_seek(0); while ($u=$users->fetch_assoc()): ?>
            <option value="<?= $u['User_ID'] ?>"><?= htmlspecialchars($u['Full_Name']) ?> (@<?= $u['Username'] ?>) — ₱<?= number_format($u['Wallet_Balance'],2) ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Amount (₱)</label>
        <input type="number" name="amount" min="1" step="0.01" required placeholder="e.g. 100">
      </div>
      <div class="form-group">
        <label>Note (optional)</label>
        <input type="text" name="note" placeholder="e.g. Walk-in cash top-up">
      </div>
      <div class="form-actions">
        <button type="submit" name="manual_topup" class="btn btn-primary">+ Add Credits</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('manualModal').classList.remove('open')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">Reject Top-Up Request</span>
      <button class="modal-close" onclick="document.getElementById('rejectModal').classList.remove('open')">✕</button>
    </div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px" id="rejectName"></p>
    <div class="form-group">
      <label>Reason for rejection</label>
      <input type="text" id="rejectNote" placeholder="e.g. Reference number not found" value="Reference number not found">
    </div>
    <div class="form-actions">
      <a href="#" id="rejectConfirmLink" class="btn btn-danger">✕ Confirm Reject</a>
      <button class="btn btn-ghost" onclick="document.getElementById('rejectModal').classList.remove('open')">Cancel</button>
    </div>
  </div>
</div>

<div class="toast-bar" id="toastEl"></div>

<script>
function openRejectModal(rid, name) {
  document.getElementById('rejectName').textContent = 'Rejecting request from: ' + name;
  document.getElementById('rejectConfirmLink').href = '#';
  document.getElementById('rejectConfirmLink').onclick = function() {
    const note = encodeURIComponent(document.getElementById('rejectNote').value);
    window.location = '?reject=' + rid + '&note=' + note;
    return false;
  };
  document.getElementById('rejectModal').classList.add('open');
}

const msgs = {
  approved: { text: '✓ Top-up approved & balance credited!', cls: 'toast-success' },
  rejected: { text: '✕ Request rejected', cls: 'toast-danger' },
  topped:   { text: '✓ Credits added manually!', cls: 'toast-success' },
};
const p=new URLSearchParams(location.search),key=p.get('toast');
if(key&&msgs[key]){
  const t=document.getElementById('toastEl');
  t.textContent=msgs[key].text;t.classList.add(msgs[key].cls);
  setTimeout(()=>t.classList.add('show'),100);
  setTimeout(()=>t.classList.remove('show'),3500);
  history.replaceState({},'',location.pathname);
}
</script>
</body>
</html>
<?php require '../includes/footer.php'; ?>