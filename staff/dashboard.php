<?php
/**
 * STAFF — staff/index.php (Dashboard)
 * -------------------------------------------------------
 * WHO SEES THIS: Staff role only
 * PURPOSE: Overview stats + clickable recent orders (modal)
 * -------------------------------------------------------
 */
require '../config.php';
require_once '../includes/auth_check.php';
$pageTitle  = 'Dashboard';
$staff_id   = $_SESSION['user_id']   ?? 0;
$staff_name = $_SESSION['full_name'] ?? 'Staff';

// ── Stats ──────────────────────────────────────────────────────────────────────
$totalProducts   = $conn->query("SELECT COUNT(*) AS c FROM products WHERE Product_Status='Active'")->fetch_assoc()['c'];
$pendingOrders   = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Status='Pending'")->fetch_assoc()['c'];
$preparingOrders = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Status='Preparing'")->fetch_assoc()['c'];
$readyOrders     = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Status='Out for Delivery'")->fetch_assoc()['c'];
$todayRevenue    = $conn->query("SELECT COALESCE(SUM(Order_Total),0) AS t FROM orders WHERE DATE(Order_Date_Time)=CURDATE() AND Order_Status='Completed'")->fetch_assoc()['t'];

// Delivery vs Refill counts today
$todayDelivery = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Type='Delivery' AND DATE(Order_Date_Time)=CURDATE()")->fetch_assoc()['c'];
$todayRefill   = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Type='Refill'   AND DATE(Order_Date_Time)=CURDATE()")->fetch_assoc()['c'];

// My active deliveries
$myDeliveries = $conn->query("
    SELECT COUNT(*) AS c FROM orders
    WHERE Rider_ID=$staff_id AND Order_Status='Out for Delivery'
")->fetch_assoc()['c'];

// ── Recent orders (6) — full join for modal ────────────────────────────────────
$recentOrders = $conn->query("
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
    ORDER BY o.Order_Date_Time DESC
    LIMIT 8
");

define('TRACKER_URL', 'http://localhost:3000');
require '../includes/staff_header.php';
?>

<style>
/* ── Modal ── */
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

/* ── Service type badge ── */
.svc-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:10px;font-family:var(--mono);font-weight:700}
.svc-delivery{background:rgba(74,222,128,0.1);color:var(--accent);border:1px solid rgba(74,222,128,0.25)}
.svc-refill{background:rgba(99,102,241,0.1);color:#a5b4fc;border:1px solid rgba(99,102,241,0.3)}

/* ── Clickable order row ── */
.order-row{display:flex;align-items:center;gap:14px;padding:12px 16px;background:var(--surface);border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:all 0.15s}
.order-row:hover{border-color:rgba(74,222,128,0.35);background:rgba(74,222,128,0.03);transform:translateX(2px)}

/* ── Tracker ── */
.tracker-link-box{display:flex;align-items:center;gap:10px;padding:11px 14px;background:rgba(74,222,128,0.06);border:1px solid rgba(74,222,128,0.2);border-radius:10px;margin-top:12px}
.tracker-url{flex:1;font-family:var(--mono);font-size:11px;color:var(--accent);word-break:break-all}
.btn-copy-url{flex-shrink:0;padding:6px 12px;border-radius:8px;font-size:11px;font-weight:700;font-family:var(--mono);cursor:pointer;border:1px solid rgba(74,222,128,0.3);color:var(--accent);background:rgba(74,222,128,0.08);transition:all 0.15s}
.btn-copy-url:hover{background:rgba(74,222,128,0.2)}
.btn-copy-url.copied{background:var(--accent);color:#0e0f11;border-color:var(--accent)}
.delivery-addr-block{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-top:10px;font-size:12px;color:var(--muted);font-family:var(--mono);line-height:1.7}
.delivery-addr-block strong{color:var(--text);font-size:13px}
</style>

<div class="page-header">
  <h1 class="page-title">Staff <span>Dashboard</span></h1>
  <div style="display:flex;align-items:center;gap:10px">
    <span style="font-size:12px;font-family:var(--mono);color:var(--muted)"><?= date('l, M d Y · h:i A') ?></span>
    <span style="font-size:11px;font-family:var(--mono);color:var(--muted);background:var(--surface);border:1px solid var(--border);padding:3px 10px;border-radius:20px">
      👤 <?= htmlspecialchars($staff_name) ?>
    </span>
  </div>
</div>

<!-- ── Stat cards ── -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px">
  <?php
  $stats = [
    ['🛍️ Active Products', $totalProducts,   '--accent',  ],
    ['🕐 Pending',         $pendingOrders,   '--warn',    ],
    ['👨‍🍳 Preparing',      $preparingOrders,'--accent2', ],
    ['✅ Out for Delivery', $readyOrders,    '--accent',  ],
    ['💰 Today\'s Sales',  '₱'.number_format($todayRevenue,0), '#a78bfa'],
  ];
  foreach ($stats as [$label, $val, $color]): ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden">
    <div style="height:4px;background:<?= $color ?>"></div>
    <div style="padding:14px 16px">
      <div style="font-size:11px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px"><?= $label ?></div>
      <div style="font-size:26px;font-weight:700;color:<?= $color ?>;letter-spacing:-1px"><?= $val ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Service type split ── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
  <div style="background:var(--card);border:1px solid rgba(74,222,128,0.25);border-radius:10px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between">
    <div>
      <div style="font-size:11px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">🚚 Delivery Orders Today</div>
      <div style="font-size:28px;font-weight:700;color:var(--accent);letter-spacing:-1px"><?= $todayDelivery ?></div>
    </div>
    <a href="orders.php?filter=all&service=delivery" style="font-size:11px;font-family:var(--mono);color:var(--accent);text-decoration:none;border:1px solid rgba(74,222,128,0.3);padding:5px 12px;border-radius:20px">View →</a>
  </div>
  <div style="background:var(--card);border:1px solid rgba(99,102,241,0.3);border-radius:10px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between">
    <div>
      <div style="font-size:11px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">💧 Refill Orders Today</div>
      <div style="font-size:28px;font-weight:700;color:#a5b4fc;letter-spacing:-1px"><?= $todayRefill ?></div>
    </div>
    <a href="orders.php?filter=all&service=refill" style="font-size:11px;font-family:var(--mono);color:#a5b4fc;text-decoration:none;border:1px solid rgba(99,102,241,0.3);padding:5px 12px;border-radius:20px">View →</a>
  </div>
</div>

<!-- ── Banners ── -->
<?php if ($myDeliveries > 0): ?>
<div style="background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.25);border-radius:10px;padding:11px 18px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between">
  <span style="font-size:13px;color:#a5b4fc;font-family:var(--mono)">🚐 You are actively riding <strong><?= $myDeliveries ?></strong> order<?= $myDeliveries!=1?'s':'' ?></span>
  <a href="orders.php?filter=ready" style="font-size:12px;font-weight:700;color:#a5b4fc;text-decoration:none;padding:4px 12px;border-radius:20px;border:1px solid rgba(99,102,241,0.35)">View →</a>
</div>
<?php endif; ?>
<?php if ($pendingOrders > 0): ?>
<div style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.25);border-radius:10px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">
  <span style="font-size:13px;color:var(--warn);font-family:var(--mono)">🕐 <?= $pendingOrders ?> order<?= $pendingOrders!=1?'s':'' ?> waiting to be prepared</span>
  <a href="orders.php?filter=pending" class="btn btn-sm" style="background:var(--warn);color:#0e0f11;font-weight:700">View Pending →</a>
</div>
<?php endif; ?>

<!-- ── Recent Orders ── -->
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
    <div class="card-title" style="margin-bottom:0">Recent Orders</div>
    <a href="orders.php" style="font-size:12px;font-family:var(--mono);color:var(--accent2);text-decoration:none">View all →</a>
  </div>

  <?php if ($recentOrders->num_rows === 0): ?>
    <p style="color:var(--muted);font-size:13px;font-family:var(--mono);text-align:center;padding:32px">No orders yet.</p>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:8px">
    <?php while ($row = $recentOrders->fetch_assoc()):
      $status = $row['Order_Status'];
      [$clr,$bgClr,$borderClr] = match($status) {
        'Pending'          => ['var(--warn)',   'rgba(251,191,36,0.12)',  'rgba(251,191,36,0.25)'],
        'Preparing'        => ['var(--accent2)','rgba(34,211,238,0.12)',  'rgba(34,211,238,0.25)'],
        'Out for Delivery' => ['var(--accent)', 'rgba(74,222,128,0.12)',  'rgba(74,222,128,0.25)'],
        'Completed'        => ['var(--muted)',  'rgba(107,114,128,0.12)','rgba(107,114,128,0.2)'],
        'Cancelled'        => ['var(--danger)', 'rgba(248,113,113,0.12)','rgba(248,113,113,0.2)'],
        default            => ['var(--muted)',  'rgba(107,114,128,0.12)','rgba(107,114,128,0.2)'],
      };
      $serviceType = $row['Order_Type'] ?? 'Delivery';
    ?>
    <div class="order-row" style="border-left:4px solid <?= $clr ?>"
         onclick='openOrderModal(<?= json_encode($row) ?>)'>

      <!-- Product image -->
      <?php if (!empty($row['Product_Image'])): ?>
        <img src="../<?= htmlspecialchars($row['Product_Image']) ?>"
             style="width:44px;height:44px;border-radius:8px;object-fit:cover;border:1px solid var(--border);flex-shrink:0">
      <?php else: ?>
        <div style="width:44px;height:44px;border-radius:8px;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">💧</div>
      <?php endif; ?>

      <!-- Info -->
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;flex-wrap:wrap">
          <span style="font-size:12px;font-family:var(--mono);color:var(--muted)">#<?= $row['Order_ID'] ?></span>
          <span style="font-size:11px;font-family:var(--mono);font-weight:600;padding:2px 10px;border-radius:20px;background:<?= $bgClr ?>;color:<?= $clr ?>;border:1px solid <?= $borderClr ?>"><?= $status ?></span>
          <span class="svc-badge <?= strtolower($serviceType)==='refill'?'svc-refill':'svc-delivery' ?>">
            <?= strtolower($serviceType)==='refill'?'💧':'🚚' ?> <?= htmlspecialchars($serviceType) ?>
          </span>
          <?php if (!empty($row['Order_Note'])): ?>
            <span style="font-size:10px;background:rgba(251,191,36,0.1);color:var(--warn);border:1px solid rgba(251,191,36,0.2);padding:2px 8px;border-radius:20px;font-family:var(--mono)">📝</span>
          <?php endif; ?>
        </div>
        <div style="font-size:14px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          <?= htmlspecialchars($row['Product_Name']) ?>
          <?php if (!empty($row['Size_Label'])): ?>
            <span style="font-size:10px;font-family:var(--mono);color:var(--muted);background:var(--card);border:1px solid var(--border);padding:1px 6px;border-radius:6px;margin-left:4px"><?= $row['Size_Label'] ?></span>
          <?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--muted);font-family:var(--mono);margin-top:2px">
          👤 <?= htmlspecialchars($row['Customer_Name'] ?: 'Guest') ?> ·
          <?= htmlspecialchars($row['Payment_Type_Description']) ?> ·
          Qty: <?= $row['Order_Quantity'] ?>
        </div>
      </div>

      <!-- Total + time + tap hint -->
      <div style="text-align:right;flex-shrink:0">
        <div style="font-size:16px;font-weight:700;color:var(--accent);font-family:var(--mono)">₱<?= number_format($row['Order_Total'],2) ?></div>
        <div style="font-size:10px;color:var(--muted);font-family:var(--mono);margin-top:2px"><?= date('M d · h:i A', strtotime($row['Order_Date_Time'])) ?></div>
        <div style="font-size:10px;color:var(--muted);font-family:var(--mono);margin-top:3px;opacity:0.6">tap to manage →</div>
      </div>
    </div>
    <?php endwhile; ?>
  </div>
  <?php endif; ?>
</div>

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

function openOrderModal(row) {
  document.getElementById('mTitle').textContent =
    'Order #' + row.Order_ID + ' — ' + row.Order_Status;

  const sizeTxt   = row.Size_Label ? row.Size_Label + ' — ' + row.Size_Name : '—';
  const isMyOrder = (parseInt(row.Rider_ID) === STAFF_ID);
  const svcType   = row.Order_Type || 'Delivery';
  const svcBadge  = svcType.toLowerCase() === 'refill'
    ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:700;background:rgba(99,102,241,0.1);color:#a5b4fc;border:1px solid rgba(99,102,241,0.3)">💧 Refill Service</span>`
    : `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:700;background:rgba(74,222,128,0.1);color:var(--accent);border:1px solid rgba(74,222,128,0.25)">🚚 Delivery</span>`;

  // Delivery address block
  let addrHtml = '';
  if (row.Ship_Address) {
    const coords = (row.Dest_Lat && row.Dest_Lng)
      ? `<div style="color:var(--accent);margin-top:3px">📡 ${parseFloat(row.Dest_Lat).toFixed(5)}, ${parseFloat(row.Dest_Lng).toFixed(5)}</div>`
      : '';
    addrHtml = `
      <div class="delivery-addr-block">
        <strong>📍 Delivery Address</strong><br>
        ${escHtml(row.Ship_Address)}, ${escHtml(row.Ship_City)}, ${escHtml(row.Ship_Province)} ${escHtml(row.Ship_Zip||'')}<br>
        ${row.Ship_Phone ? '📞 ' + escHtml(row.Ship_Phone) : ''}
        ${coords}
      </div>`;
  }

  // Rider section
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
      ${isMyOrder ? `<div style="margin-top:10px"><a href="${escAttr(riderUrl)}" target="_blank" style="display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;font-size:13px;font-weight:700;font-family:var(--mono);cursor:pointer;background:rgba(74,222,128,0.12);color:var(--accent);border:1.5px solid rgba(74,222,128,0.3);text-decoration:none">🗺️ Open My Tracker</a></div>` : ''}
    ` : '';
    riderHtml = `
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-top:10px">
        <div style="font-size:10px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Assigned Rider</div>
        <div style="font-size:13px">${riderLabel}${dispTime}</div>
        ${trackerSection}
      </div>`;
  }

  document.getElementById('mContent').innerHTML = `
    <div style="display:flex;gap:14px;margin-bottom:14px;align-items:flex-start">
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
  const f  = 'all';
  const status = row.Order_Status;

  if (status === 'Pending') {
    acts.innerHTML += `<a href="orders.php?action=prepare&id=${id}&filter=${f}" class="btn btn-primary" style="background:var(--accent2);color:#0e0f11">👨‍🍳 Start Preparing</a>`;
    acts.innerHTML += `<a href="orders.php?action=cancel&id=${id}&filter=${f}" class="btn btn-danger" onclick="return confirm('Cancel order #${id}?')">✕ Cancel</a>`;
  } else if (status === 'Preparing') {
    acts.innerHTML += `<a href="orders.php?action=ready&id=${id}&filter=${f}" class="btn btn-primary" onclick="return confirm('You will be assigned as the rider for order #${id}. Proceed?')">🚐 Proceed to Delivery</a>`;
    acts.innerHTML += `<a href="orders.php?action=cancel&id=${id}&filter=${f}" class="btn btn-danger" onclick="return confirm('Cancel order #${id}?')">✕ Cancel</a>`;
  } else if (status === 'Out for Delivery') {
    acts.innerHTML += `<a href="orders.php?action=complete&id=${id}&filter=${f}" class="btn btn-primary" style="background:#7c3aed" onclick="return confirm('Mark order #${id} as delivered?')">☑️ Mark as Completed</a>`;
  } else {
    acts.innerHTML = `<span style="font-size:13px;font-family:var(--mono);color:var(--muted)">${status === 'Completed' ? '✓ Completed' : '✕ Cancelled'}</span>`;
  }

  acts.innerHTML += `<a href="orders.php?view=active&filter=all" class="btn btn-ghost" style="margin-left:auto">Go to Orders →</a>`;
  acts.innerHTML += `<button class="btn btn-ghost" onclick="closeModal()">Close</button>`;

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
function escHtml(s) { if (!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s) { return String(s||'').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function closeModal() { document.getElementById('orderModal').classList.remove('open'); }
document.getElementById('orderModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });

// Toast
const toastMsgs = {
  preparing:{ text:'👨‍🍳 Order is now being prepared!', cls:'toast-success' },
  ready:    { text:'🚐 Dispatched!',                   cls:'toast-ready'   },
  completed:{ text:'☑️ Order completed!',              cls:'toast-done'    },
  cancelled:{ text:'✕ Order cancelled',               cls:'toast-cancel'  },
};
const p = new URLSearchParams(location.search), key = p.get('toast');
if (key && toastMsgs[key]) {
  const t = document.getElementById('toastEl');
  t.textContent = toastMsgs[key].text; t.classList.add(toastMsgs[key].cls);
  setTimeout(() => t.classList.add('show'), 100);
  setTimeout(() => t.classList.remove('show'), 3500);
  history.replaceState({}, '', location.pathname);
}
</script>

<?php require '../includes/footer.php'; ?>