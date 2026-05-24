<?php
require '../config.php';
$pageTitle = 'Dashboard';

$totalProducts = $conn->query("SELECT COUNT(*) AS c FROM products WHERE Product_Status='Active'")->fetch_assoc()['c'];
$totalOrders   = $conn->query("SELECT COUNT(*) AS c FROM orders")->fetch_assoc()['c'];
$pendingOrders = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Status='Pending'")->fetch_assoc()['c'];
$preparingOrders = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Status='Preparing'")->fetch_assoc()['c'];
$readyOrders   = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Order_Status='Out for Delivery'")->fetch_assoc()['c'];
$todayRevenue  = $conn->query("SELECT SUM(Order_Total) AS t FROM orders WHERE DATE(Order_Date_Time)=CURDATE() AND Order_Status='Completed'")->fetch_assoc()['t'] ?? 0;

$recentOrders = $conn->query("
    SELECT o.Order_ID, o.Order_Quantity, o.Order_Total, o.Order_Status,
           o.Order_Date_Time, o.Customer_Name, o.Order_Note,
           p.Product_Name, p.Product_Image,
           s.Size_Label, pt.Payment_Type_Description
    FROM orders o
    JOIN products p       ON o.Product_ID      = p.Product_ID
    JOIN payments_type pt ON o.Payment_Type_ID  = pt.Payment_Type_ID
    LEFT JOIN sizes s     ON o.Size_ID          = s.Size_ID
    ORDER BY o.Order_Date_Time DESC
    LIMIT 6
");

require '../includes/staff_header.php';
?>

<div class="page-header">
  <h1 class="page-title">Staff <span>Dashboard</span></h1>
  <span style="font-size:12px;font-family:var(--mono);color:var(--muted)"><?= date('l, M d Y · h:i A') ?></span>
</div>

<!-- Stat cards — same style as orders.php -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:24px">

  <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden">
    <div style="height:4px;background:var(--accent)"></div>
    <div style="padding:14px 16px">
      <div style="font-size:11px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">🛍️ Active Products</div>
      <div style="font-size:26px;font-weight:700;color:var(--accent);letter-spacing:-1px"><?= $totalProducts ?></div>
    </div>
  </div>

  <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden">
    <div style="height:4px;background:var(--warn)"></div>
    <div style="padding:14px 16px">
      <div style="font-size:11px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">🕐 Pending</div>
      <div style="font-size:26px;font-weight:700;color:var(--warn);letter-spacing:-1px"><?= $pendingOrders ?></div>
    </div>
  </div>

  <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden">
    <div style="height:4px;background:var(--accent2)"></div>
    <div style="padding:14px 16px">
      <div style="font-size:11px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">👨‍🍳 Preparing</div>
      <div style="font-size:26px;font-weight:700;color:var(--accent2);letter-spacing:-1px"><?= $preparingOrders ?></div>
    </div>
  </div>

  <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden">
    <div style="height:4px;background:var(--accent)"></div>
    <div style="padding:14px 16px">
      <div style="font-size:11px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">✅ Out for Delivery</div>
      <div style="font-size:26px;font-weight:700;color:var(--accent);letter-spacing:-1px"><?= $readyOrders ?></div>
    </div>
  </div>

  <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden">
    <div style="height:4px;background:#a78bfa"></div>
    <div style="padding:14px 16px">
      <div style="font-size:11px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">💰 Today's Sales</div>
      <div style="font-size:26px;font-weight:700;color:#a78bfa;letter-spacing:-1px">₱<?= number_format($todayRevenue, 0) ?></div>
    </div>
  </div>

</div>

<!-- Quick action strip -->
<?php if ($pendingOrders > 0): ?>
<div style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.25);border-radius:10px;padding:14px 20px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between">
  <span style="font-size:13px;color:var(--warn);font-family:var(--mono)">
    🕐 <?= $pendingOrders ?> order<?= $pendingOrders != 1 ? 's' : '' ?> waiting to be prepared
  </span>
  <a href="orders.php?filter=pending" class="btn btn-sm" style="background:var(--warn);color:#0e0f11;font-weight:700">View Pending →</a>
</div>
<?php endif; ?>

<!-- Recent orders -->
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
    <div class="card-title" style="margin-bottom:0">Recent Orders</div>
    <a href="orders.php" style="font-size:12px;font-family:var(--mono);color:var(--accent2);text-decoration:none">View all →</a>
  </div>

  <?php if ($recentOrders->num_rows === 0): ?>
    <p style="color:var(--muted);font-size:13px;font-family:var(--mono);text-align:center;padding:32px">No orders yet.</p>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:10px">
    <?php while ($row = $recentOrders->fetch_assoc()):
      $statusColor = match($row['Order_Status']) {
        'Pending'          => 'var(--warn)',
        'Preparing'        => 'var(--accent2)',
        'Out for Delivery' => 'var(--accent)',
        'Completed'        => 'var(--muted)',
        'Cancelled'        => 'var(--danger)',
        default            => 'var(--muted)'
      };
      $badgeBg = match($row['Order_Status']) {
        'Pending'          => 'rgba(251,191,36,0.12)',
        'Preparing'        => 'rgba(34,211,238,0.12)',
        'Out for Delivery' => 'rgba(74,222,128,0.12)',
        'Completed'        => 'rgba(107,114,128,0.12)',
        'Cancelled'        => 'rgba(248,113,113,0.12)',
        default            => 'rgba(107,114,128,0.12)'
      };
    ?>
    <div style="display:flex;align-items:center;gap:14px;padding:12px 16px;background:var(--surface);border:1px solid var(--border);border-radius:10px;border-left:4px solid <?= $statusColor ?>">
      <!-- Image -->
      <?php if (!empty($row['Product_Image'])): ?>
        <img src="../<?= htmlspecialchars($row['Product_Image']) ?>"
             style="width:44px;height:44px;border-radius:8px;object-fit:cover;border:1px solid var(--border);flex-shrink:0">
      <?php else: ?>
        <div style="width:44px;height:44px;border-radius:8px;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">🧋</div>
      <?php endif; ?>

      <!-- Info -->
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;flex-wrap:wrap">
          <span style="font-size:12px;font-family:var(--mono);color:var(--muted)">#<?= $row['Order_ID'] ?></span>
          <span style="font-size:11px;font-family:var(--mono);font-weight:600;padding:2px 10px;border-radius:20px;background:<?= $badgeBg ?>;color:<?= $statusColor ?>"><?= $row['Order_Status'] ?></span>
          <?php if (!empty($row['Order_Note'])): ?>
            <span style="font-size:10px;background:rgba(251,191,36,0.1);color:var(--warn);border:1px solid rgba(251,191,36,0.2);padding:2px 8px;border-radius:20px;font-family:var(--mono)">📝 note</span>
          <?php endif; ?>
        </div>
        <div style="font-size:14px;font-weight:600;color:var(--text)">
          <?= htmlspecialchars($row['Product_Name']) ?>
          <?php if (!empty($row['Size_Label'])): ?>
            <span style="font-size:10px;font-family:var(--mono);color:var(--muted);background:var(--card);border:1px solid var(--border);padding:1px 6px;border-radius:6px;margin-left:4px"><?= $row['Size_Label'] ?></span>
          <?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--muted);font-family:var(--mono);margin-top:2px">
          👤 <?= htmlspecialchars($row['Customer_Name'] ?: 'Guest') ?> &nbsp;·&nbsp;
          <?= htmlspecialchars($row['Payment_Type_Description']) ?> &nbsp;·&nbsp;
          Qty: <?= $row['Order_Quantity'] ?>
        </div>
      </div>

      <!-- Total + time -->
      <div style="text-align:right;flex-shrink:0">
        <div style="font-size:16px;font-weight:700;color:var(--accent);font-family:var(--mono)">₱<?= number_format($row['Order_Total'], 2) ?></div>
        <div style="font-size:10px;color:var(--muted);font-family:var(--mono);margin-top:2px"><?= date('M d · h:i A', strtotime($row['Order_Date_Time'])) ?></div>
      </div>
    </div>
    <?php endwhile; ?>
  </div>
  <?php endif; ?>
</div>

<?php require '../includes/footer.php'; ?>