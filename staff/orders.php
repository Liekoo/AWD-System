<?php
/**
 * STAFF — staff/orders.php
 * -------------------------------------------------------
 * WHO SEES THIS: Staff role only
 * PURPOSE: Manage and process incoming orders via modal
 *
 * SERVICE TYPES:
 *   🚚 Delivery  — standard product delivery to customer
 *   💧 Refill    — customer's container refill service
 *
 * WORKFLOW:
 *   Pending          → [Prepare]              → Preparing
 *   Preparing        → checkboxes → [Take Selected Orders] → Out for Delivery
 *                       ↳ AUTO-ASSIGNS logged-in staff as Rider for ALL selected
 *                       ↳ LOCKS those orders — other riders cannot pick them
 *   Out for Delivery → upload proof photo → [Mark Completed]
 *                       ↳ Mark Completed is BLOCKED until proof is uploaded
 *   Pending/Preparing → [Cancel] → Cancelled (stock restored)
 * -------------------------------------------------------
 */
require '../config.php';
require_once '../includes/auth_check.php';
$pageTitle  = 'Orders';
$staff_id   = $_SESSION['user_id']   ?? 0;
$staff_name = $_SESSION['full_name'] ?? 'Staff';

define('TRACKER_URL', 'http://localhost:3000');
define('POD_DIR',     '../uploads/pod/');
define('POD_WEB',     'uploads/pod/');

// ── AJAX: Upload proof of delivery ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
    header('Content-Type: application/json');
    $oid = (int)($_POST['order_id'] ?? 0);
    if (!$oid) { echo json_encode(['ok'=>false,'msg'=>'Invalid order.']); exit; }

    $check = $conn->query("SELECT Order_Status, Rider_ID FROM orders WHERE Order_ID=$oid")->fetch_assoc();
    if (!$check || $check['Rider_ID'] != $staff_id || $check['Order_Status'] !== 'Out for Delivery') {
        echo json_encode(['ok'=>false,'msg'=>'Not authorised.']); exit;
    }
    if (empty($_FILES['proof']['tmp_name'])) {
        echo json_encode(['ok'=>false,'msg'=>'No file received.']); exit;
    }

    $file    = $_FILES['proof'];
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($file['type'], $allowed)) { echo json_encode(['ok'=>false,'msg'=>'Only JPG/PNG/WEBP allowed.']); exit; }
    if ($file['size'] > 8 * 1024 * 1024)   { echo json_encode(['ok'=>false,'msg'=>'Max 8 MB.']); exit; }

    if (!is_dir(POD_DIR)) mkdir(POD_DIR, 0775, true);

    $ext  = match($file['type']) { 'image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif',default=>'jpg' };
    foreach (glob(POD_DIR . $oid . '.*') as $old) @unlink($old);
    $dest = POD_DIR . $oid . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['ok'=>false,'msg'=>'Upload failed — check folder permissions.']); exit;
    }

    $webPath = POD_WEB . $oid . '.' . $ext;
    $conn->query("UPDATE orders SET Proof_Image='".  $conn->real_escape_string($webPath)."', Proof_Uploaded_At=NOW() WHERE Order_ID=$oid");
    echo json_encode(['ok'=>true,'path'=>$webPath]);
    exit;
}

// ── AJAX: Batch dispatch selected orders ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'batch_dispatch') {
    header('Content-Type: application/json');
    $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
    if (empty($ids)) { echo json_encode(['ok'=>false,'msg'=>'No orders selected.']); exit; }

    $idList    = implode(',', $ids);
    $riderName = $conn->real_escape_string($staff_name);
    $conn->query("UPDATE orders
                  SET Order_Status='Out for Delivery',
                      Rider_ID=$staff_id,
                      Rider_Name='$riderName',
                      Dispatched_At=NOW()
                  WHERE Order_ID IN ($idList)
                    AND Order_Status='Preparing'
                    AND (Rider_ID IS NULL OR Rider_ID=0)");

    echo json_encode(['ok'=>true,'dispatched'=>$conn->affected_rows]);
    exit;
}

// ── Handle status transitions (existing GET-based) ─────────────────────────────
if (isset($_GET['action'], $_GET['id'])) {
    $id      = (int)$_GET['id'];
    $action  = $_GET['action'];
    $filter  = $_GET['filter']  ?? 'all';
    $service = $_GET['service'] ?? 'all';

    $newStatus = match($action) {
        'prepare'  => 'Preparing',
        'ready'    => 'Out for Delivery',
        'complete' => 'Completed',
        'cancel'   => 'Cancelled',
        default    => null
    };

    if ($newStatus) {
        $extraCols = '';

        if ($newStatus === 'Out for Delivery') {
            $riderName = $conn->real_escape_string($staff_name);
            $extraCols = ", Rider_ID=$staff_id, Rider_Name='$riderName', Dispatched_At=NOW()";
        }

        if ($newStatus === 'Completed') {
            // Guard: proof required
            $proof = $conn->query("SELECT Proof_Image, Rider_ID FROM orders WHERE Order_ID=$id")->fetch_assoc();
            if (!$proof || empty($proof['Proof_Image'])) {
                header("Location: orders.php?filter=$filter&service=$service&toast=noproof"); exit;
            }
            if ($proof['Rider_ID'] != $staff_id) {
                header("Location: orders.php?filter=$filter&service=$service&toast=notowner"); exit;
            }
            $extraCols = ', Completed_At=NOW()';
        }

        $conn->query("UPDATE orders SET Order_Status='$newStatus' $extraCols WHERE Order_ID=$id");

        if ($newStatus === 'Cancelled') {
            $order = $conn->query("SELECT Product_ID,Order_Quantity FROM orders WHERE Order_ID=$id")->fetch_assoc();
            if ($order) {
                $conn->query("UPDATE products SET Product_Quantity_Stock=Product_Quantity_Stock+{$order['Order_Quantity']} WHERE Product_ID={$order['Product_ID']}");
            }
        }

        $toast = match($newStatus) {
            'Preparing'        => 'preparing',
            'Out for Delivery' => 'ready',
            'Completed'        => 'completed',
            'Cancelled'        => 'cancelled',
            default            => 'updated'
        };
    }
    header("Location: orders.php?filter=$filter&service=$service&toast=$toast"); exit;
}

// ── Filters ────────────────────────────────────────────────────────────────────
$filter  = $_GET['filter']  ?? 'all';
$view    = $_GET['view']    ?? 'active';
$service = $_GET['service'] ?? 'all';

$statusClause = match($filter) {
    'pending'   => "o.Order_Status='Pending'",
    'preparing' => "o.Order_Status='Preparing'",
    'ready'     => "o.Order_Status='Out for Delivery'",
    'mine'      => "o.Order_Status='Out for Delivery' AND o.Rider_ID=$staff_id",
    'done'      => "o.Order_Status IN ('Completed','Cancelled')",
    default     => "o.Order_Status NOT IN ('Completed','Cancelled')"
};
if ($view === 'logs') $statusClause = "o.Order_Status IN ('Completed','Cancelled')";

$serviceClause = match($service) {
    'delivery' => "o.Order_Type='Delivery'",
    'refill'   => "o.Order_Type='Refill'",
    default    => '1=1'
};

$whereClause = "WHERE ($statusClause) AND ($serviceClause)";

// ── Main query (existing + Proof_Image + Full_Name from shipping_addresses) ────
$orders = $conn->query("
    SELECT o.*,
           p.Product_Name, p.Product_Image,
           s.Size_Label, s.Size_Name,
           pt.Payment_Type_Description,
           sa.Full_Name AS Ship_Name,
           sa.Address   AS Ship_Address,
           sa.City      AS Ship_City,
           sa.Province  AS Ship_Province,
           sa.Zip_Code  AS Ship_Zip,
           sa.Phone     AS Ship_Phone,
           sa.Latitude  AS Dest_Lat,
           sa.Longitude AS Dest_Lng
    FROM orders o
    JOIN products p       ON o.Product_ID      = p.Product_ID
    JOIN payments_type pt ON o.Payment_Type_ID  = pt.Payment_Type_ID
    LEFT JOIN sizes s     ON o.Size_ID          = s.Size_ID
    LEFT JOIN shipping_addresses sa ON o.Address_ID = sa.Address_ID
    $whereClause
    ORDER BY
      FIELD(o.Order_Status,'Pending','Preparing','Out for Delivery','Completed','Cancelled'),
      o.Order_Date_Time DESC
");

// ── Counts ─────────────────────────────────────────────────────────────────────
$svcWhere = match($service) {
    'delivery' => "AND Order_Type='Delivery'",
    'refill'   => "AND Order_Type='Refill'",
    default    => ''
};
$counts = [];
foreach (['Pending','Preparing','Out for Delivery','Completed','Cancelled'] as $st) {
    $esc = $conn->real_escape_string($st);
    $counts[$st] = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Status='$esc' $svcWhere")->fetch_assoc()['c'];
}
$counts['all'] = $counts['Pending'] + $counts['Preparing'] + $counts['Out for Delivery'];
$activeCount   = $counts['all'];
$logsCount     = $counts['Completed'] + $counts['Cancelled'];

$deliveryTotal = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Type='Delivery' AND Order_Status NOT IN ('Completed','Cancelled')")->fetch_assoc()['c'];
$refillTotal   = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Type='Refill'   AND Order_Status NOT IN ('Completed','Cancelled')")->fetch_assoc()['c'];

$myDeliveries  = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Rider_ID=$staff_id AND Order_Status='Out for Delivery'")->fetch_assoc()['c'];
$availToPick   = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Status='Preparing' AND (Rider_ID IS NULL OR Rider_ID=0)")->fetch_assoc()['c'];

require '../includes/staff_header.php';
?>

<style>
/* ══════════════════ EXISTING STYLES (unchanged) ══════════════════ */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal-box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,0.5)}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border)}
.modal-title{font-size:15px;font-weight:600;color:var(--text);font-family:var(--mono)}
.modal-close{background:none;border:none;color:var(--muted);font-size:22px;cursor:pointer;line-height:1;transition:color 0.15s}.modal-close:hover{color:var(--danger)}
.toast-bar{position:fixed;bottom:28px;right:28px;padding:13px 22px;border-radius:30px;font-weight:700;font-size:13px;z-index:999;display:flex;align-items:center;gap:8px;box-shadow:0 8px 24px rgba(0,0,0,0.3);transform:translateY(80px);opacity:0;transition:transform 0.4s cubic-bezier(.34,1.56,.64,1),opacity 0.3s;pointer-events:none}
.toast-bar.show{transform:translateY(0);opacity:1}
.toast-success{background:var(--accent2);color:#0e0f11}
.toast-ready{background:var(--accent);color:#0e0f11}
.toast-done{background:#7c3aed;color:#fff}
.toast-cancel{background:var(--danger);color:#fff}
.toast-error{background:#b91c1c;color:#fff}
.svc-switcher{display:flex;gap:8px;margin-bottom:16px}
.svc-btn{padding:8px 18px;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:all 0.15s;border:1.5px solid var(--border);color:var(--muted);background:var(--card);font-family:var(--mono)}
.svc-btn.active-all{background:rgba(107,114,128,0.1);color:var(--text);border-color:var(--border)}
.svc-btn.active-delivery{background:rgba(74,222,128,0.1);color:var(--accent);border-color:rgba(74,222,128,0.35)}
.svc-btn.active-refill{background:rgba(99,102,241,0.1);color:#a5b4fc;border-color:rgba(99,102,241,0.35)}
.svc-btn .cnt{border-radius:20px;padding:1px 7px;font-size:11px;background:rgba(0,0,0,0.15)}
.view-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.view-tab{padding:7px 16px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all 0.15s;border:1px solid var(--border);color:var(--muted);background:var(--card)}
.view-tab.active{background:var(--accent);color:#0e0f11;border-color:var(--accent)}
.view-tab .cnt{border-radius:20px;padding:1px 7px;font-size:11px;font-family:var(--mono);background:rgba(0,0,0,0.15)}
.view-tab:not(.active) .cnt{background:var(--border);color:var(--text)}
.rider-chip{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:20px;font-size:10px;font-family:var(--mono);font-weight:600;background:rgba(74,222,128,0.1);color:var(--accent);border:1px solid rgba(74,222,128,0.25)}
.rider-chip.mine{background:rgba(99,102,241,0.15);color:#a5b4fc;border-color:rgba(99,102,241,0.3)}
.tracker-link-box{display:flex;align-items:center;gap:10px;padding:11px 14px;background:rgba(74,222,128,0.06);border:1px solid rgba(74,222,128,0.2);border-radius:10px;margin-top:12px}
.tracker-url{flex:1;font-family:var(--mono);font-size:11px;color:var(--accent);word-break:break-all}
.btn-copy-url{flex-shrink:0;padding:6px 12px;border-radius:8px;font-size:11px;font-weight:700;font-family:var(--mono);cursor:pointer;border:1px solid rgba(74,222,128,0.3);color:var(--accent);background:rgba(74,222,128,0.08);transition:all 0.15s}
.btn-copy-url:hover{background:rgba(74,222,128,0.2)}
.btn-copy-url.copied{background:var(--accent);color:#0e0f11;border-color:var(--accent)}
.delivery-addr-block{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-top:10px;font-size:12px;color:var(--muted);font-family:var(--mono);line-height:1.7}
.delivery-addr-block strong{color:var(--text);font-size:13px}
.my-deliveries-banner{display:flex;align-items:center;justify-content:space-between;padding:11px 18px;border-radius:10px;margin-bottom:16px;background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.25)}
.my-deliveries-banner span{font-size:13px;color:#a5b4fc;font-family:var(--mono)}
.my-deliveries-banner a{font-size:12px;font-weight:700;color:#a5b4fc;text-decoration:none;padding:4px 12px;border-radius:20px;border:1px solid rgba(99,102,241,0.35);transition:all 0.15s}
.svc-badge-card{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:10px;font-family:var(--mono);font-weight:700}
.svc-delivery-card{background:rgba(74,222,128,0.1);color:var(--accent);border:1px solid rgba(74,222,128,0.25)}
.svc-refill-card{background:rgba(99,102,241,0.1);color:#a5b4fc;border:1px solid rgba(99,102,241,0.3)}

/* ══════════════════ NEW STYLES: batch + lock + proof ══════════════════ */

/* Order card wrapper — now flex row with checkbox column */
.order-card-row{
  background:var(--card);border:1px solid var(--border);
  border-radius:12px;overflow:hidden;display:flex;
  transition:border-color 0.15s,box-shadow 0.15s;
}
.order-card-row:hover{border-color:var(--muted)}
.order-card-row.selected{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent)}
.order-card-row.locked{opacity:0.55}
.order-card-row.mine-riding{border-left:3px solid #818cf8}

/* Checkbox column */
.cb-col{
  width:50px;min-width:50px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  padding:0 12px;border-right:1px solid var(--border);
}
.cb-col input[type=checkbox]{
  width:17px;height:17px;cursor:pointer;accent-color:var(--accent);
}

/* Sticky batch bar at bottom */
.batch-bar{
  position:sticky;bottom:0;left:0;right:0;z-index:200;
  background:var(--card);border-top:2px solid var(--accent);
  padding:14px 20px;
  display:none;align-items:center;justify-content:space-between;gap:16px;
  box-shadow:0 -6px 30px rgba(0,0,0,0.5);flex-wrap:wrap;
}
.batch-bar.visible{display:flex}
.batch-info{font-family:var(--mono);font-size:13px;color:var(--text)}
.batch-info .b-cnt{display:inline-block;background:var(--accent);color:#0e0f11;border-radius:20px;padding:2px 10px;font-weight:700;font-size:12px;margin-right:6px}
.batch-info .b-total{color:var(--accent);font-weight:700;font-size:16px;margin-left:4px}
.btn-take{padding:11px 24px;border-radius:10px;font-size:13px;font-weight:700;font-family:var(--mono);cursor:pointer;border:none;background:var(--accent);color:#0e0f11;transition:all 0.2s}
.btn-take:hover{opacity:0.85;transform:translateY(-1px)}
.btn-take:disabled{opacity:0.4;cursor:not-allowed;transform:none}
.btn-clr{padding:11px 16px;border-radius:10px;font-size:13px;font-weight:600;font-family:var(--mono);cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--muted);transition:all 0.15s}
.btn-clr:hover{border-color:var(--danger);color:var(--danger)}

/* Proof of delivery upload */
.pod-zone{
  border:2px dashed var(--border);border-radius:10px;
  padding:22px;text-align:center;cursor:pointer;
  transition:border-color 0.2s,background 0.2s;margin-top:12px;
}
.pod-zone:hover,.pod-zone.over{border-color:var(--accent);background:rgba(74,222,128,0.05)}
.pod-zone input[type=file]{display:none}
.pod-preview-img{width:100%;max-height:180px;border-radius:8px;object-fit:cover;margin-top:10px;border:1px solid var(--border);display:none}
.pod-status{font-size:12px;font-family:var(--mono);color:var(--muted);margin-top:6px}

/* Complete button */
.btn-complete{
  padding:10px 22px;border-radius:10px;font-size:13px;font-weight:700;
  font-family:var(--mono);cursor:pointer;border:none;
  background:#7c3aed;color:#fff;transition:all 0.2s;
  display:inline-flex;align-items:center;gap:6px;text-decoration:none;
}
.btn-complete:hover{opacity:0.85}
.btn-complete[data-locked]{background:var(--border);color:var(--muted);cursor:not-allowed;pointer-events:none}
.proof-note{font-size:11px;font-family:var(--mono);color:var(--danger);margin-top:5px;display:flex;align-items:center;gap:5px}

/* Proof badge on card */
.proof-badge{font-size:10px;background:rgba(74,222,128,0.1);color:var(--accent);border:1px solid rgba(74,222,128,0.25);padding:2px 8px;border-radius:20px;font-family:var(--mono)}
</style>

<div class="page-header">
  <h1 class="page-title">Or<span>ders</span></h1>
  <div style="display:flex;align-items:center;gap:12px">
    <span style="font-size:12px;font-family:var(--mono);color:var(--muted)"><?= date('M d, Y · h:i A') ?></span>
    <span style="font-size:11px;font-family:var(--mono);color:var(--muted);background:var(--surface);border:1px solid var(--border);padding:3px 10px;border-radius:20px">
      🚐 <?= htmlspecialchars($staff_name) ?>
    </span>
  </div>
</div>

<!-- Stat cards (existing, unchanged) -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px">
  <?php
  $stats = [
    ['Pending',          $counts['Pending'],          'var(--warn)',   '🕐'],
    ['Preparing',        $counts['Preparing'],        'var(--accent2)','👨‍🍳'],
    ['Out for Delivery', $counts['Out for Delivery'], 'var(--accent)', '✅'],
    ['Completed',        $counts['Completed'],        'var(--muted)',  '☑️'],
    ['Cancelled',        $counts['Cancelled'],        'var(--danger)', '✕'],
  ];
  foreach ($stats as [$label,$count,$color,$icon]): ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden">
    <div style="height:3px;background:<?= $color ?>"></div>
    <div style="padding:12px 14px">
      <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:5px"><?= $icon ?> <?= $label ?></div>
      <div style="font-size:24px;font-weight:700;color:<?= $color ?>;letter-spacing:-1px"><?= $count ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Service type switcher (existing, unchanged) -->
<div style="margin-bottom:16px">
  <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Service Type</div>
  <div class="svc-switcher">
    <a href="?view=<?= $view ?>&filter=<?= $filter ?>&service=all"
       class="svc-btn <?= $service==='all'?'active-all':'' ?>">📋 All Services <span class="cnt"><?= $deliveryTotal+$refillTotal ?></span></a>
    <a href="?view=<?= $view ?>&filter=<?= $filter ?>&service=delivery"
       class="svc-btn <?= $service==='delivery'?'active-delivery':'' ?>">🚚 Delivery <span class="cnt"><?= $deliveryTotal ?></span></a>
    <a href="?view=<?= $view ?>&filter=<?= $filter ?>&service=refill"
       class="svc-btn <?= $service==='refill'?'active-refill':'' ?>">💧 Refill <span class="cnt"><?= $refillTotal ?></span></a>
  </div>
</div>

<?php if ($service !== 'all'): ?>
<div style="background:<?= $service==='refill'?'rgba(99,102,241,0.08)':'rgba(74,222,128,0.06)' ?>;border:1px solid <?= $service==='refill'?'rgba(99,102,241,0.25)':'rgba(74,222,128,0.25)' ?>;border-radius:10px;padding:10px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between">
  <span style="font-size:12px;font-family:var(--mono);color:<?= $service==='refill'?'#a5b4fc':'var(--accent)' ?>">
    <?= $service==='refill'?'💧 Showing Refill orders only':'🚚 Showing Delivery orders only' ?>
  </span>
  <a href="?view=<?= $view ?>&filter=<?= $filter ?>&service=all" style="font-size:11px;font-family:var(--mono);color:var(--muted);text-decoration:none">Clear ✕</a>
</div>
<?php endif; ?>

<!-- My deliveries banner (existing, unchanged) -->
<?php if ($myDeliveries > 0): ?>
<div class="my-deliveries-banner">
  <span>🚐 You are actively riding <strong><?= $myDeliveries ?></strong> order<?= $myDeliveries!=1?'s':'' ?></span>
  <a href="?filter=ready&service=<?= $service ?>">View my deliveries →</a>
</div>
<?php endif; ?>

<!-- Pending alert (existing, unchanged) -->
<?php if ($counts['Pending'] > 0): ?>
<div style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.25);border-radius:10px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">
  <span style="font-size:13px;color:var(--warn);font-family:var(--mono)">🕐 <?= $counts['Pending'] ?> order<?= $counts['Pending']!=1?'s':'' ?> waiting to be prepared</span>
  <a href="?filter=pending&service=<?= $service ?>" class="btn btn-sm" style="background:var(--warn);color:#0e0f11;font-weight:700">View Pending →</a>
</div>
<?php endif; ?>

<!-- NEW: batch pick hint -->
<?php if ($availToPick > 0 && in_array($filter, ['all','preparing'])): ?>
<div style="background:rgba(34,211,238,0.06);border:1px solid rgba(34,211,238,0.2);border-radius:10px;padding:11px 18px;margin-bottom:14px;font-size:13px;color:var(--accent2);font-family:var(--mono)">
  👨‍🍳 <strong><?= $availToPick ?></strong> order<?= $availToPick!=1?'s':'' ?> ready to pick for delivery — tick the checkboxes then tap <strong>Take Selected Orders</strong>
</div>
<?php endif; ?>

<!-- View tabs (existing + new "My Deliveries" tab) -->
<div class="view-tabs">
  <a href="?view=active&filter=all&service=<?= $service ?>"       class="view-tab <?= $view==='active'&&$filter==='all'?'active':'' ?>">All Active <span class="cnt"><?= $activeCount ?></span></a>
  <a href="?view=active&filter=pending&service=<?= $service ?>"   class="view-tab <?= $filter==='pending'?'active':'' ?>">Pending <span class="cnt"><?= $counts['Pending'] ?></span></a>
  <a href="?view=active&filter=preparing&service=<?= $service ?>" class="view-tab <?= $filter==='preparing'?'active':'' ?>">Preparing <span class="cnt"><?= $counts['Preparing'] ?></span></a>
  <a href="?view=active&filter=ready&service=<?= $service ?>"     class="view-tab <?= $filter==='ready'?'active':'' ?>">Out for Delivery <span class="cnt"><?= $counts['Out for Delivery'] ?></span></a>
  <?php if ($myDeliveries > 0): ?>
  <a href="?filter=mine&service=<?= $service ?>"                  class="view-tab <?= $filter==='mine'?'active':'' ?>" style="border-color:rgba(99,102,241,0.4);color:#a5b4fc">🚐 Mine <span class="cnt"><?= $myDeliveries ?></span></a>
  <?php endif; ?>
  <a href="?view=logs&service=<?= $service ?>"                    class="view-tab <?= $view==='logs'?'active':'' ?>">Order Logs <span class="cnt"><?= $logsCount ?></span></a>
</div>

<!-- Orders list -->
<?php if ($orders->num_rows === 0): ?>
  <div style="text-align:center;padding:60px;color:var(--muted);font-family:var(--mono);font-size:14px">No orders found for this filter.</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:10px;padding-bottom:80px" id="orderList">
  <?php while ($row = $orders->fetch_assoc()):
    $status   = $row['Order_Status'];
    $svcType  = $row['Order_Type'] ?? 'Delivery';
    $hasRider = !empty($row['Rider_ID']);
    $isLocked = $hasRider && (int)$row['Rider_ID'] !== (int)$staff_id;
    $isMine   = $hasRider && (int)$row['Rider_ID'] === (int)$staff_id;
    $canSelect = ($status === 'Preparing') && !$hasRider;
    $hasProof  = !empty($row['Proof_Image']);

    [$clr,$bgClr,$borderClr] = match($status) {
      'Pending'          => ['var(--warn)',   'rgba(251,191,36,0.08)',  'rgba(251,191,36,0.25)'],
      'Preparing'        => ['var(--accent2)','rgba(34,211,238,0.08)',  'rgba(34,211,238,0.25)'],
      'Out for Delivery' => ['var(--accent)', 'rgba(74,222,128,0.08)', 'rgba(74,222,128,0.25)'],
      'Completed'        => ['var(--muted)',  'rgba(107,114,128,0.06)','rgba(107,114,128,0.2)'],
      'Cancelled'        => ['var(--danger)', 'rgba(248,113,113,0.06)','rgba(248,113,113,0.2)'],
      default            => ['var(--muted)',  'rgba(107,114,128,0.06)','rgba(107,114,128,0.2)'],
    };

    // Card classes
    $cls = 'order-card-row';
    if ($isMine && $status === 'Out for Delivery') $cls .= ' mine-riding';
    if ($isLocked) $cls .= ' locked';
  ?>
  <div class="<?= $cls ?>"
       data-id="<?= $row['Order_ID'] ?>"
       data-total="<?= floatval($row['Order_Total']) ?>">

    <!-- Status accent bar -->
    <div style="width:4px;background:<?= $clr ?>;flex-shrink:0"></div>

    <!-- NEW: Checkbox column -->
    <div class="cb-col">
      <?php if ($canSelect): ?>
        <input type="checkbox" class="order-cb"
               data-id="<?= $row['Order_ID'] ?>"
               data-total="<?= floatval($row['Order_Total']) ?>"
               onchange="onCbChange(this)">
      <?php elseif ($isLocked): ?>
        <span title="Picked by <?= htmlspecialchars($row['Rider_Name'] ?? 'another rider') ?>" style="font-size:15px;opacity:0.5">🔒</span>
      <?php elseif ($isMine && $status === 'Preparing'): ?>
        <span title="Picked by you" style="font-size:15px">✅</span>
      <?php else: ?>
        <span style="opacity:0.15;font-size:13px">—</span>
      <?php endif; ?>
    </div>

    <!-- Product image (existing, unchanged) -->
    <div style="width:72px;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:10px;border-right:1px solid var(--border)">
      <?php if (!empty($row['Product_Image'])): ?>
        <img src="../<?= htmlspecialchars($row['Product_Image']) ?>" style="width:50px;height:50px;border-radius:8px;object-fit:cover;border:1px solid var(--border)">
      <?php else: ?>
        <div style="width:50px;height:50px;border-radius:8px;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:20px">💧</div>
      <?php endif; ?>
    </div>

    <!-- Order info — clicking opens modal (existing layout, unchanged) -->
    <div style="flex:1;padding:12px 16px;display:flex;flex-direction:column;gap:4px;cursor:pointer"
         onclick='openOrderModal(<?= json_encode($row) ?>)'>

      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="font-family:var(--mono);font-size:11px;color:var(--muted)">#<?= $row['Order_ID'] ?></span>
        <span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:600;background:<?= $bgClr ?>;color:<?= $clr ?>;border:1px solid <?= $borderClr ?>"><?= $status ?></span>
        <span class="svc-badge-card <?= strtolower($svcType)==='refill'?'svc-refill-card':'svc-delivery-card' ?>">
          <?= strtolower($svcType)==='refill'?'💧 Refill':'🚚 Delivery' ?>
        </span>
        <span style="font-size:11px;color:var(--muted);font-family:var(--mono)"><?= date('M d · h:i A', strtotime($row['Order_Date_Time'])) ?></span>
        <?php if (!empty($row['Order_Note'])): ?>
          <span style="font-size:10px;background:rgba(251,191,36,0.1);color:var(--warn);border:1px solid rgba(251,191,36,0.2);padding:2px 8px;border-radius:20px;font-family:var(--mono)">📝 note</span>
        <?php endif; ?>
        <?php if ($hasProof): ?>
          <span class="proof-badge">📷 proof</span>
        <?php endif; ?>
      </div>

      <div style="font-size:14px;font-weight:600;color:var(--text)">
        <?= htmlspecialchars($row['Product_Name']) ?>
        <?php if (!empty($row['Size_Label'])): ?>
          <span style="font-size:10px;font-family:var(--mono);color:var(--muted);background:var(--surface);border:1px solid var(--border);padding:1px 6px;border-radius:6px;margin-left:4px"><?= $row['Size_Label'] ?></span>
        <?php endif; ?>
      </div>

      <div style="font-size:11px;color:var(--muted);font-family:var(--mono)">
        👤 <?= htmlspecialchars($row['Customer_Name'] ?: 'Guest') ?> &nbsp;·&nbsp;
        💳 <?= htmlspecialchars($row['Payment_Type_Description']) ?> &nbsp;·&nbsp;
        Qty: <?= $row['Order_Quantity'] ?> &nbsp;·&nbsp;
        <span style="color:var(--accent)">₱<?= number_format($row['Order_Total'],2) ?></span>
      </div>

      <?php if (!empty($row['Rider_Name'])): ?>
        <div style="margin-top:3px">
          <span class="rider-chip <?= $isMine?'mine':'' ?>">
            🚐 <?= $isMine ? 'You ('.$staff_name.')' : htmlspecialchars($row['Rider_Name']) ?>
          </span>
          <?php if (!empty($row['Dispatched_At'])): ?>
            <span style="font-size:10px;font-family:var(--mono);color:var(--muted);margin-left:6px">dispatched <?= date('h:i A', strtotime($row['Dispatched_At'])) ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($row['Ship_Address'])): ?>
        <div style="font-size:11px;font-family:var(--mono);color:var(--muted);margin-top:1px">
          📍 <?= htmlspecialchars($row['Ship_Address'].', '.$row['Ship_City']) ?>
        </div>
      <?php endif; ?>
    </div>

    <div style="display:flex;align-items:center;padding:0 14px;flex-shrink:0">
      <span style="font-size:11px;color:var(--muted);font-family:var(--mono)">tap →</span>
    </div>
  </div>
  <?php endwhile; ?>
</div>
<?php endif; ?>

<!-- NEW: Sticky batch bar -->
<div class="batch-bar" id="batchBar">
  <div class="batch-info">
    <span class="b-cnt" id="batchCnt">0</span>
    order<span id="batchPlural">s</span> selected &nbsp;·&nbsp; Total:
    <span class="b-total" id="batchTotal">₱0.00</span>
  </div>
  <div style="display:flex;gap:8px">
    <button class="btn-clr" onclick="clearSel()">✕ Clear</button>
    <button class="btn-take" onclick="openDispatchModal()">🚐 Take Selected Orders</button>
  </div>
</div>

<!-- ORDER ACTION MODAL (existing structure, extended with proof + complete guard) -->
<div class="modal-overlay" id="orderModal">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="mTitle">Order Details</span>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div id="mContent"></div>
    <div id="mActions" style="display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border);flex-wrap:wrap;align-items:center"></div>
  </div>
</div>

<!-- NEW: Batch dispatch confirm modal -->
<div class="modal-overlay" id="dispatchModal">
  <div class="modal-box" style="max-width:460px">
    <div class="modal-header">
      <span class="modal-title">🚐 Confirm — Take These Orders</span>
      <button class="modal-close" onclick="document.getElementById('dispatchModal').classList.remove('open')">✕</button>
    </div>
    <div id="dispatchContent"></div>
    <div style="display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
      <button class="btn-take" id="btnDoDispatch" onclick="doDispatch()">✅ Yes, I'm the Rider for All</button>
      <button class="btn-clr" onclick="document.getElementById('dispatchModal').classList.remove('open')">Cancel</button>
    </div>
  </div>
</div>

<div class="toast-bar" id="toastEl"></div>

<script>
/* ═══════════ EXISTING GLOBALS ═══════════ */
const STAFF_ID    = <?= $staff_id ?>;
const STAFF_NAME  = <?= json_encode($staff_name) ?>;
const TRACKER_URL = <?= json_encode(TRACKER_URL) ?>;
const CUR_FILTER  = <?= json_encode($filter) ?>;
const CUR_SERVICE = <?= json_encode($service) ?>;

/* ═══════════ NEW: BATCH SELECTION ═══════════ */
let selIds   = new Set();
let selTotal = 0;

function onCbChange(cb) {
  const id    = parseInt(cb.dataset.id);
  const amt   = parseFloat(cb.dataset.total);
  const card  = cb.closest('.order-card-row');
  if (cb.checked) { selIds.add(id); selTotal += amt; card.classList.add('selected'); }
  else            { selIds.delete(id); selTotal -= amt; card.classList.remove('selected'); }
  refreshBatchBar();
}

function refreshBatchBar() {
  const n = selIds.size;
  document.getElementById('batchCnt').textContent    = n;
  document.getElementById('batchPlural').textContent = n === 1 ? '' : 's';
  document.getElementById('batchTotal').textContent  = '₱' + fmt(selTotal);
  n > 0
    ? document.getElementById('batchBar').classList.add('visible')
    : document.getElementById('batchBar').classList.remove('visible');
}

function clearSel() {
  document.querySelectorAll('.order-cb:checked').forEach(cb => {
    cb.checked = false;
    cb.closest('.order-card-row')?.classList.remove('selected');
  });
  selIds.clear(); selTotal = 0; refreshBatchBar();
}

function openDispatchModal() {
  if (!selIds.size) return;
  const ids = [...selIds];
  let rows = ids.map(id => {
    const card = document.querySelector(`.order-card-row[data-id="${id}"]`);
    const name = card?.querySelector('[style*="font-size:14px"]')?.textContent?.trim() || '#'+id;
    const addr = card?.querySelector('[style*="📍"]')?.textContent?.trim() || '';
    return `<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px">
      <span style="font-family:var(--mono);color:var(--muted)">#${id}</span>
      <span style="flex:1;margin:0 10px">${escHtml(name)}</span>
      <span style="font-family:var(--mono);color:var(--accent)">₱${fmt(parseFloat(document.querySelector(`.order-card-row[data-id="${id}"]`)?.dataset?.total||0))}</span>
    </div>`;
  }).join('');

  document.getElementById('dispatchContent').innerHTML = `
    <p style="font-size:13px;color:var(--muted);font-family:var(--mono);margin-bottom:14px">
      You will be locked in as the rider for <strong style="color:var(--text)">${ids.length}</strong> order${ids.length!==1?'s':''}. Others cannot claim them.
    </p>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:14px">${rows}</div>
    <div style="display:flex;justify-content:space-between;font-size:15px;font-weight:700;padding:4px 0">
      <span style="color:var(--muted);font-family:var(--mono)">Combined Total</span>
      <span style="color:var(--accent)">₱${fmt(selTotal)}</span>
    </div>`;
  document.getElementById('dispatchModal').classList.add('open');
}

function doDispatch() {
  const btn = document.getElementById('btnDoDispatch');
  btn.disabled = true; btn.textContent = '⏳ Dispatching…';

  const body = new URLSearchParams({ action: 'batch_dispatch' });
  [...selIds].forEach(id => body.append('ids[]', id));

  fetch('orders.php', { method:'POST', body })
    .then(r => r.json())
    .then(d => {
      document.getElementById('dispatchModal').classList.remove('open');
      if (d.ok) {
        showToast(`🚐 ${d.dispatched} order${d.dispatched!==1?'s':''} dispatched! You are the rider.`, 'toast-ready');
        setTimeout(() => location.reload(), 1100);
      } else {
        showToast('✕ ' + (d.msg||'Dispatch failed'), 'toast-error');
      }
    })
    .catch(() => showToast('✕ Network error', 'toast-error'))
    .finally(() => { btn.disabled=false; btn.textContent='✅ Yes, I\'m the Rider for All'; });
}

/* ═══════════ EXISTING: openOrderModal (extended) ═══════════ */
function openOrderModal(row) {
  document.getElementById('mTitle').textContent = 'Order #' + row.Order_ID + ' — ' + row.Order_Status;

  const sizeTxt   = row.Size_Label ? row.Size_Label + ' — ' + row.Size_Name : '—';
  const isMyOrder = (parseInt(row.Rider_ID) === STAFF_ID);
  const hasProof  = !!row.Proof_Image;
  const svcType   = row.Order_Type || 'Delivery';
  const svcBadge  = svcType.toLowerCase() === 'refill'
    ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:700;background:rgba(99,102,241,0.1);color:#a5b4fc;border:1px solid rgba(99,102,241,0.3)">💧 Refill Service</span>`
    : `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:700;background:rgba(74,222,128,0.1);color:var(--accent);border:1px solid rgba(74,222,128,0.25)">🚚 Delivery</span>`;

  /* ── Address block (existing) ── */
  let addrHtml = '';
  if (row.Ship_Address) {
    const coords = (row.Dest_Lat && row.Dest_Lng)
      ? `<div style="color:var(--accent);margin-top:3px">📡 ${parseFloat(row.Dest_Lat).toFixed(5)}, ${parseFloat(row.Dest_Lng).toFixed(5)}</div>` : '';
    addrHtml = `
      <div class="delivery-addr-block">
        <strong>📍 Delivery Address</strong><br>
        ${escHtml(row.Ship_Name||row.Customer_Name||'')} &nbsp;${row.Ship_Phone?'📞 '+escHtml(row.Ship_Phone):''}<br>
        ${escHtml(row.Ship_Address)}, ${escHtml(row.Ship_City)}, ${escHtml(row.Ship_Province)} ${escHtml(row.Ship_Zip||'')}
        ${coords}
      </div>`;
  }

  /* ── Rider / tracker block (existing + unchanged) ── */
  let riderHtml = '';
  if (row.Rider_Name) {
    const riderLabel = isMyOrder
      ? `<span style="color:#a5b4fc;font-weight:700">🚐 You (${escHtml(row.Rider_Name)})</span>`
      : `<span style="color:var(--accent)">🚐 ${escHtml(row.Rider_Name)}</span>`;
    const dispTime = row.Dispatched_At
      ? `<span style="font-size:11px;color:var(--muted);font-family:var(--mono)"> · dispatched ${row.Dispatched_At}</span>` : '';
    const riderUrl = TRACKER_URL + '?order_id=' + row.Order_ID + '&role=rider';
    const trackerSection = (row.Order_Status === 'Out for Delivery') ? `
      <div class="tracker-link-box">
        <div>
          <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">${isMyOrder?'🗺️ Your Rider Tracker Link':'🔗 Rider Tracker Link'}</div>
          <div class="tracker-url">${escHtml(riderUrl)}</div>
        </div>
        <button class="btn-copy-url" id="copyBtn-${row.Order_ID}"
                onclick="copyTrackerUrl('${row.Order_ID}','${escAttr(riderUrl)}')">📋 Copy</button>
      </div>
      ${isMyOrder ? `<div style="margin-top:10px"><a href="${escAttr(riderUrl)}" target="_blank" style="display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;font-size:13px;font-weight:700;font-family:var(--mono);cursor:pointer;background:rgba(74,222,128,0.12);color:var(--accent);border:1.5px solid rgba(74,222,128,0.3);text-decoration:none">🗺️ Open My Tracker (Start Riding)</a></div>` : ''}
    ` : '';
    riderHtml = `
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-top:10px">
        <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Assigned Rider</div>
        <div style="font-size:13px">${riderLabel}${dispTime}</div>
        ${trackerSection}
      </div>`;
  }

  /* ── NEW: Proof of delivery block (only for my Out for Delivery orders) ── */
  let podHtml = '';
  if (row.Order_Status === 'Out for Delivery' && isMyOrder) {
    podHtml = `
      <div style="margin-top:14px">
        <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">
          📷 Proof of Delivery
          ${hasProof
            ? '<span style="color:var(--accent);margin-left:6px">✓ Uploaded</span>'
            : '<span style="color:var(--danger);margin-left:6px">Required before completing</span>'}
        </div>
        ${hasProof
          ? `<img src="../${escHtml(row.Proof_Image)}" style="width:100%;max-height:180px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
             <div style="font-size:11px;font-family:var(--mono);color:var(--accent);margin-top:5px">✓ Photo on record — you may now mark as completed.</div>
             <div style="font-size:11px;font-family:var(--mono);color:var(--muted);margin-top:8px">Replace photo:</div>`
          : ''}
        <div class="pod-zone" id="podZone${row.Order_ID}"
             onclick="document.getElementById('podFile${row.Order_ID}').click()"
             ondragover="event.preventDefault();this.classList.add('over')"
             ondragleave="this.classList.remove('over')"
             ondrop="onDrop(event,${row.Order_ID})">
          <input type="file" id="podFile${row.Order_ID}" accept="image/*" capture="environment"
                 onchange="uploadProof(this.files[0],${row.Order_ID})">
          <div style="font-size:22px;margin-bottom:6px">📷</div>
          <div style="font-size:13px;font-weight:600;color:var(--text)">${hasProof?'Replace proof photo':'Take / upload proof photo'}</div>
          <div style="font-size:11px;font-family:var(--mono);color:var(--muted);margin-top:3px">Tap to open camera or choose file · JPG/PNG/WEBP · Max 8 MB</div>
          <img class="pod-preview-img" id="podPrev${row.Order_ID}" alt="">
        </div>
        <div class="pod-status" id="podStat${row.Order_ID}"></div>
      </div>`;
  } else if ((row.Order_Status === 'Out for Delivery' || row.Order_Status === 'Completed') && hasProof) {
    podHtml = `
      <div style="margin-top:12px">
        <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">📷 Proof of Delivery</div>
        <img src="../${escHtml(row.Proof_Image)}" style="width:100%;max-height:160px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
      </div>`;
  }

  /* ── Modal content (existing grid, unchanged) ── */
  document.getElementById('mContent').innerHTML = `
    <div style="display:flex;gap:14px;margin-bottom:16px;align-items:flex-start">
      ${row.Product_Image
        ? `<img src="../${row.Product_Image}" style="width:72px;height:72px;border-radius:10px;object-fit:cover;border:1px solid var(--border);flex-shrink:0">`
        : `<div style="width:72px;height:72px;border-radius:10px;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0">💧</div>`}
      <div>
        <div style="font-size:17px;font-weight:600;color:var(--text);margin-bottom:6px">${escHtml(row.Product_Name)}</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
          ${svcBadge}
          <span style="font-size:12px;color:var(--muted);font-family:var(--mono)">${escHtml(row.Payment_Type_Description)}</span>
        </div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:10px">
      <div><div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Customer</div><div style="font-size:13px;font-weight:500">${escHtml(row.Customer_Name||'Guest')}</div></div>
      <div><div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Size</div><div style="font-size:13px;font-weight:500">${escHtml(sizeTxt)}</div></div>
      <div><div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Quantity</div><div style="font-size:13px;font-weight:500">${row.Order_Quantity}</div></div>
      <div><div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Total</div><div style="font-size:16px;font-weight:700;color:var(--accent)">₱${parseFloat(row.Order_Total).toFixed(2)}</div></div>
      <div><div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Ordered</div><div style="font-size:12px;font-family:var(--mono)">${row.Order_Date_Time}</div></div>
      ${row.Completed_At?`<div><div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Completed</div><div style="font-size:12px;font-family:var(--mono)">${row.Completed_At}</div></div>`:''}
    </div>
    ${row.Order_Note?`<div style="background:rgba(251,191,36,0.08);border:1px dashed rgba(251,191,36,0.3);border-radius:8px;padding:10px 14px;font-size:13px;color:var(--warn);display:flex;gap:8px;margin-bottom:10px"><span>📝</span><span>${escHtml(row.Order_Note)}</span></div>`:''}
    ${addrHtml}
    ${riderHtml}
    ${podHtml}
  `;

  /* ── Action buttons ── */
  const acts = document.getElementById('mActions');
  acts.innerHTML = '';
  const id     = row.Order_ID;
  const status = row.Order_Status;

  if (status === 'Pending') {
    // Existing: Prepare + Cancel
    acts.innerHTML += `<a href="?action=prepare&id=${id}&filter=${CUR_FILTER}&service=${CUR_SERVICE}" class="btn btn-primary" style="background:var(--accent2);color:#0e0f11">👨‍🍳 Start Preparing</a>`;
    acts.innerHTML += `<a href="?action=cancel&id=${id}&filter=${CUR_FILTER}&service=${CUR_SERVICE}" class="btn btn-danger" onclick="return confirm('Cancel order #${id}?')">✕ Cancel</a>`;

  } else if (status === 'Preparing') {
    if (!row.Rider_ID) {
      // Unclaimed — existing single-order dispatch button + NEW batch hint
      acts.innerHTML += `<a href="?action=ready&id=${id}&filter=${CUR_FILTER}&service=${CUR_SERVICE}" class="btn btn-primary"
        onclick="return confirm('You will be the rider for order #${id}. Proceed?')">🚐 Take This Order (Solo)</a>`;
      acts.innerHTML += `<a href="?action=cancel&id=${id}&filter=${CUR_FILTER}&service=${CUR_SERVICE}" class="btn btn-danger" onclick="return confirm('Cancel order #${id}?')">✕ Cancel</a>`;
    } else if (isMyOrder) {
      acts.innerHTML = `<span style="font-size:13px;font-family:var(--mono);color:var(--accent)">✓ You already picked this order</span>`;
    } else {
      acts.innerHTML = `<span style="font-size:13px;font-family:var(--mono);color:var(--muted)">🔒 Claimed by ${escHtml(row.Rider_Name||'another rider')}</span>`;
    }

  } else if (status === 'Out for Delivery') {
    if (isMyOrder) {
      // NEW: Complete button — disabled until proof uploaded
      const canComplete = hasProof;
      acts.innerHTML += canComplete
        ? `<a href="?action=complete&id=${id}&filter=${CUR_FILTER}&service=${CUR_SERVICE}" class="btn-complete" onclick="return confirm('Mark order #${id} as completed?')">☑️ Mark as Completed</a>`
        : `<button class="btn-complete" data-locked disabled>☑️ Mark as Completed</button>`;
      if (!canComplete) {
        acts.innerHTML += `<div class="proof-note" style="width:100%">📷 Upload proof of delivery above first — then this button will unlock.</div>`;
      }
    } else {
      acts.innerHTML = `<span style="font-size:13px;font-family:var(--mono);color:var(--muted)">🔒 Handled by ${escHtml(row.Rider_Name||'another rider')}</span>`;
    }

  } else {
    // Completed / Cancelled (existing)
    acts.innerHTML = `<span style="font-size:13px;font-family:var(--mono);color:var(--muted)">${status==='Completed'?'✓ Completed':'✕ Cancelled'}</span>`;
  }

  acts.innerHTML += `<button class="btn btn-ghost" onclick="closeModal()" style="margin-left:auto">Close</button>`;
  document.getElementById('orderModal').classList.add('open');
}

/* ═══════════ NEW: PROOF OF DELIVERY ═══════════ */
function onDrop(e, oid) {
  e.preventDefault();
  document.getElementById('podZone'+oid)?.classList.remove('over');
  const f = e.dataTransfer.files[0];
  if (f) uploadProof(f, oid);
}

function uploadProof(file, oid) {
  if (!file) return;
  const stat = document.getElementById('podStat'+oid);
  const prev = document.getElementById('podPrev'+oid);

  // Instant local preview
  const reader = new FileReader();
  reader.onload = e => { prev.src = e.target.result; prev.style.display = 'block'; };
  reader.readAsDataURL(file);

  stat.textContent = '⏳ Uploading…'; stat.style.color = 'var(--muted)';

  const fd = new FormData();
  fd.append('action',   'upload_proof');
  fd.append('order_id', oid);
  fd.append('proof',    file);

  fetch('orders.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        stat.textContent = '✓ Proof uploaded! You can now mark this order as completed.';
        stat.style.color = 'var(--accent)';

        // Add proof badge to card in list
        const card = document.querySelector(`.order-card-row[data-id="${oid}"]`);
        if (card && !card.querySelector('.proof-badge')) {
          const badgeWrap = card.querySelector('[style*="flex-wrap:wrap"]');
          if (badgeWrap) {
            const b = document.createElement('span');
            b.className   = 'proof-badge';
            b.textContent = '📷 proof';
            badgeWrap.appendChild(b);
          }
        }

        // Unlock the complete button inside the modal
        const locked = document.querySelector('.btn-complete[data-locked]');
        if (locked) {
          const f = CUR_FILTER, s = CUR_SERVICE;
          const a = document.createElement('a');
          a.href      = `?action=complete&id=${oid}&filter=${f}&service=${s}`;
          a.className = 'btn-complete';
          a.innerHTML = '☑️ Mark as Completed';
          a.onclick   = () => confirm(`Mark order #${oid} as completed?`);
          locked.replaceWith(a);
          document.querySelector('.proof-note')?.remove();
        }
      } else {
        stat.textContent = '✕ ' + (d.msg||'Upload failed');
        stat.style.color = 'var(--danger)';
      }
    })
    .catch(() => { stat.textContent='✕ Network error'; stat.style.color='var(--danger)'; });
}

/* ═══════════ EXISTING HELPERS (unchanged) ═══════════ */
function copyTrackerUrl(orderId, url) {
  navigator.clipboard.writeText(url).then(() => {
    const btn = document.getElementById('copyBtn-' + orderId);
    if (btn) { btn.textContent='✓ Copied'; btn.classList.add('copied'); }
    setTimeout(() => { if(btn){btn.textContent='📋 Copy';btn.classList.remove('copied');} }, 2200);
  }).catch(() => {
    const ta = document.createElement('textarea');
    ta.value=url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
  });
}
function escHtml(s) { if(!s)return''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s) { return String(s||'').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function fmt(n)     { return parseFloat(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function closeModal() { document.getElementById('orderModal').classList.remove('open'); }
document.getElementById('orderModal').addEventListener('click',   function(e){ if(e.target===this) closeModal(); });
document.getElementById('dispatchModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });

/* ═══════════ EXISTING TOASTS (extended) ═══════════ */
const toastMsgs = {
  preparing: { text:'👨‍🍳 Order is now being prepared!',        cls:'toast-success' },
  ready:     { text:'🚐 Dispatched! You are the rider.',        cls:'toast-ready'   },
  completed: { text:'☑️ Order completed!',                      cls:'toast-done'    },
  cancelled: { text:'✕ Order cancelled — stock restored',      cls:'toast-cancel'  },
  noproof:   { text:'📷 Upload proof of delivery first.',       cls:'toast-error'   },
  notowner:  { text:'✕ You are not the rider for that order.', cls:'toast-error'   },
};

function showToast(msg, cls) {
  const t = document.getElementById('toastEl');
  t.textContent = msg; t.className = 'toast-bar ' + cls;
  setTimeout(() => t.classList.add('show'), 50);
  setTimeout(() => t.classList.remove('show'), 3500);
}

const p   = new URLSearchParams(location.search);
const key = p.get('toast');
if (key && toastMsgs[key]) {
  const { text, cls } = toastMsgs[key];
  showToast(text, cls);
  history.replaceState({}, '', location.pathname+'?filter='+(p.get('filter')||'all')+'&service='+(p.get('service')||'all'));
}
</script>

<?php require '../includes/footer.php'; ?>