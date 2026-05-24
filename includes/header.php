<?php require_once __DIR__ . '/auth_check.php'; require_role('admin'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?? 'Admin' ?> — ShopAdmin</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0e0f11; --surface: #16181c; --card: #1c1f25; --border: #2a2d35;
      --accent: #4ade80; --accent2: #22d3ee; --danger: #f87171; --warn: #fbbf24;
      --text: #e8eaf0; --muted: #6b7280; --radius: 10px;
      --mono: 'DM Mono', monospace; --sans: 'Syne', sans-serif; --sidebar: 220px;
    }
    body { background: var(--bg); color: var(--text); font-family: var(--sans); font-size: 14px; min-height: 100vh; }
    .sidebar {
      width: var(--sidebar); min-height: 100vh; background: var(--surface);
      border-right: 1px solid var(--border); display: flex; flex-direction: column;
      padding: 28px 0; position: fixed; top: 0; left: 0; z-index: 100;
    }
    .sidebar-logo { font-size: 18px; font-weight: 700; color: var(--accent); padding: 0 24px 28px; letter-spacing: -0.5px; border-bottom: 1px solid var(--border); }
    .sidebar-logo span { color: var(--text); }
    .sidebar-user { padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .sidebar-user .role-badge { font-size: 10px; font-family: var(--mono); background: rgba(74,222,128,0.12); color: var(--accent); padding: 2px 8px; border-radius: 20px; text-transform: uppercase; }
    .sidebar-user .name { font-size: 13px; font-weight: 600; margin-top: 6px; }
    .nav-section { padding: 20px 12px 8px; font-size: 10px; font-family: var(--mono); color: var(--muted); text-transform: uppercase; letter-spacing: 1.5px; }
    .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 24px; color: var(--muted); text-decoration: none; font-size: 13px; font-weight: 600; transition: all 0.15s; border-left: 2px solid transparent; }
    .nav-link:hover, .nav-link.active { color: var(--text); background: rgba(255,255,255,0.04); border-left-color: var(--accent); }
    .nav-link .icon { font-size: 15px; width: 18px; text-align: center; }
    .nav-link.danger { color: var(--danger); }
    .nav-link.danger:hover { background: rgba(248,113,113,0.08); border-left-color: var(--danger); }
    .main { margin-left: var(--sidebar); padding: 32px 36px; min-height: 100vh; }
    .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    .page-title { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
    .page-title span { color: var(--accent); }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 24px; }
    .card-title { font-size: 12px; font-family: var(--mono); color: var(--muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 18px; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    thead th { text-align: left; padding: 10px 14px; font-family: var(--mono); font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid var(--border); }
    tbody tr { border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.1s; }
    tbody tr:hover { background: rgba(255,255,255,0.03); }
    tbody td { padding: 12px 14px; color: var(--text); vertical-align: middle; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-family: var(--mono); font-weight: 500; }
    .badge-green  { background: rgba(74,222,128,0.12);  color: var(--accent); }
    .badge-yellow { background: rgba(251,191,36,0.12);   color: var(--warn); }
    .badge-red    { background: rgba(248,113,113,0.12);  color: var(--danger); }
    .badge-blue   { background: rgba(34,211,238,0.12);   color: var(--accent2); }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: var(--radius); font-family: var(--sans); font-size: 13px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: all 0.15s; }
    .btn-primary { background: var(--accent); color: #0e0f11; }
    .btn-primary:hover { background: #22c55e; }
    .btn-danger { background: rgba(248,113,113,0.15); color: var(--danger); border: 1px solid rgba(248,113,113,0.3); }
    .btn-danger:hover { background: rgba(248,113,113,0.25); }
    .btn-ghost { background: rgba(255,255,255,0.06); color: var(--text); }
    .btn-ghost:hover { background: rgba(255,255,255,0.1); }
    .btn-sm { padding: 5px 10px; font-size: 12px; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
    .form-group { display: flex; flex-direction: column; gap: 6px; }
    .form-group.full { grid-column: 1 / -1; }
    label { font-size: 11px; font-family: var(--mono); color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
    input, select, textarea { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); font-family: var(--sans); font-size: 13px; padding: 10px 14px; outline: none; transition: border-color 0.15s; width: 100%; }
    input:focus, select:focus, textarea:focus { border-color: var(--accent); }
    select option { background: var(--surface); }
    textarea { resize: vertical; min-height: 80px; }
    .form-actions { display: flex; gap: 10px; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
    .alert { padding: 12px 16px; border-radius: var(--radius); margin-bottom: 20px; font-size: 13px; font-family: var(--mono); }
    .alert-success { background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.3); color: var(--accent); }
    .alert-error { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3); color: var(--danger); }
    .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; }
    .stat-label { font-size: 10px; font-family: var(--mono); color: var(--muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
    .stat-value { font-size: 26px; font-weight: 700; color: var(--accent); letter-spacing: -1px; }
    .mono { font-family: var(--mono); }
  </style>
</head>
<body>
<aside class="sidebar">
  <div class="sidebar-logo">shop<span>admin</span></div>
  <div class="sidebar-user">
    <span class="role-badge">Admin</span>
    <div class="name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
  </div>
  <div class="nav-section">Main</div>
  <a href="../admin/dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>"><span class="icon">◈</span> Dashboard</a>
  <div class="nav-section">Manage</div>
  <a href="../admin/orders.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'orders.php' ? 'active' : '' ?>"><span class="icon">⊞</span> Orders</a>
  <a href="../admin/products.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : '' ?>"><span class="icon">⊟</span> Products</a>
  <a href="../admin/users.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : '' ?>"><span class="icon">⊕</span> Users</a>
  <a href="../admin/customer_types.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'customer_types.php' ? 'active' : '' ?>"><span class="icon">◎</span> Customer Types</a>
  <a href="../admin/wallet.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'wallet.php' ? 'active' : '' ?>"><span class="icon">💳</span> Wallet</a>
  <a href="../admin/sizes.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'sizes.php' ? 'active' : '' ?>"><span class="icon">⊘</span> Sizes</a>
  <a href="../admin/payment_types.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'payment_types.php' ? 'active' : '' ?>"><span class="icon">⊗</span> Payment Types</a>
  <div style="margin-top:auto">
    <a href="../auth/logout.php" class="nav-link danger"><span class="icon">⏻</span> Logout</a>
  </div>
</aside>
<main class="main">