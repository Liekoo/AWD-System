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
 *   Preparing        → [Proceed to Delivery]  → Out for Delivery
 *                       ↳ AUTO-ASSIGNS logged-in staff as Rider
 *   Out for Delivery → [Mark Completed]       → Completed
 *   Pending/Preparing → [Cancel]              → Cancelled (stock restored)
 * -------------------------------------------------------
 */
require '../config.php';
require_once '../includes/auth_check.php';
$pageTitle  = 'Orders';
$staff_id   = $_SESSION['user_id']   ?? 0;
$staff_name = $_SESSION['full_name'] ?? 'Staff';

// ── Handle status transitions ──────────────────────────────────────────────────
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
$service = $_GET['service'] ?? 'all';   // 'all' | 'delivery' | 'refill'

// Status clause
$statusClause = match($filter) {
    'pending'   => "o.Order_Status='Pending'",
    'preparing' => "o.Order_Status='Preparing'",
    'ready'     => "o.Order_Status='Out for Delivery'",
    'done'      => "o.Order_Status IN ('Completed','Cancelled')",
    default     => "o.Order_Status NOT IN ('Completed','Cancelled')"
};
if ($view === 'logs') $statusClause = "o.Order_Status IN ('Completed','Cancelled')";

// Service clause
$serviceClause = match($service) {
    'delivery' => "o.Order_Type='Delivery'",
    'refill'   => "o.Order_Type='Refill'",
    default    => '1=1'
};

$whereClause = "WHERE ($statusClause) AND ($serviceClause)";

// ── Main query ─────────────────────────────────────────────────────────────────
$orders = $conn->query("
    SELECT o.*,
           p.Product_Name, p.Product_Image,
           s.Size_Label, s.Size_Name,
           pt.Payment_Type_Description,
           sa.Address  AS Ship_Address,
           sa.City     AS Ship_City,
           sa.Province AS Ship_Province,
           sa.Zip_Code AS Ship_Zip,
           sa.Phone    AS Ship_Phone,
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

// ── Counts (all, respecting service filter) ────────────────────────────────────
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

// Service type global counts
$deliveryTotal = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Type='Delivery' AND Order_Status NOT IN ('Completed','Cancelled')")->fetch_assoc()['c'];
$refillTotal   = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Type='Refill'   AND Order_Status NOT IN ('Completed','Cancelled')")->fetch_assoc()['c'];

// My active deliveries
$myDeliveries = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Rider_ID=$staff_id AND Order_Status='Out for Delivery'")->fetch_assoc()['c'];

define('TRACKER_URL', 'http://localhost:3000');
require '../includes/staff_header.php';
?>

<style>
/* ── Modals ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal-box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,0.5)}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border)}
.modal-title{font-size:15px;font-weight:600;color:var(--text);font-family:var(--mono)}
.modal-close{background:none;border:none;color:var(--muted);font-size:22px;cursor:pointer;line-height:1;transition:color 0.15s}.modal-close:hover{color:var(--danger)}

/* ── Toast ── */
.toast-bar{position:fixed;bottom:28px;right:28px;padding:13px 22px;border-radius:30px;font-weight:700;font-size:13px;z-index:999;display:flex;align-items:center;gap:8px;box-shadow:0 8px 24px rgba(0,0,0,0.3);transform:translateY(80px);opacity:0;transition:transform 0.4s cubic-bezier(.34,1.56,.64,1),opacity 0.3s;pointer-events:none}
.toast-bar.show{transform:translateY(0);opacity:1}
.toast-success{background:var(--accent2);color:#0e0f11}
.toast-ready{background:var(--accent);color:#0e0f11}
.toast-done{background:#7c3aed;color:#fff}
.toast-cancel{background:var(--danger);color:#fff}

/* ── Service switcher ── */
.svc-switcher{display:flex;gap:8px;margin-bottom:16px}
.svc-btn{padding:8px 18px;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:all 0.15s;border:1.5px solid var(--border);color:var(--muted);background:var(--card);font-family:var(--mono)}
.svc-btn.active-all{background:rgba(107,114,128,0.1);color:var(--text);border-color:var(--border)}
.svc-btn.active-delivery{background:rgba(74,222,128,0.1);color:var(--accent);border-color:rgba(74,222,128,0.35)}
.svc-btn.active-refill{background:rgba(99,102,241,0.1);color:#a5b4fc;border-color:rgba(99,102,241,0.35)}
.svc-btn .cnt{border-radius:20px;padding:1px 7px;font-size:11px;background:rgba(0,0,0,0.15)}

/* ── View tabs ── */
.view-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.view-tab{padding:7px 16px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all 0.15s;border:1px solid var(--border);color:var(--muted);background:var(--card)}
.view-tab.active{background:var(--accent);color:#0e0f11;border-color:var(--accent)}
.view-tab .cnt{border-radius:20px;padding:1px 7px;font-size:11px;font-family:var(--mono);background:rgba(0,0,0,0.15)}
.view-tab:not(.active) .cnt{background:var(--border);color:var(--text)}

/* ── Rider chip ── */
.rider-chip{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:20px;font-size:10px;font-family:var(--mono);font-weight:600;background:rgba(74,222,128,0.1);color:var(--accent);border:1px solid rgba(74,222,128,0.25)}
.rider-chip.mine{background:rgba(99,102,241,0.15);color:#a5b4fc;border-color:rgba(99,102,241,0.3)}

/* ── Tracker ── */
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

/* ── Service badge on cards ── */
.svc-badge-card{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:10px;font-family:var(--mono);font-weight:700}
.svc-delivery-card{background:rgba(74,222,128,0.1);color:var(--accent);border:1px solid rgba(74,222,128,0.25)}
.svc-refill-card{background:rgba(99,102,241,0.1);color:#a5b4fc;border:1px solid rgba(99,102,241,0.3)}
</style>

<?php
// Build filter query string helper
function filterUrl($f='all', $s=null, $v='active') {
    global $service, $view;
    $sParam = $s ?? $service;
    return "?view=$v&filter=$f&service=$sParam";
}
?>

<div class="page-header">
  <h1 class="page-title">Or<span>ders</span></h1>
  <div style="display:flex;align-items:center;gap:12px">
    <span style="font-size:12px;font-family:var(--mono);color:var(--muted)"><?= date('M d, Y · h:i A') ?></span>
    <span style="font-size:11px;font-family:var(--mono);color:var(--muted);background:var(--surface);border:1px solid var(--border);padding:3px 10px;border-radius:20px">
      🚐 <?= htmlspecialchars($staff_name) ?>
    </span>
  </div>
</div>

<!-- Stat cards -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px">
  <?php
  $stats = [
    ['Pending',          $counts['Pending'],          'var(--warn)',   '🕐'],
    ['Preparing',        $counts['Preparing'],        'var(--accent2)','👨‍🍳'],
    ['Out for Delivery', $counts['Out for Delivery'], 'var(--accent)', '✅'],
    ['Completed',        $counts['Completed'],        'var(--muted)',  '☑️'],
    ['Cancelled',        $counts['Cancelled'],        'var(--danger)', '✕'],
  ];
  foreach ($stats as [$label, $count, $color, $icon]): ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden">
    <div style="height:3px;background:<?= $color ?>"></div>
    <div style="padding:12px 14px">
      <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:5px"><?= $icon ?> <?= $label ?></div>
      <div style="font-size:24px;font-weight:700;color:<?= $color ?>;letter-spacing:-1px"><?= $count ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── SERVICE TYPE SWITCHER ── -->
<div style="margin-bottom:16px">
  <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Service Type</div>
  <div class="svc-switcher">
    <a href="?view=<?= $view ?>&filter=<?= $filter ?>&service=all"
       class="svc-btn <?= $service==='all'?'active-all':'' ?>">
      📋 All Services <span class="cnt"><?= $deliveryTotal + $refillTotal ?></span>
    </a>
    <a href="?view=<?= $view ?>&filter=<?= $filter ?>&service=delivery"
       class="svc-btn <?= $service==='delivery'?'active-delivery':'' ?>">
      🚚 Delivery <span class="cnt"><?= $deliveryTotal ?></span>
    </a>
    <a href="?view=<?= $view ?>&filter=<?= $filter ?>&service=refill"
       class="svc-btn <?= $service==='refill'?'active-refill':'' ?>">
      💧 Refill <span class="cnt"><?= $refillTotal ?></span>
    </a>
  </div>
</div>

<?php if ($service !== 'all'): ?>
<!-- Active service filter banner -->
<div style="background:<?= $service==='refill'?'rgba(99,102,241,0.08)':'rgba(74,222,128,0.06)' ?>;border:1px solid <?= $service==='refill'?'rgba(99,102,241,0.25)':'rgba(74,222,128,0.25)' ?>;border-radius:10px;padding:10px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between">
  <span style="font-size:12px;font-family:var(--mono);color:<?= $service==='refill'?'#a5b4fc':'var(--accent)' ?>">
    <?= $service==='refill'?'💧 Showing Refill Service orders only':'🚚 Showing Delivery orders only' ?>
  </span>
  <a href="?view=<?= $view ?>&filter=<?= $filter ?>&service=all" style="font-size:11px;font-family:var(--mono);color:var(--muted);text-decoration:none">Clear filter ✕</a>
</div>
<?php endif; ?>

<!-- My deliveries banner -->
<?php if ($myDeliveries > 0): ?>
<div class="my-deliveries-banner">
  <span>🚐 You are actively riding <strong><?= $myDeliveries ?></strong> order<?= $myDeliveries!=1?'s':'' ?></span>
  <a href="?filter=ready&service=<?= $service ?>">View my deliveries →</a>
</div>
<?php endif; ?>

<!-- Pending alert -->
<?php if ($counts['Pending'] > 0): ?>
<div style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.25);border-radius:10px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">
  <span style="font-size:13px;color:var(--warn);font-family:var(--mono)">🕐 <?= $counts['Pending'] ?> order<?= $counts['Pending']!=1?'s':'' ?> waiting to be prepared</span>
  <a href="?filter=pending&service=<?= $service ?>" class="btn btn-sm" style="background:var(--warn);color:#0e0f11;font-weight:700">View Pending →</a>
</div>
<?php endif; ?>

<!-- View tabs -->
<div class="view-tabs">
  <a href="?view=active&filter=all&service=<?= $service ?>"       class="view-tab <?= $view==='active'&&$filter==='all'?'active':'' ?>">All Active <span class="cnt"><?= $activeCount ?></span></a>
  <a href="?view=active&filter=pending&service=<?= $service ?>"   class="view-tab <?= $filter==='pending'?'active':'' ?>">Pending <span class="cnt"><?= $counts['Pending'] ?></span></a>
  <a href="?view=active&filter=preparing&service=<?= $service ?>" class="view-tab <?= $filter==='preparing'?'active':'' ?>">Preparing <span class="cnt"><?= $counts['Preparing'] ?></span></a>
  <a href="?view=active&filter=ready&service=<?= $service ?>"     class="view-tab <?= $filter==='ready'?'active':'' ?>">Out for Delivery <span class="cnt"><?= $counts['Out for Delivery'] ?></span></a>
  <a href="?view=logs&service=<?= $service ?>"                    class="view-tab <?= $view==='logs'?'active':'' ?>">Order Logs <span class="cnt"><?= $logsCount ?></span></a>
</div>

<!-- Orders list -->
<?php if ($orders->num_rows === 0): ?>
  <div style="text-align:center;padding:60px;color:var(--muted);font-family:var(--mono);font-size:14px">No orders found for this filter.</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:10px">
  <?php while ($row = $orders->fetch_assoc()):
    $status = $row['Order_Status'];
    $svcType = $row['Order_Type'] ?? 'Delivery';
    $isMyOrder = ($row['Rider_ID'] == $staff_id);
    [$clr,$bgClr,$borderClr] = match($status) {
      'Pending'          => ['var(--warn)',   'rgba(251,191,36,0.08)',  'rgba(251,191,36,0.25)'],
      'Preparing'        => ['var(--accent2)','rgba(34,211,238,0.08)',  'rgba(34,211,238,0.25)'],
      'Out for Delivery' => ['var(--accent)', 'rgba(74,222,128,0.08)', 'rgba(74,222,128,0.25)'],
      'Completed'        => ['var(--muted)',  'rgba(107,114,128,0.06)','rgba(107,114,128,0.2)'],
      'Cancelled'        => ['var(--danger)', 'rgba(248,113,113,0.06)','rgba(248,113,113,0.2)'],
      default            => ['var(--muted)',  'rgba(107,114,128,0.06)','rgba(107,114,128,0.2)'],
    };
    $cardStyle = $isMyOrder && $status === 'Out for Delivery' ? 'border-left:3px solid #818cf8' : '';
  ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;display:flex;cursor:pointer;<?= $cardStyle ?>"
       onclick='openOrderModal(<?= json_encode($row) ?>)'>

    <div style="width:4px;background:<?= $clr ?>;flex-shrink:0"></div>

    <div style="width:72px;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:10px;border-right:1px solid var(--border)">
      <?php if (!empty($row['Product_Image'])): ?>
        <img src="../<?= htmlspecialchars($row['Product_Image']) ?>" style="width:50px;height:50px;border-radius:8px;object-fit:cover;border:1px solid var(--border)">
      <?php else: ?>
        <div style="width:50px;height:50px;border-radius:8px;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:20px">💧</div>
      <?php endif; ?>
    </div>

    <div style="flex:1;padding:12px 16px;display:flex;flex-direction:column;gap:4px">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="font-family:var(--mono);font-size:11px;color:var(--muted)">#<?= $row['Order_ID'] ?></span>
        <span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:600;background:<?= $bgClr ?>;color:<?= $clr ?>;border:1px solid <?= $borderClr ?>"><?= $status ?></span>
        <!-- Service type badge -->
        <span class="svc-badge-card <?= strtolower($svcType)==='refill'?'svc-refill-card':'svc-delivery-card' ?>">
          <?= strtolower($svcType)==='refill'?'💧 Refill':'🚚 Delivery' ?>
        </span>
        <span style="font-size:11px;color:var(--muted);font-family:var(--mono)"><?= date('M d · h:i A', strtotime($row['Order_Date_Time'])) ?></span>
        <?php if (!empty($row['Order_Note'])): ?>
          <span style="font-size:10px;background:rgba(251,191,36,0.1);color:var(--warn);border:1px solid rgba(251,191,36,0.2);padding:2px 8px;border-radius:20px;font-family:var(--mono)">📝 note</span>
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
          <span class="rider-chip <?= $isMyOrder ? 'mine' : '' ?>">
            🚐 <?= $isMyOrder ? 'You' : htmlspecialchars($row['Rider_Name']) ?>
            <?php if ($isMyOrder): ?> (you)<?php endif; ?>
          </span>
          <?php if (!empty($row['Dispatched_At'])): ?>
            <span style="font-size:10px;font-family:var(--mono);color:var(--muted);margin-left:6px">dispatched <?= date('h:i A', strtotime($row['Dispatched_At'])) ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div style="display:flex;align-items:center;padding:0 14px;flex-shrink:0">
      <span style="font-size:12px;color:var(--muted);font-family:var(--mono)">tap to manage →</span>
    </div>
  </div>
  <?php endwhile; ?>
</div>
<?php endif; ?>

<!-- ══ ORDER ACTION MODAL ══ -->
<div class="modal-overlay" id="orderModal">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="mTitle">Order Details</span>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div id="mContent"></div>
    <div id="mActions" style="display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border);flex-wrap:wrap"></div>
  </div>
</div>

<div class="toast-bar" id="toastEl"></div>

<script>
const STAFF_ID    = <?= $staff_id ?>;
const STAFF_NAME  = <?= json_encode($staff_name) ?>;
const TRACKER_URL = <?= json_encode(TRACKER_URL) ?>;
const CUR_FILTER  = <?= json_encode($filter) ?>;
const CUR_SERVICE = <?= json_encode($service) ?>;

function openOrderModal(row) {
  document.getElementById('mTitle').textContent =
    'Order #' + row.Order_ID + ' — ' + row.Order_Status;

  const sizeTxt   = row.Size_Label ? row.Size_Label + ' — ' + row.Size_Name : '—';
  const isMyOrder = (parseInt(row.Rider_ID) === STAFF_ID);
  const svcType   = row.Order_Type || 'Delivery';
  const svcBadge  = svcType.toLowerCase() === 'refill'
    ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:700;background:rgba(99,102,241,0.1);color:#a5b4fc;border:1px solid rgba(99,102,241,0.3)">💧 Refill Service</span>`
    : `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:700;background:rgba(74,222,128,0.1);color:var(--accent);border:1px solid rgba(74,222,128,0.25)">🚚 Delivery</span>`;

  let addrHtml = '';
  if (row.Ship_Address) {
    const coords = (row.Dest_Lat && row.Dest_Lng)
      ? `<div style="color:var(--accent);margin-top:3px">📡 ${parseFloat(row.Dest_Lat).toFixed(5)}, ${parseFloat(row.Dest_Lng).toFixed(5)}</div>` : '';
    addrHtml = `
      <div class="delivery-addr-block">
        <strong>📍 Delivery Address</strong><br>
        ${escHtml(row.Ship_Address)}, ${escHtml(row.Ship_City)}, ${escHtml(row.Ship_Province)} ${escHtml(row.Ship_Zip||'')}<br>
        ${row.Ship_Phone ? '📞 ' + escHtml(row.Ship_Phone) : ''} ${coords}
      </div>`;
  }

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
          <div class="tracker-url" id="trackerUrl-${row.Order_ID}">${riderUrl}</div>
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
      <div>
        <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Customer</div>
        <div style="font-size:13px;font-weight:500">${escHtml(row.Customer_Name||'Guest')}</div>
      </div>
      <div>
        <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Size</div>
        <div style="font-size:13px;font-weight:500">${escHtml(sizeTxt)}</div>
      </div>
      <div>
        <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Quantity</div>
        <div style="font-size:13px;font-weight:500">${row.Order_Quantity}</div>
      </div>
      <div>
        <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Total</div>
        <div style="font-size:16px;font-weight:700;color:var(--accent)">₱${parseFloat(row.Order_Total).toFixed(2)}</div>
      </div>
      <div>
        <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Ordered</div>
        <div style="font-size:12px;font-family:var(--mono)">${row.Order_Date_Time}</div>
      </div>
      ${row.Completed_At ? `
      <div>
        <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">Completed</div>
        <div style="font-size:12px;font-family:var(--mono)">${row.Completed_At}</div>
      </div>` : ''}
    </div>

    ${row.Order_Note ? `
      <div style="background:rgba(251,191,36,0.08);border:1px dashed rgba(251,191,36,0.3);border-radius:8px;padding:10px 14px;font-size:13px;color:var(--warn);display:flex;gap:8px;margin-bottom:10px">
        <span>📝</span><span>${escHtml(row.Order_Note)}</span>
      </div>` : ''}
    ${addrHtml}
    ${riderHtml}
  `;

  // Action buttons
  const acts = document.getElementById('mActions');
  acts.innerHTML = '';
  const id = row.Order_ID;
  const status = row.Order_Status;

  if (status === 'Pending') {
    acts.innerHTML += `<a href="?action=prepare&id=${id}&filter=${CUR_FILTER}&service=${CUR_SERVICE}" class="btn btn-primary" style="background:var(--accent2);color:#0e0f11">👨‍🍳 Start Preparing</a>`;
    acts.innerHTML += `<a href="?action=cancel&id=${id}&filter=${CUR_FILTER}&service=${CUR_SERVICE}" class="btn btn-danger" onclick="return confirm('Cancel order #${id}?')">✕ Cancel</a>`;
  } else if (status === 'Preparing') {
    acts.innerHTML += `<a href="?action=ready&id=${id}&filter=${CUR_FILTER}&service=${CUR_SERVICE}" class="btn btn-primary"
       onclick="return confirm('You will be assigned as the rider for order #${id}. Proceed?')">🚐 Proceed to Delivery — I am the Rider</a>`;
    acts.innerHTML += `<a href="?action=cancel&id=${id}&filter=${CUR_FILTER}&service=${CUR_SERVICE}" class="btn btn-danger" onclick="return confirm('Cancel order #${id}?')">✕ Cancel</a>`;
  } else if (status === 'Out for Delivery') {
    acts.innerHTML += `<a href="?action=complete&id=${id}&filter=${CUR_FILTER}&service=${CUR_SERVICE}" class="btn btn-primary" style="background:#7c3aed" onclick="return confirm('Mark order #${id} as delivered?')">☑️ Mark as Completed</a>`;
  } else {
    acts.innerHTML = `<span style="font-size:13px;font-family:var(--mono);color:var(--muted)">${status === 'Completed' ? '✓ Completed' : '✕ Cancelled'}</span>`;
  }
  acts.innerHTML += `<button class="btn btn-ghost" onclick="closeModal()" style="margin-left:auto">Close</button>`;

  document.getElementById('orderModal').classList.add('open');
}

function copyTrackerUrl(orderId, url) {
  navigator.clipboard.writeText(url).then(() => {
    const btn = document.getElementById('copyBtn-' + orderId);
    if (btn) { btn.textContent = '✓ Copied'; btn.classList.add('copied'); }
    setTimeout(() => { if (btn) { btn.textContent = '📋 Copy'; btn.classList.remove('copied'); }}, 2200);
  }).catch(() => {
    const ta = document.createElement('textarea');
    ta.value = url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
  });
}
function escHtml(s) { if(!s)return''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s) { return String(s||'').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function closeModal() { document.getElementById('orderModal').classList.remove('open'); }
document.getElementById('orderModal').addEventListener('click', function(e) { if(e.target===this) closeModal(); });

const toastMsgs = {
  preparing:{ text:'👨‍🍳 Order is now being prepared!', cls:'toast-success' },
  ready:    { text:'🚐 Dispatched! You are the rider.', cls:'toast-ready'   },
  completed:{ text:'☑️ Order completed!',              cls:'toast-done'    },
  cancelled:{ text:'✕ Order cancelled — stock restored', cls:'toast-cancel'},
};
const p = new URLSearchParams(location.search), key = p.get('toast');
if (key && toastMsgs[key]) {
  const t = document.getElementById('toastEl');
  t.textContent = toastMsgs[key].text; t.classList.add(toastMsgs[key].cls);
  setTimeout(() => t.classList.add('show'), 100);
  setTimeout(() => t.classList.remove('show'), 3500);
  history.replaceState({}, '', location.pathname + '?filter=' + (p.get('filter')||'all') + '&service=' + (p.get('service')||'all'));
}
</script>

<?php require '../includes/footer.php'; ?>