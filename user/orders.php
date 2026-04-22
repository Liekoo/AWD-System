<?php
/**
 * CUSTOMER — user/orders.php
 * Customer views order history + live tracker modal (watch-only).
 * Rider URL is NEVER shown here — that is admin/staff responsibility only.
 */
require '../config.php';
require_once '../includes/auth_check.php';
require_login();
$uid = $_SESSION['user_id'];

// ── Cancel order ───────────────────────────────────────────────────────────────
if (isset($_GET['cancel'])) {
    $id = (int)$_GET['cancel'];
    $conn->query("UPDATE orders SET Order_Status='Cancelled'
                  WHERE Order_ID=$id AND User_ID=$uid AND Order_Status='Pending'");
    $order = $conn->query("SELECT Product_ID,Order_Quantity FROM orders WHERE Order_ID=$id")->fetch_assoc();
    if ($order) {
        $conn->query("UPDATE products SET Product_Quantity_Stock=Product_Quantity_Stock+{$order['Order_Quantity']}
                      WHERE Product_ID={$order['Product_ID']}");
    }
    header('Location: orders.php?cancelled=1'); exit;
}

// ── Fetch orders — join shipping_addresses for dest lat/lng ────────────────────
$orders = $conn->query("
    SELECT o.*,
           p.Product_Name, p.Product_Image,
           ct.Customer_Type_Description,
           pt.Payment_Type_Description,
           sa.Latitude  AS Dest_Lat,
           sa.Longitude AS Dest_Lng
    FROM orders o
    JOIN products p       ON o.Product_ID      = p.Product_ID
    JOIN customer_type ct ON o.Customer_Type_ID = ct.Customer_Type_ID
    JOIN payments_type pt ON o.Payment_Type_ID  = pt.Payment_Type_ID
    LEFT JOIN shipping_addresses sa ON o.Address_ID = sa.Address_ID
    WHERE o.User_ID = $uid
    ORDER BY o.Order_Date_Time DESC
");

$rows = [];
while ($r = $orders->fetch_assoc()) $rows[] = $r;

$total_orders  = count($rows);
$total_spent   = array_sum(array_column($rows, 'Order_Total'));
$pending_count = count(array_filter($rows, fn($r) => $r['Order_Status'] === 'Pending'));
$trackable     = ['Preparing', 'Ready for Pickup'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>My Orders — Sip &amp; Savor</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
  <style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --water:#0070ff;--water-mid:#1588ff;--water-bright:#0097ff;--water-light:#00b1ff;
  --sky:#e6f4ff;--sky2:#cceeff;
  --deep:#002d6e;--deep2:#003d8f;--navy:#001a4d;
  --text:#051c3a;--text-soft:#2d5a8e;--text-muted:#5e8ab4;
  --border:#b3d4f0;--border-dark:#7ab3e0;--card:#f5faff;
  --serif:'Playfair Display',serif;--sans:'DM Sans',sans-serif;--mono:'DM Mono',monospace;
  --radius:16px;
  --track:#00c896;--track-dark:#009e77;--track-glow:rgba(0,200,150,0.13);
}
body{background:#eef7ff;color:var(--text);font-family:var(--sans);min-height:100vh}

/* Topbar */
.topbar{background:var(--navy);padding:0 40px;height:64px;display:flex;align-items:center;
  justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 20px rgba(0,26,77,0.4)}
.logo{font-family:var(--serif);font-size:20px;color:#e6f4ff}.logo span{color:var(--water-light)}
.topbar-right{display:flex;align-items:center;gap:12px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:30px;
  font-family:var(--sans);font-size:13px;font-weight:600;cursor:pointer;border:none;
  text-decoration:none;transition:all 0.2s}
.btn-warm{color:#fff;background:var(--water)}.btn-warm:hover{background:var(--water-mid);transform:translateY(-1px)}
.btn-outline{color:#cce8ff;background:transparent;border:1.5px solid rgba(0,177,255,0.3)}
.btn-outline:hover{background:rgba(0,112,255,0.15)}
.logout-link{font-size:12px;color:rgba(168,212,245,0.4);text-decoration:none;font-family:var(--mono)}
.logout-link:hover{color:var(--water-light)}

/* Hero */
.hero-strip{background:linear-gradient(135deg,var(--navy) 0%,var(--deep2) 100%);padding:36px 40px}
.hero-strip h1{font-family:var(--serif);font-size:32px;color:#e6f4ff;margin-bottom:4px}
.hero-strip h1 span{color:var(--water-light)}
.hero-strip p{font-size:13px;color:rgba(168,212,245,0.55);font-family:var(--mono)}

/* Content */
.content{max-width:960px;margin:0 auto;padding:36px 24px}

/* Stats */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:32px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px}
.stat-label{font-size:11px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px}
.stat-value{font-family:var(--serif);font-size:28px;font-weight:700;color:var(--water)}

/* Alerts */
.alert{padding:14px 18px;border-radius:var(--radius);margin-bottom:24px;font-family:var(--mono);font-size:13px}
.alert-success{background:rgba(0,151,255,0.08);border:1px solid rgba(0,151,255,0.25);color:var(--water-mid)}
.alert-cancel{background:rgba(192,74,0,0.08);border:1px solid rgba(192,74,0,0.22);color:#c04a00}

/* Order cards */
.order-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;margin-bottom:16px;transition:box-shadow 0.2s,border-color 0.2s}
.order-card:hover{box-shadow:0 6px 24px rgba(0,112,255,0.1);border-color:var(--border-dark)}
.order-card.is-trackable{border-left:3px solid var(--track);animation:trackPulse 3s ease-in-out infinite}
@keyframes trackPulse{0%,100%{border-left-color:var(--track)}50%{border-left-color:var(--track-dark)}}

.order-card-header{display:flex;align-items:center;justify-content:space-between;
  padding:12px 20px;border-bottom:1px solid var(--border);background:var(--sky);flex-wrap:wrap;gap:8px}
.order-id{font-family:var(--mono);font-size:12px;color:var(--text-muted)}
.order-date{font-family:var(--mono);font-size:11px;color:var(--text-muted)}
.order-card-body{display:flex;align-items:flex-start;gap:16px;padding:16px 20px}
.order-img{width:64px;height:64px;border-radius:10px;object-fit:cover;border:1px solid var(--border);flex-shrink:0}
.order-img-placeholder{width:64px;height:64px;border-radius:10px;background:var(--sky2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0}
.order-info{flex:1;min-width:0}
.order-name{font-family:var(--serif);font-size:16px;font-weight:600;color:var(--deep2);margin-bottom:5px}
.order-meta{font-size:12px;color:var(--text-muted);font-family:var(--mono);display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.order-note{display:flex;align-items:flex-start;gap:6px;background:var(--sky);border:1px dashed var(--border-dark);border-radius:8px;padding:8px 12px;font-size:12px;color:var(--text-soft);margin-top:6px;line-height:1.5}
.order-note .note-icon{flex-shrink:0;font-size:13px}
.order-right{text-align:right;flex-shrink:0}
.order-total{font-family:var(--serif);font-size:20px;font-weight:700;color:var(--water);margin-bottom:4px}
.order-qty{font-size:11px;font-family:var(--mono);color:var(--text-muted);margin-bottom:8px}

/* Buttons */
.btn-cancel{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:20px;
  font-size:12px;font-weight:600;font-family:var(--sans);cursor:pointer;text-decoration:none;
  border:1.5px solid rgba(192,74,0,0.3);color:#c04a00;background:rgba(192,74,0,0.07);transition:all 0.2s}
.btn-cancel:hover{background:rgba(192,74,0,0.15);border-color:#c04a00}
.btn-track{display:inline-flex;align-items:center;gap:6px;padding:6px 16px;border-radius:20px;
  font-size:12px;font-weight:700;font-family:var(--sans);cursor:pointer;text-decoration:none;
  border:1.5px solid var(--track);color:var(--track-dark);background:var(--track-glow);
  transition:all 0.2s;position:relative;overflow:hidden}
.btn-track::before{content:'';position:absolute;inset:0;
  background:linear-gradient(90deg,transparent,rgba(0,200,150,0.15),transparent);
  transform:translateX(-100%);transition:transform 0.5s}
.btn-track:hover::before{transform:translateX(100%)}
.btn-track:hover{background:rgba(0,200,150,0.25);border-color:var(--track-dark);
  transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,200,150,0.25)}
.pulse-dot{width:7px;height:7px;border-radius:50%;background:var(--track);display:inline-block;
  animation:pulseDot 1.4s ease-in-out infinite}
@keyframes pulseDot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:0.4;transform:scale(0.7)}}

/* Badges */
.badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:600}
.badge-pending{background:rgba(0,112,255,0.1);color:var(--water);border:1px solid rgba(0,112,255,0.25)}
.badge-processing{background:rgba(0,177,255,0.12);color:var(--water-bright);border:1px solid rgba(0,177,255,0.3)}
.badge-completed{background:rgba(0,151,255,0.15);color:var(--deep2);border:1px solid rgba(0,112,255,0.3)}
.badge-cancelled{background:rgba(192,74,0,0.1);color:#c04a00;border:1px solid rgba(192,74,0,0.25)}
.badge-pickup{background:rgba(0,200,150,0.12);color:var(--track-dark);border:1px solid rgba(0,200,150,0.3)}

/* Empty */
.empty{text-align:center;padding:80px 20px}
.empty .icon{font-size:64px;margin-bottom:16px;opacity:0.4;display:block}
.empty h3{font-family:var(--serif);font-size:22px;color:var(--deep2);margin-bottom:8px}
.empty p{font-size:14px;color:var(--text-muted);margin-bottom:20px}
.empty a{display:inline-block;padding:11px 28px;background:var(--water);color:#fff;
  border-radius:30px;text-decoration:none;font-weight:600;font-size:13px}
.empty a:hover{background:var(--deep2)}

.section-title{font-family:var(--serif);font-size:20px;color:var(--deep2);margin-bottom:16px}
.section-title span{color:var(--water)}
.footer{background:var(--navy);color:rgba(168,212,245,0.4);text-align:center;padding:20px;font-size:12px;font-family:var(--mono);margin-top:60px}

/* ══════════════════════════════════════════════════════════
   CANCEL MODAL
══════════════════════════════════════════════════════════ */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,26,77,0.55);
  z-index:200;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-overlay.show{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:36px;
  max-width:380px;width:90%;text-align:center;box-shadow:0 24px 60px rgba(0,26,77,0.25)}
.modal-icon{font-size:48px;margin-bottom:12px}
.modal h3{font-family:var(--serif);font-size:22px;color:var(--deep2);margin-bottom:8px}
.modal p{font-size:13px;color:var(--text-muted);margin-bottom:24px;line-height:1.6}
.modal-actions{display:flex;gap:10px;justify-content:center}
.modal-actions .btn-confirm{padding:10px 24px;background:#c04a00;color:#fff;border:none;
  border-radius:30px;font-family:var(--sans);font-size:13px;font-weight:700;cursor:pointer}
.modal-actions .btn-confirm:hover{background:#a03800}
.modal-actions .btn-back{padding:10px 24px;background:var(--sky);color:var(--text-soft);
  border:1.5px solid var(--border);border-radius:30px;font-family:var(--sans);font-size:13px;font-weight:600;cursor:pointer}
.modal-actions .btn-back:hover{background:var(--sky2)}

/* ══════════════════════════════════════════════════════════
   LIVE TRACKER MODAL (customer watch-only)
   - No Rider URL anywhere in this modal
   - Destination pin comes from shipping_addresses via app.js
══════════════════════════════════════════════════════════ */
.track-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,10,40,0.75);
  z-index:300;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
.track-modal-overlay.show{display:flex}
.track-modal{background:var(--navy);border:1px solid rgba(0,200,150,0.2);border-radius:24px;
  width:min(720px,96vw);max-height:90vh;overflow:hidden;
  box-shadow:0 32px 80px rgba(0,26,77,0.6);display:flex;flex-direction:column}

/* Header */
.track-modal-header{display:flex;align-items:center;justify-content:space-between;
  padding:18px 24px;border-bottom:1px solid rgba(0,200,150,0.12);
  background:rgba(0,10,40,0.4);flex-shrink:0}
.track-title-group h3{font-family:var(--serif);font-size:18px;color:#e6f4ff;margin-bottom:3px}
.track-sub{font-size:11px;font-family:var(--mono);color:rgba(168,212,245,0.4)}
.live-badge{display:inline-flex;align-items:center;gap:5px;
  background:rgba(0,200,150,0.12);border:1px solid rgba(0,200,150,0.3);
  border-radius:20px;padding:3px 10px;font-size:11px;font-family:var(--mono);
  font-weight:700;color:var(--track);text-transform:uppercase;letter-spacing:1px;margin-left:8px}
.live-dot{width:6px;height:6px;border-radius:50%;background:var(--track);display:inline-block;
  animation:pulseDot 1.2s ease-in-out infinite}
.btn-close-track{width:36px;height:36px;border-radius:50%;
  background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);
  color:rgba(168,212,245,0.5);font-size:18px;cursor:pointer;display:flex;
  align-items:center;justify-content:center;transition:all 0.2s;flex-shrink:0}
.btn-close-track:hover{background:rgba(255,255,255,0.12);color:#fff}

/* Timeline */
.track-timeline{display:flex;align-items:center;padding:14px 24px;
  background:rgba(0,10,40,0.3);border-bottom:1px solid rgba(0,200,150,0.08);
  gap:0;overflow-x:auto;flex-shrink:0}
.track-step-wrap{display:flex;flex-direction:column;align-items:center;gap:4px;flex-shrink:0}
.track-step-dot{width:28px;height:28px;border-radius:50%;border:2px solid rgba(168,212,245,0.15);
  display:flex;align-items:center;justify-content:center;font-size:12px;
  background:rgba(0,20,60,0.5);transition:all 0.3s}
.track-step-dot.done{border-color:var(--track);background:rgba(0,200,150,0.15)}
.track-step-dot.active{border-color:var(--track);background:rgba(0,200,150,0.22);
  animation:activeGlow 2s ease-in-out infinite}
@keyframes activeGlow{0%,100%{box-shadow:0 0 0 4px rgba(0,200,150,0.1)}50%{box-shadow:0 0 0 8px rgba(0,200,150,0.05)}}
.track-step-label{font-size:10px;font-family:var(--mono);color:rgba(168,212,245,0.35);white-space:nowrap}
.track-step-label.active{color:var(--track);font-weight:700}
.track-connector{width:44px;height:2px;background:rgba(168,212,245,0.08);
  margin:0 4px;margin-bottom:14px;flex-shrink:0;transition:background 0.4s}
.track-connector.done{background:var(--track)}

/* Map iframe — fills the modal body */
.track-map-wrap{flex:1;position:relative;min-height:360px}
.track-map-wrap iframe{width:100%;height:100%;min-height:360px;border:none;display:block}

/* Rider status bar */
.rider-status-bar{padding:10px 20px;background:rgba(0,10,40,0.5);
  border-top:1px solid rgba(0,200,150,0.08);display:flex;align-items:center;
  gap:10px;flex-shrink:0}
.status-dot{width:8px;height:8px;border-radius:50%;background:rgba(168,212,245,0.2);flex-shrink:0;transition:background 0.3s}
.status-dot.online{background:var(--track);animation:pulseDot 1.4s ease-in-out infinite}
.status-text{font-size:12px;font-family:var(--mono);color:rgba(168,212,245,0.45)}

/* Map legend strip */
.map-legend{display:flex;align-items:center;gap:16px;
  padding:8px 20px;background:rgba(0,10,40,0.4);
  border-top:1px solid rgba(168,212,245,0.06);flex-shrink:0}
.legend-item{display:flex;align-items:center;gap:6px;font-size:11px;
  font-family:var(--mono);color:rgba(168,212,245,0.4)}
.legend-dot{width:10px;height:10px;border-radius:50%;border:2px solid #fff;flex-shrink:0}

@media(max-width:600px){
  .topbar{padding:0 16px}
  .hero-strip{padding:24px 16px}
  .stats-row{gap:10px}.stat-value{font-size:22px}
  .order-card-body{flex-wrap:wrap}
  .track-modal{width:100vw;max-height:100dvh;border-radius:0}
  .track-connector{width:22px}
}
  </style>
</head>
<body>

<div class="topbar">
  <div class="logo">Sip &amp; <span>Savor</span></div>
  <div class="topbar-right">
    <a href="shop.php" class="btn btn-warm">Home</a>
    <a href="cart.php" class="btn btn-outline">🛒 Cart</a>
    <a href="../auth/logout.php" class="logout-link">logout</a>
  </div>
</div>

<div class="hero-strip">
  <h1>My <span>Orders</span></h1>
  <p>Logged in as <?= htmlspecialchars($_SESSION['full_name']) ?></p>
</div>

<div class="content">

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">💧 Order placed! We'll get it on the way soon.</div>
  <?php endif; ?>
  <?php if (isset($_GET['cancelled'])): ?>
    <div class="alert alert-cancel">✕ Order cancelled. Stock has been restored.</div>
  <?php endif; ?>

  <div class="stats-row">
    <div class="stat-card"><div class="stat-label">Total Orders</div><div class="stat-value"><?= $total_orders ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Spent</div><div class="stat-value">₱<?= number_format($total_spent,2) ?></div></div>
    <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value"><?= $pending_count ?></div></div>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty">
      <span class="icon">🧋</span>
      <h3>No orders yet</h3>
      <p>Your order history will appear here once you place your first order.</p>
      <a href="shop.php">Browse the menu</a>
    </div>
  <?php else: ?>
    <h2 class="section-title">Order <span>History</span></h2>

    <?php foreach ($rows as $row):
      $isTrackable = in_array($row['Order_Status'], $trackable);
      $badge = match($row['Order_Status']) {
        'Completed'        => 'badge-completed',
        'Pending'          => 'badge-pending',
        'Preparing'        => 'badge-processing',
        'Ready for Pickup' => 'badge-pickup',
        'Cancelled'        => 'badge-cancelled',
        default            => 'badge-pending'
      };
      // Destination coords from shipping_addresses (may be null)
      $destLat = $row['Dest_Lat'] ?? '';
      $destLng = $row['Dest_Lng'] ?? '';
    ?>
    <div class="order-card<?= $isTrackable ? ' is-trackable' : '' ?>">
      <div class="order-card-header">
        <span class="order-id">Order #<?= $row['Order_ID'] ?></span>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span class="badge <?= $badge ?>"><?= htmlspecialchars($row['Order_Status']) ?></span>
          <span class="order-date"><?= date('M d, Y · h:i A', strtotime($row['Order_Date_Time'])) ?></span>

          <?php if ($row['Order_Status'] === 'Pending'): ?>
            <a href="#" class="btn-cancel"
               onclick="confirmCancel(<?= $row['Order_ID'] ?>); return false;">✕ Cancel</a>
          <?php endif; ?>

          <?php if ($isTrackable): ?>
            <!--
              data-lat / data-lng: the customer's saved address coordinates.
              Passed to app.js which places the 🏠 destination pin on the map.
              No rider URL here — riders get their link from admin/orders.php only.
            -->
            <a href="#" class="btn-track"
               data-lat="<?= htmlspecialchars($destLat) ?>"
               data-lng="<?= htmlspecialchars($destLng) ?>"
               onclick="openTracker(this, <?= $row['Order_ID'] ?>, '<?= htmlspecialchars(addslashes($row['Product_Name'])) ?>', '<?= $row['Order_Status'] ?>'); return false;">
              <span class="pulse-dot"></span> Track Live
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="order-card-body">
        <?php if (!empty($row['Product_Image'])): ?>
          <img class="order-img" src="../<?= htmlspecialchars($row['Product_Image']) ?>" alt="">
        <?php else: ?>
          <div class="order-img-placeholder">🧋</div>
        <?php endif; ?>
        <div class="order-info">
          <div class="order-name"><?= htmlspecialchars($row['Product_Name']) ?></div>
          <div class="order-meta">
            <span>📦 Qty: <?= $row['Order_Quantity'] ?></span>
            <span>💳 <?= htmlspecialchars($row['Payment_Type_Description']) ?></span>
            <span>👤 <?= htmlspecialchars($row['Customer_Type_Description']) ?></span>
            <span>🏷️ ₱<?= number_format($row['Product_Price'],2) ?>/ea</span>
          </div>
          <?php if (!empty($row['Order_Note'])): ?>
            <div class="order-note"><span class="note-icon">📝</span><span><?= htmlspecialchars($row['Order_Note']) ?></span></div>
          <?php endif; ?>
        </div>
        <div class="order-right">
          <div class="order-total">₱<?= number_format($row['Order_Total'],2) ?></div>
          <div class="order-qty"><?= $row['Order_Quantity'] ?> × ₱<?= number_format($row['Product_Price'],2) ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="footer">🧋 Sip &amp; Savor — Made with love &amp; the finest ingredients</div>

<!-- Cancel modal -->
<div class="modal-overlay" id="cancelModal">
  <div class="modal">
    <div class="modal-icon">🧋</div>
    <h3>Cancel this order?</h3>
    <p>This can't be undone, but your stock will be restored.</p>
    <div class="modal-actions">
      <button class="btn-back" onclick="closeModal()">Keep it</button>
      <a href="#" class="btn-confirm" id="confirmLink">Yes, cancel</a>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     LIVE TRACKER MODAL — customer view only
     iframe loads: localhost:3000?order_id=X&role=customer&dest_lat=Y&dest_lng=Z
     app.js uses dest_lat/lng to look up and show the 🏠 destination pin.
     The 🚐 rider pin appears when the rider opens their own link (via admin).
     NO rider URL is shown to the customer anywhere on this page.
══════════════════════════════════════════════════════════════════════════ -->
<div class="track-modal-overlay" id="trackModal">
  <div class="track-modal">

    <div class="track-modal-header">
      <div class="track-title-group">
        <h3 id="trackTitle">
          Live Tracking
          <span class="live-badge"><span class="live-dot"></span> Live</span>
        </h3>
        <div class="track-sub" id="trackSub">Connecting…</div>
      </div>
      <button class="btn-close-track" onclick="closeTracker()">✕</button>
    </div>

    <!-- Progress timeline -->
    <div class="track-timeline">
      <div class="track-step-wrap">
        <div class="track-step-dot done" id="tl-ordered">✓</div>
        <div class="track-step-label">Ordered</div>
      </div>
      <div class="track-connector done" id="tl-line-1"></div>
      <div class="track-step-wrap">
        <div class="track-step-dot" id="tl-preparing">⚙</div>
        <div class="track-step-label" id="tl-lbl-preparing">Preparing</div>
      </div>
      <div class="track-connector" id="tl-line-2"></div>
      <div class="track-step-wrap">
        <div class="track-step-dot" id="tl-onway">🚐</div>
        <div class="track-step-label" id="tl-lbl-onway">On the Way</div>
      </div>
      <div class="track-connector" id="tl-line-3"></div>
      <div class="track-step-wrap">
        <div class="track-step-dot" id="tl-delivered">🏠</div>
        <div class="track-step-label" id="tl-lbl-delivered">Delivered</div>
      </div>
    </div>

    <!-- Map iframe — customer role, no rider URL passed -->
    <div class="track-map-wrap">
      <iframe id="trackIframe" src="about:blank" title="Live Delivery Map"
              allow="geolocation" sandbox="allow-scripts allow-same-origin"></iframe>
    </div>

    <!-- Rider connection status -->
    <div class="rider-status-bar">
      <div class="status-dot" id="riderDot"></div>
      <div class="status-text" id="riderStatusText">Waiting for rider to connect…</div>
    </div>

    <!-- Map legend — 🏠 destination, 🚐 rider -->
    <div class="map-legend">
      <div class="legend-item">
        <div class="legend-dot" style="background:#0070ff"></div>
        🏠 Your delivery address
      </div>
      <div class="legend-item">
        <div class="legend-dot" style="background:#00c896"></div>
        🚐 Rider location
      </div>
    </div>

  </div>
</div>

<script>
// Tracker server base URL — change if using ngrok
const TRACKER_HOST = 'http://localhost:3000';

/* ── Cancel modal ── */
function confirmCancel(orderId) {
  document.getElementById('confirmLink').href = '?cancel=' + orderId;
  document.getElementById('cancelModal').classList.add('show');
}
function closeModal() {
  document.getElementById('cancelModal').classList.remove('show');
}
document.getElementById('cancelModal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

/* ── Track modal ──
   el    = the <a> element with data-lat / data-lng attributes
   orderId, productName, status = order details
   IMPORTANT: No rider URL is constructed or shown here.
              The destination pin comes from the lat/lng stored in
              shipping_addresses when the order was placed.
*/
function openTracker(el, orderId, productName, status) {
  // Read destination coordinates from data attributes on the button
  const destLat = el.dataset.lat || '';
  const destLng = el.dataset.lng || '';

  // Update modal header
  document.getElementById('trackTitle').innerHTML =
    'Order #' + orderId +
    ' <span class="live-badge"><span class="live-dot"></span> Live</span>';
  document.getElementById('trackSub').textContent = productName + ' · ' + status;

  // Update timeline
  updateTimeline(status);

  // Reset rider status
  setRiderStatus(false, 'Waiting for rider to connect…');

  // Build customer iframe URL:
  // - role=customer  → script.js knows to only watch, not stream GPS
  // - dest_lat/lng   → app.js passes these to EJS → DEST_LAT/LNG globals
  //                    → script.js places 🏠 pin immediately on map load
  let customerUrl = TRACKER_HOST
    + '?order_id=' + encodeURIComponent(orderId)
    + '&role=customer';
  if (destLat && destLng) {
    customerUrl += '&dest_lat=' + encodeURIComponent(destLat)
                 + '&dest_lng=' + encodeURIComponent(destLng);
  }

  document.getElementById('trackIframe').src = customerUrl;
  document.getElementById('trackModal').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeTracker() {
  document.getElementById('trackModal').classList.remove('show');
  document.body.style.overflow = '';
  setTimeout(function() {
    document.getElementById('trackIframe').src = 'about:blank';
  }, 280);
}

document.getElementById('trackModal').addEventListener('click', function(e) {
  if (e.target === this) closeTracker();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') { closeTracker(); closeModal(); }
});

/* ── Timeline ── */
function updateTimeline(status) {
  ['tl-preparing','tl-onway','tl-delivered'].forEach(function(id) {
    const el = document.getElementById(id);
    el.classList.remove('done','active');
    // Reset emoji
    if (id === 'tl-preparing') el.textContent = '⚙';
    if (id === 'tl-onway')     el.textContent = '🚐';
    if (id === 'tl-delivered') el.textContent = '🏠';
  });
  ['tl-lbl-preparing','tl-lbl-onway','tl-lbl-delivered'].forEach(function(id) {
    document.getElementById(id).classList.remove('active');
  });
  ['tl-line-2','tl-line-3'].forEach(function(id) {
    document.getElementById(id).classList.remove('done');
  });

  if (status === 'Preparing') {
    document.getElementById('tl-preparing').classList.add('active');
    document.getElementById('tl-lbl-preparing').classList.add('active');
  } else if (status === 'Ready for Pickup') {
    document.getElementById('tl-preparing').classList.add('done');
    document.getElementById('tl-preparing').textContent = '✓';
    document.getElementById('tl-line-2').classList.add('done');
    document.getElementById('tl-onway').classList.add('active');
    document.getElementById('tl-lbl-onway').classList.add('active');
  } else if (status === 'Completed') {
    ['tl-preparing','tl-onway','tl-delivered'].forEach(function(id) {
      const el = document.getElementById(id);
      el.classList.add('done'); el.textContent = '✓';
    });
    ['tl-line-2','tl-line-3'].forEach(function(id) {
      document.getElementById(id).classList.add('done');
    });
  }
}

/* ── Rider status bar ── */
function setRiderStatus(online, msg) {
  const dot  = document.getElementById('riderDot');
  const text = document.getElementById('riderStatusText');
  online ? dot.classList.add('online') : dot.classList.remove('online');
  text.innerHTML = msg;
}

/* ── postMessage from iframe → update status bar ──
   In script.js, these are sent via window.parent.postMessage():
     { type: 'rider-online' }
     { type: 'rider-moving' }
     { type: 'rider-offline' }
*/
window.addEventListener('message', function(e) {
  if (e.origin !== TRACKER_HOST) return;
  switch (e.data && e.data.type) {
    case 'rider-online':
      setRiderStatus(true, '🚐 <span style="color:rgba(168,212,245,0.7)">Rider is online and heading your way</span>');
      break;
    case 'rider-moving':
      setRiderStatus(true, '📍 <span style="color:rgba(168,212,245,0.7)">Rider location updated</span>');
      break;
    case 'rider-offline':
      setRiderStatus(false, '⚠ Rider disconnected. Waiting to reconnect…');
      break;
  }
});
</script>
</body>
</html>