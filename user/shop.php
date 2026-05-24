<?php
session_start();
require '../config.php';

$search   = $conn->real_escape_string($_GET['search'] ?? '');
$where    = $search
    ? "WHERE Product_Status='Active' AND (Product_Name LIKE '%$search%' OR Product_Description LIKE '%$search%')"
    : "WHERE Product_Status='Active'";
$products = $conn->query("SELECT * FROM products $where ORDER BY Product_Name");

$is_logged_in = isset($_SESSION['user_id']) && $_SESSION['role'] === 'user';
$cart_count   = 0;
if ($is_logged_in) {
    $uid        = $_SESSION['user_id'];
    $cart_count = $conn->query("SELECT SUM(Quantity) AS c FROM cart WHERE User_ID=$uid")->fetch_assoc()['c'] ?? 0;
}

// Load global sizes
$sizes = [];
$sr = $conn->query("SELECT * FROM sizes ORDER BY Sort_Order ASC");
while ($s = $sr->fetch_assoc()) $sizes[] = $s;
$defaultSize = $sizes[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AquaLuxe Water Refilling Station</title>
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
.logo{display:flex;align-items:center;gap:10px}
.logo-icon{font-size:26px}
.logo-text{font-family:var(--serif);font-size:22px;color:#e6f4ff}.logo-text span{color:var(--water-light)}
.topbar-right{display:flex;align-items:center;gap:12px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border-radius:30px;font-family:var(--sans);font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all 0.2s}
.btn-outline{color:#cce8ff;background:transparent;border:1.5px solid rgba(0,177,255,0.3)}.btn-outline:hover{background:rgba(0,112,255,0.15)}
.btn-warm{color:#fff;background:var(--water)}.btn-warm:hover{background:var(--water-mid);transform:translateY(-1px)}
.btn-cart{color:var(--deep);background:#cce8ff}.btn-cart:hover{background:#b3dbff;transform:translateY(-1px)}
.cart-count{background:var(--water);color:#fff;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:11px;font-family:var(--mono)}
.user-chip{font-size:13px;color:#a8d4f5;font-weight:500}
.logout-link{font-size:12px;color:#cce8ff;text-decoration:none;font-family:var(--mono);transition:color 0.15s}.logout-link:hover{color:var(--water-light)}
.hero{background:linear-gradient(135deg,var(--navy) 0%,var(--deep2) 50%,#005bb5 100%);padding:64px 40px;text-align:center;position:relative;overflow:hidden}
.hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none'%3E%3Cg fill='%230097ff' fill-opacity='0.07'%3E%3Ccircle cx='30' cy='30' r='4'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")}
.hero-content{position:relative;z-index:1}
.hero-tag{display:inline-block;background:rgba(0,177,255,0.15);color:var(--water-light);border:1px solid rgba(0,177,255,0.35);padding:5px 16px;border-radius:20px;font-size:12px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:16px}
.hero-title{font-family:var(--serif);font-size:48px;color:#e6f4ff;line-height:1.15;margin-bottom:12px}.hero-title span{color:var(--water-light)}
.hero-sub{font-size:15px;color:rgba(200,230,255,0.65);max-width:480px;margin:0 auto 32px;line-height:1.6}
.search-bar{display:flex;max-width:480px;margin:0 auto;border-radius:30px;overflow:hidden;background:#e6f4ff;box-shadow:0 4px 24px rgba(0,26,77,0.25)}
.search-bar input{background:transparent;border:none;color:var(--text);font-family:var(--sans);font-size:14px;padding:14px 22px;outline:none;flex:1}.search-bar input::placeholder{color:var(--text-muted)}
.search-bar button{padding:14px 24px;background:var(--water);color:#fff;border:none;font-family:var(--sans);font-size:13px;font-weight:600;cursor:pointer;transition:background 0.15s}.search-bar button:hover{background:var(--deep2)}
.guest-banner{background:#dceeff;border-bottom:1px solid var(--border);padding:12px 40px;display:flex;align-items:center;justify-content:center;gap:16px;font-size:13px;color:var(--text-soft);flex-wrap:wrap}
.guest-banner strong{color:var(--deep2)}
.content{padding:40px;max-width:1400px;margin:0 auto}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
.section-title{font-family:var(--serif);font-size:26px;color:var(--deep2)}.section-title span{color:var(--water)}
.section-count{font-size:12px;font-family:var(--mono);color:var(--text-muted);background:var(--sky2);padding:4px 12px;border-radius:20px}
.clear-link{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);text-decoration:none;margin-bottom:20px;font-family:var(--mono)}.clear-link:hover{color:var(--water)}
.products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:24px}
.product-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;transition:transform 0.2s,box-shadow 0.2s,border-color 0.2s;position:relative}
.product-card:hover{transform:translateY(-4px);box-shadow:0 12px 40px rgba(0,112,255,0.13);border-color:var(--border-dark)}
.product-img{width:100%;height:200px;object-fit:cover;display:block}
.product-img-placeholder{width:100%;height:200px;background:linear-gradient(135deg,#dbeeff 0%,#c2e0ff 100%);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;border-bottom:1px solid var(--border)}
.product-img-placeholder .icon{font-size:48px;opacity:0.5}
.product-img-placeholder .label{font-size:11px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:1px}
.product-body{padding:16px 18px 10px;flex:1;display:flex;flex-direction:column;gap:6px}
.product-name{font-family:var(--serif);font-size:17px;font-weight:600;color:var(--deep2);line-height:1.3}
.product-desc{font-size:12px;color:var(--text-muted);line-height:1.6;flex:1}
.size-section{padding:0 18px 14px}
.size-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.size-label-text{font-size:11px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-right:2px}
.size-pill{width:38px;height:38px;border-radius:50%;border:2px solid var(--border-dark);background:var(--sky);color:var(--text-soft);font-family:var(--mono);font-size:13px;font-weight:800;cursor:pointer;transition:all 0.18s;display:flex;align-items:center;justify-content:center;position:relative;letter-spacing:0}
.size-pill:hover{border-color:var(--water-bright);background:var(--sky2);color:var(--deep2)}
.size-pill.active{border-color:var(--water);background:var(--water);color:#fff;box-shadow:0 3px 10px rgba(0,112,255,0.35);transform:scale(1.08)}
.size-pill .tooltip{position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:var(--navy);color:#e6f4ff;padding:4px 10px;border-radius:8px;font-size:10px;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity 0.15s;font-family:var(--sans);font-weight:600}
.size-pill .tooltip::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:4px solid transparent;border-top-color:var(--navy)}
.size-pill:hover .tooltip,.size-pill.active .tooltip{opacity:1}
.product-footer{display:flex;align-items:center;justify-content:space-between;padding:10px 18px 12px;border-top:1px dashed var(--border)}
.product-price{font-family:var(--serif);font-size:22px;font-weight:700;color:var(--water);transition:all 0.2s}
.product-stock{font-size:10px;font-family:var(--mono);color:var(--text-muted)}
.stock-low{color:#c04a00!important}
.add-btn{margin:0 18px 18px;padding:11px;background:var(--water);color:#fff;border:none;border-radius:30px;font-family:var(--sans);font-size:13px;font-weight:600;cursor:pointer;transition:all 0.2s}
.add-btn:hover{background:var(--deep2);transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,112,255,0.3)}
.add-btn:disabled{background:var(--sky2);color:var(--text-muted);cursor:not-allowed;transform:none;box-shadow:none}
.add-btn.guest{background:transparent;color:var(--water);border:1.5px solid var(--border-dark)}.add-btn.guest:hover{background:var(--sky)}
.stock-badge{position:absolute;top:12px;right:12px;padding:4px 10px;border-radius:20px;font-size:10px;font-family:var(--mono);font-weight:500}
.stock-badge.out{background:rgba(224,90,0,0.1);color:#c04a00;border:1px solid rgba(224,90,0,0.25)}
.stock-badge.low{background:rgba(0,151,255,0.1);color:var(--water);border:1px solid rgba(0,151,255,0.25)}
.toast{position:fixed;bottom:28px;right:28px;background:var(--navy);color:#e6f4ff;padding:13px 22px;border-radius:30px;font-weight:600;font-size:13px;opacity:0;transition:opacity 0.3s,transform 0.3s;transform:translateY(8px);pointer-events:none;z-index:999;box-shadow:0 8px 24px rgba(0,26,77,0.35)}
.toast.show{opacity:1;transform:translateY(0)}
.empty{text-align:center;padding:80px 20px;color:var(--text-muted)}
.empty .icon{font-size:56px;margin-bottom:16px;opacity:0.4}
.empty p{font-size:15px;font-family:var(--serif)}.empty a{color:var(--water);text-decoration:none;font-weight:600}
.footer{background:var(--navy);color:rgba(168,212,245,0.45);text-align:center;padding:24px;font-size:12px;font-family:var(--mono);margin-top:60px}
 /* Guest Banner Container */
    .guest-banner {
      background: var(--white); /* Darker background makes text pop */
      border-bottom: 1px solid var(--border-dark);
      padding: 10px 0;
      overflow: hidden; /* Hides the text outside the edges */
      position: relative;
      white-space: nowrap;
    }

    /* The moving track */
    .banner-track {
      display: flex;
      width: max-content;
      animation: slideLeft 30s linear infinite; /* Adjust speed here (30s) */
    }

    .banner-content {
      display: flex;
      align-items: center;
      gap: 60px; /* Space between messages */
      padding-right: 60px;
    }

    .banner-content span, .banner-content strong {
      font-size: 13px;
      color: var(--pink);
      font-family: var(--mono);
    }

    .banner-content strong {
      color: var(--pink);
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    /* The Animation */
    @keyframes slideLeft {
      0% { transform: translateX(0); }
      100% { transform: translateX(-50%); } /* Move half the width for seamless loop */
    }

    /* Pause on hover so users can read */
    .guest-banner:hover .banner-track {
      animation-play-state: paused;
    }
  </style>
</head>
<body>
<div class="topbar">
  <div class="logo">
    <span class="logo-icon">💧</span>
    <div class="logo-text">AquaLuxe </div>
  </div>
  <div class="topbar-right">
    <?php if ($is_logged_in): ?>
      <span class="user-chip">Hi, <?= htmlspecialchars($_SESSION['full_name']) ?> 👋</span>
      <a href="shop.php" class="btn btn-warm">Home</a>
      <a href="orders.php" class="btn btn-outline">My Orders</a>
      <a href="wallet.php" class="btn btn-outline"> Wallet 💳 ₱<?= number_format($conn->query("SELECT Wallet_Balance FROM users WHERE User_ID=$uid")->fetch_assoc()['Wallet_Balance'],2) ?></a>
      <a href="cart.php" class="btn btn-outline">🛒 Cart <span class="cart-count" id="cartCount"><?= $cart_count ?></span></a>
      <a href="../auth/logout.php" class="logout-link btn btn-outline">logout</a>
    <?php else: ?>
      <a href="../auth/login.php?redirect=user/shop.php" class="btn btn-outline">Sign In</a>
      <a href="../auth/register.php" class="btn btn-warm">Get Started</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$is_logged_in): ?>
<div class="guest-banner">
  <div class="banner-track">
    <div class="banner-content">
      <strong>💧 Welcome! Browse our water products freely.</strong>
      <strong><a href="../auth/login.php?redirect=user/shop.php" style="font-size:12px;color:var(--water);font-weight:200;text-decoration:none">Sign in</a> to enjoy exclusive offers.</strong>
      <strong>Check out our new promotions!</strong>
      <strong><a href="../auth/register.php" style="font-size:12px;color:var(--water);font-weight:200;text-decoration:none">Get</a> 5% off on your first order.</strong>
    </div>
  </div>
</div>
</div>
<?php endif; ?>

<div class="hero">
  <div class="hero-content">
    <div class="hero-tag">💧 Pure &amp; Purified</div>
    <h1 class="hero-title">Clean water,<br><span>delivered fresh</span></h1>
    <p class="hero-sub">Premium purified mineral water delivered straight to your door. Choose your size and order with ease.</p>
    <form class="search-bar" method="GET">
      <input type="text" name="search" placeholder="Search for gallon size or product..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
      <button type="submit">Search</button>
    </form>
  </div>
</div>

<div class="content">
  <?php if ($search): ?>
    <a href="shop.php" class="clear-link">← Back to all products</a>
  <?php endif; ?>
  <div class="section-header">
    <h2 class="section-title"><?= $search ? 'Results for <span>"'.htmlspecialchars($search).'"</span>' : 'Our <span>Products</span>' ?></h2>
    <span class="section-count"><?= $products->num_rows ?> item<?= $products->num_rows != 1 ? 's' : '' ?></span>
  </div>

  <?php if ($products->num_rows === 0): ?>
    <div class="empty">
      <div class="icon">💧</div>
      <p>No products found. <a href="shop.php">See all products</a></p>
    </div>
  <?php else: ?>
  <div class="products-grid">
    <?php while ($p = $products->fetch_assoc()): ?>
    <div class="product-card">
      <?php if ($p['Product_Quantity_Stock'] < 1): ?>
        <span class="stock-badge out">sold out</span>
      <?php elseif ($p['Product_Quantity_Stock'] <= 5): ?>
        <span class="stock-badge low">only <?= $p['Product_Quantity_Stock'] ?> left</span>
      <?php endif; ?>

      <?php if (!empty($p['Product_Image'])): ?>
        <img class="product-img" src="../<?= htmlspecialchars($p['Product_Image']) ?>" alt="<?= htmlspecialchars($p['Product_Name']) ?>">
      <?php else: ?>
        <div class="product-img-placeholder">
          <div class="icon">🫙</div>
          <div class="label">Photo coming soon</div>
        </div>
      <?php endif; ?>

      <div class="product-body">
        <div class="product-name"><?= htmlspecialchars($p['Product_Name']) ?></div>
        <?php if (!empty($p['Product_Description'])): ?>
          <div class="product-desc"><?= htmlspecialchars($p['Product_Description']) ?></div>
        <?php endif; ?>
      </div>
<!---      Load sizes for this product 
      <?php if (!empty($sizes)): ?>
      <div class="size-section">
        <div class="size-row">
          <span class="size-label-text">Size</span>
          <?php foreach ($sizes as $idx => $sz): ?>
          <button type="button"
            class="size-pill <?= $idx === 0 ? 'active' : '' ?>"
            onclick="selectSize(<?= $p['Product_ID'] ?>, <?= $sz['Size_ID'] ?>, <?= $sz['Size_Price'] ?>, <?= $p['Product_Price'] ?>, this)"
            data-size-id="<?= $sz['Size_ID'] ?>"
            data-addon="<?= $sz['Size_Price'] ?>">
            <?= htmlspecialchars($sz['Size_Label']) ?>
            <span class="tooltip"><?= htmlspecialchars($sz['Size_Name']) ?> — ₱<?= number_format($p['Product_Price'] + $sz['Size_Price'], 2) ?></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
          -->
      <?php endif; ?>

      <div class="product-footer">
        <div class="product-price" id="price-<?= $p['Product_ID'] ?>">
          ₱<?= number_format($p['Product_Price'] + ($defaultSize ? $defaultSize['Size_Price'] : 0), 2) ?>
        </div>
        <div class="product-stock <?= $p['Product_Quantity_Stock'] <= 5 && $p['Product_Quantity_Stock'] > 0 ? 'stock-low' : '' ?>">
          <?= $p['Product_Quantity_Stock'] ?> available
        </div>
      </div>

      <?php if ($p['Product_Quantity_Stock'] < 1): ?>
        <button class="add-btn" disabled>Sold Out</button>
      <?php elseif ($is_logged_in): ?>
        <button class="add-btn"
          id="addbtn-<?= $p['Product_ID'] ?>"
          data-product-id="<?= $p['Product_ID'] ?>"
          data-selected-size="<?= $defaultSize ? $defaultSize['Size_ID'] : '' ?>"
          onclick="addToCart(<?= $p['Product_ID'] ?>, this)">
          + Add to Order
        </button>
        <button class="add-btn" href="cart.php" 
          id="addbtn-<?= $p['Product_ID'] ?>"
          data-product-id="<?= $p['Product_ID'] ?>"
          data-selected-size="<?= $defaultSize ? $defaultSize['Size_ID'] : '' ?>"
          onclick="addToCart(<?= $p['Product_ID'] ?>, this); window.location.href='cart.php';">
          Buy Now
        </button>
      <?php else: ?>
        <button class="add-btn guest" onclick="window.location='../auth/login.php?redirect=user/shop.php'">Sign in to Order</button>
      <?php endif; ?>
    </div>
    <?php endwhile; ?>
  </div>
  <?php endif; ?>
</div>

<div class="footer">💧 AquaLuxe — Pure water, pure care, delivered to your door</div>
<div class="toast" id="toast">💧 Added to your order!</div>

<script>
const selectedSizes = {};

function selectSize(productId, sizeId, addon, basePrice, btn) {
  btn.closest('.size-row').querySelectorAll('.size-pill').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  selectedSizes[productId] = sizeId;
  const total = parseFloat(basePrice) + parseFloat(addon);
  document.getElementById('price-' + productId).textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});
  const addBtn = document.getElementById('addbtn-' + productId);
  if (addBtn) addBtn.dataset.selectedSize = sizeId;
}

function addToCart(productId, btn) {
  const sizeId = btn.dataset.selectedSize || '';
  btn.disabled = true; btn.textContent = 'Adding...';
  fetch('cart_action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=add&product_id=${productId}&size_id=${sizeId}`
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false; btn.textContent = '+ Add to Order';
    document.getElementById('cartCount').textContent = data.cart_count;
    const t = document.getElementById('toast');
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2500);
  });
}
</script>
</body>
</html>