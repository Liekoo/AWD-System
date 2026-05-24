<?php
require '../config.php';
require_once '../includes/auth_check.php';
require_login();
$uid = $_SESSION['user_id'];

$cart_items = $conn->query("
    SELECT c.Cart_ID, c.Quantity, c.Size_ID,
           p.Product_ID, p.Product_Name, p.Product_Price, p.Product_Quantity_Stock, p.Product_Image,
           s.Size_Label, s.Size_Name,
           p.Product_Price + COALESCE(s.Size_Price, 0) AS Unit_Price,
           (c.Quantity * (p.Product_Price + COALESCE(s.Size_Price, 0))) AS Subtotal
    FROM cart c
    JOIN products p ON c.Product_ID = p.Product_ID
    LEFT JOIN sizes s ON c.Size_ID = s.Size_ID
    WHERE c.User_ID = $uid
");

$items = [];

$grand_total = 0;

while ($r = $cart_items->fetch_assoc()) { $items[] = $r; $grand_total += $r['Subtotal']; }

$delivery_fee  = 10;
$service_fee   = 10;
$fees_total    = $delivery_fee + $service_fee;
$order_grand_total = $grand_total + $fees_total;

$payment_types  = $conn->query("SELECT * FROM payments_type");
$customer_name  = $_SESSION['full_name'];
$walletRow      = $conn->query("SELECT Wallet_Balance FROM users WHERE User_ID=$uid")->fetch_assoc();
$wallet_balance = $walletRow['Wallet_Balance'];

// Load saved addresses
$saved_addresses = $conn->query("SELECT * FROM shipping_addresses WHERE User_ID=$uid ORDER BY Is_Default DESC, Created_At DESC");
$addresses = [];
while ($a = $saved_addresses->fetch_assoc()) $addresses[] = $a;
$default_address = !empty($addresses) ? $addresses[0] : null;

// ── Handle checkout ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    $payment_id  = trim($_POST['Payment_Type_ID'] ?? '');
    $note        = $conn->real_escape_string(trim($_POST['Order_Note'] ?? ''));
    $use_wallet  = ($payment_id === 'wallet');
    $cust_name   = $conn->real_escape_string($customer_name);
    $ctype_row   = $conn->query("SELECT Customer_Type_ID FROM customer_type LIMIT 1")->fetch_assoc();
    $customer_id = $ctype_row ? $ctype_row['Customer_Type_ID'] : 1;
    $address_id  = !empty($_POST['Address_ID']) ? (int)$_POST['Address_ID'] : null;

    $fresh_total = 0;
    foreach ($items as $item) { $fresh_total += $item['Subtotal']; }

    if (empty($payment_id)) {
        // No payment selected — fall through
    } elseif (!$address_id) {
        $shipping_error = true;
    } elseif ($use_wallet && $wallet_balance < ($fresh_total + $fees_total)) {
        $wallet_error = true;
    } else {
        $actual_pid = $use_wallet
            ? ($conn->query("SELECT Payment_Type_ID FROM payments_type LIMIT 1")->fetch_assoc()['Payment_Type_ID'] ?? 1)
            : (int)$payment_id;

        foreach ($items as $item) {
            $pid = $item['Product_ID'];
            $sid = $item['Size_ID'] ?: 'NULL';
            $qty = $item['Quantity'];
            $price = $item['Unit_Price'];
            $fn  = $use_wallet ? ($note ? $note . ' [Wallet]' : 'Paid with Wallet') : $note;
            $fne = $conn->real_escape_string($fn);
            $conn->query("INSERT INTO orders
                (User_ID,Product_ID,Size_ID,Customer_Type_ID,Payment_Type_ID,
                 Order_Quantity,Product_Price,Customer_Name,Order_Note,Address_ID)
                VALUES ($uid,$pid,$sid,$customer_id,$actual_pid,
                        $qty,$price,'$cust_name','$fne',$address_id)");
            $conn->query("UPDATE products SET Product_Quantity_Stock=Product_Quantity_Stock-$qty WHERE Product_ID=$pid");
        }

        if ($use_wallet) {
            $charged = $fresh_total + $fees_total;
            $new_bal = $wallet_balance - $charged;
            $conn->query("UPDATE users SET Wallet_Balance=$new_bal WHERE User_ID=$uid");
            $wNote = $conn->real_escape_string('Order payment via Wallet (incl. fees)');
            $conn->query("INSERT INTO wallet_transactions (User_ID,Type,Amount,Balance_After,Note,Status)
                          VALUES ($uid,'purchase',$charged,$new_bal,'$wNote','approved')");
        }

        $conn->query("DELETE FROM cart WHERE User_ID=$uid");
        header('Location: orders.php?success=1'); exit;
    }
}


// ── AJAX: Save new address ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_address'])) {
    header('Content-Type: application/json');
    $fn   = $conn->real_escape_string(trim($_POST['addr_name']     ?? ''));
    $ph   = $conn->real_escape_string(trim($_POST['addr_phone']    ?? ''));
    $addr = $conn->real_escape_string(trim($_POST['addr_address']  ?? ''));
    $city = $conn->real_escape_string(trim($_POST['addr_city']     ?? ''));
    $prov = $conn->real_escape_string(trim($_POST['addr_province'] ?? ''));
    $zip  = $conn->real_escape_string(trim($_POST['addr_zip']      ?? ''));
    $lat  = is_numeric($_POST['addr_lat'] ?? '') ? (float)$_POST['addr_lat'] : null;
    $lng  = is_numeric($_POST['addr_lng'] ?? '') ? (float)$_POST['addr_lng'] : null;
    $def  = !empty($_POST['addr_default']) ? 1 : 0;
    $lat_val = $lat !== null ? $lat : 'NULL';
    $lng_val = $lng !== null ? $lng : 'NULL';

    if ($def) $conn->query("UPDATE shipping_addresses SET Is_Default=0 WHERE User_ID=$uid");

    $conn->query("INSERT INTO shipping_addresses
        (User_ID,Full_Name,Phone,Address,City,Province,Zip_Code,Latitude,Longitude,Is_Default)
        VALUES ($uid,'$fn','$ph','$addr','$city','$prov','$zip',$lat_val,$lng_val,$def)");
    $new_id = $conn->insert_id;
    $row = $conn->query("SELECT * FROM shipping_addresses WHERE Address_ID=$new_id")->fetch_assoc();
    echo json_encode(['success' => true, 'address' => $row]);
    exit;
}

// ── AJAX: Delete address ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_address'])) {
    header('Content-Type: application/json');
    $aid   = (int)$_POST['address_id'];
    $check = $conn->query("SELECT Is_Default FROM shipping_addresses WHERE Address_ID=$aid AND User_ID=$uid")->fetch_assoc();
    if (!$check) {
        echo json_encode(['success' => false, 'message' => 'Address not found.']); exit;
    }
    if ($check['Is_Default']) {
        // Count how many addresses the user has
        $total = $conn->query("SELECT COUNT(*) AS c FROM shipping_addresses WHERE User_ID=$uid")->fetch_assoc()['c'];
        if ($total <= 1) {
            echo json_encode(['success' => false, 'message' => 'You cannot delete your only address.']); exit;
        }
        echo json_encode(['success' => false, 'message' => 'Set another address as default before deleting this one.']); exit;
    }
    $conn->query("DELETE FROM shipping_addresses WHERE Address_ID=$aid AND User_ID=$uid");
    echo json_encode(['success' => true]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your Order — Aqualuxe</title>
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
  --track:#00c896;--track-dark:#009e77;
}
body{background:#eef7ff;color:var(--text);font-family:var(--sans);min-height:100vh}

/* ── Topbar ── */
.topbar{background:var(--navy);padding:0 40px;height:64px;display:flex;align-items:center;
  justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 20px rgba(0,26,77,0.4)}
.logo{font-family:var(--serif);font-size:20px;color:#e6f4ff}.logo span{color:var(--water-light)}
.topbar-right{display:flex;align-items:center;gap:12px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:30px;
  font-family:var(--sans);font-size:13px;font-weight:600;cursor:pointer;border:none;
  text-decoration:none;transition:all 0.2s}
.btn-outline{color:#cce8ff;background:transparent;border:1.5px solid rgba(0,177,255,0.3)}
.btn-outline:hover{background:rgba(0,112,255,0.15)}
.btn-outline1{color:var(--water);background:transparent;border:1.5px solid rgba(0,177,255,0.3);margin-bottom: 20px;color:var(--text-muted)}
.btn-outline1:hover{background:rgba(0,112,255,0.15)}
.logout-link{font-size:12px;color:rgba(168,212,245,0.4);text-decoration:none;font-family:var(--mono)}
.logout-link:hover{color:var(--water-light)}

/* ── Layout ── */
.content{max-width:900px;margin:0 auto;padding:40px 24px}
.page-title{font-family:var(--serif);font-size:32px;color:var(--deep2);margin-bottom:6px}
.page-title span{color:var(--water)}
.page-sub{font-size:13px;color:var(--text-muted);margin-bottom:32px;font-family:var(--mono)}
.back-link{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text-muted);
  text-decoration:none;margin-bottom:24px;font-family:var(--mono)}
.back-link:hover{color:var(--water)}
.cart-layout{display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start}
@media(max-width:720px){.cart-layout{grid-template-columns:1fr}}

/* ── Cards ── */
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--sky)}
.card-header h3{font-family:var(--serif);font-size:17px;color:var(--deep2)}

/* ── Cart items ── */
.cart-item{display:flex;align-items:center;gap:14px;padding:16px 22px;border-bottom:1px solid var(--border);transition:background 0.15s}
.cart-item:last-child{border-bottom:none}
.cart-item:hover{background:var(--sky)}
.item-img{width:56px;height:56px;border-radius:10px;object-fit:cover;border:1px solid var(--border);flex-shrink:0}
.item-img-placeholder{width:56px;height:56px;border-radius:10px;background:var(--sky2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.item-info{flex:1;min-width:0}
.item-name{font-family:var(--serif);font-size:15px;font-weight:600;color:var(--deep2);margin-bottom:2px}
.item-size{display:inline-block;background:var(--sky2);color:var(--text-soft);border:1px solid var(--border-dark);padding:2px 8px;border-radius:10px;font-size:10px;font-family:var(--mono);margin-bottom:3px}
.item-price{font-size:12px;font-family:var(--mono);color:var(--text-muted)}
.item-controls{display:flex;align-items:center;gap:8px}
.qty-btn{width:28px;height:28px;border-radius:50%;border:1.5px solid var(--border-dark);background:var(--sky);color:var(--water);font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.15s}
.qty-btn:hover{background:var(--water);color:#fff;border-color:var(--water)}
.qty-display{font-family:var(--mono);font-size:14px;font-weight:600;color:var(--deep2);min-width:24px;text-align:center}
.item-subtotal{font-family:var(--serif);font-size:16px;font-weight:700;color:var(--water);min-width:80px;text-align:right}
.remove-btn{background:none;border:none;color:var(--text-muted);font-size:16px;cursor:pointer;padding:4px;transition:color 0.15s}
.remove-btn:hover{color:#c04a00}

/* ── Auto-buy ── */
.autobuy-section{background:var(--sky);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px}
.autobuy-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:0}
.autobuy-label{font-size:13px;font-weight:600;color:var(--deep2);display:flex;align-items:center;gap:8px}
.autobuy-sub{font-size:11px;font-family:var(--mono);color:var(--text-muted);margin-top:3px}
.toggle-wrap{position:relative;width:44px;height:24px;flex-shrink:0}
.toggle-wrap input{opacity:0;width:100%;height:100%;position:absolute;cursor:pointer;z-index:1;margin:0}
.toggle-track{position:absolute;inset:0;background:var(--border-dark);border-radius:12px;transition:background 0.2s}
.toggle-wrap input:checked ~ .toggle-track{background:var(--water)}
.toggle-thumb{position:absolute;top:3px;left:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:transform 0.2s;pointer-events:none}
.toggle-wrap input:checked ~ .toggle-thumb{transform:translateX(20px)}
.autobuy-settings{margin-top:12px;padding-top:12px;border-top:1px dashed var(--border-dark);display:none}
.autobuy-settings.visible{display:block}
.autobuy-active-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(0,112,255,0.1);color:var(--water);border:1px solid rgba(0,112,255,0.25);padding:4px 12px;border-radius:20px;font-size:11px;font-family:var(--mono);font-weight:600;margin-top:8px}
.cancel-autobuy{font-size:11px;color:#c04a00;font-family:var(--mono);cursor:pointer;text-decoration:underline;background:none;border:none;padding:0;margin-left:8px}

/* ── Summary card ── */
.summary-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;position:sticky;top:84px}
.summary-header{background:var(--navy);padding:18px 22px}
.summary-header h3{font-family:var(--serif);font-size:18px;color:#e6f4ff}
.summary-header p{font-size:12px;color:rgba(168,212,245,0.5);font-family:var(--mono);margin-top:2px}
.summary-body{padding:22px}
.summary-row{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;font-size:13px;gap:8px}
.summary-label{color:var(--text-muted);flex:1}
.summary-value{font-family:var(--mono);color:var(--deep2);font-weight:600;white-space:nowrap}
.summary-total{display:flex;justify-content:space-between;align-items:center;padding-top:14px;border-top:2px dashed var(--border-dark);margin-top:4px}
.summary-total-label{font-family:var(--serif);font-size:16px;color:var(--deep2)}
.summary-total-value{font-family:var(--serif);font-size:24px;font-weight:700;color:var(--water)}

/* ── Form elements ── */
.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
label{font-size:11px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:1px}
input,select,textarea{background:var(--sky);border:1.5px solid var(--border);border-radius:10px;color:var(--text);font-family:var(--sans);font-size:13px;padding:10px 14px;outline:none;width:100%;transition:border-color 0.2s}
input:focus,select:focus,textarea:focus{border-color:var(--water-bright)}
select option{background:#e6f4ff}
textarea{resize:vertical;min-height:70px}
.name-display{background:var(--sky2);border:1.5px solid var(--border-dark);border-radius:10px;padding:10px 14px;font-size:13px;color:var(--deep2);font-weight:600}

/* ── Wallet breakdown ── */
.wallet-breakdown{display:none;background:linear-gradient(135deg,var(--navy) 0%,var(--deep2) 100%);border-radius:10px;padding:16px;margin-top:10px}
.wallet-breakdown.visible{display:block}
.wb-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px}
.wb-label{color:rgba(168,212,245,0.65);font-family:var(--mono)}
.wb-val{color:#e6f4ff;font-family:var(--mono);font-weight:600}
.wb-divider{border:none;border-top:1px solid rgba(168,212,245,0.15);margin:10px 0}
.wb-remaining{display:flex;justify-content:space-between;font-size:15px}
.wb-remaining-label{color:rgba(168,212,245,0.75);font-family:var(--serif)}
.wb-remaining-val{color:var(--water-light);font-family:var(--serif);font-weight:700;font-size:18px}
.wb-insufficient{color:#e07040;font-size:12px;font-family:var(--mono);margin-top:8px}

/* ── Checkout button ── */
.checkout-btn{width:100%;padding:14px;background:var(--water);color:#fff;border:none;border-radius:30px;font-family:var(--sans);font-size:14px;font-weight:700;cursor:pointer;transition:all 0.2s;margin-top:16px}
.checkout-btn:hover{background:var(--deep2);transform:translateY(-1px);box-shadow:0 8px 24px rgba(0,112,255,0.35)}
.checkout-btn:disabled{background:var(--sky2);color:var(--text-muted);cursor:not-allowed;transform:none;box-shadow:none}
.divider{border:none;border-top:1px dashed var(--border);margin:14px 0}

/* ── Alerts ── */
.alert-error{background:rgba(192,74,0,0.1);border:1px solid rgba(192,74,0,0.25);color:#c04a00;padding:12px 16px;border-radius:10px;font-size:13px;font-family:var(--mono);margin-bottom:16px}

/* ── Empty ── */
.empty{text-align:center;padding:80px 20px}
.empty .icon{font-size:64px;margin-bottom:16px;opacity:0.4;display:block}
.empty h3{font-family:var(--serif);font-size:22px;color:var(--deep2);margin-bottom:8px}
.empty p{font-size:14px;color:var(--text-muted);margin-bottom:20px}
.empty a{display:inline-block;padding:11px 28px;background:var(--water);color:#fff;border-radius:30px;text-decoration:none;font-weight:600;font-size:13px}

/* ── Address selector button (in checkout form) ── */
.addr-select-btn{
  display:flex;align-items:center;justify-content:space-between;
  padding:11px 14px;background:var(--sky);border:1.5px solid var(--border);
  border-radius:10px;cursor:pointer;transition:all 0.2s;width:100%;text-align:left;
  font-family:var(--sans);font-size:13px;
}
.addr-select-btn:hover{border-color:var(--water-bright);background:var(--sky2)}
.addr-select-btn .addr-preview{flex:1;color:var(--text);font-size:13px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.addr-select-btn .addr-preview.placeholder{color:var(--text-muted)}
.addr-select-btn .chevron{flex-shrink:0;font-size:10px;color:var(--text-muted);margin-left:8px}

/* ══════════════════════════════════════════════════════
   ADDRESS MODAL
══════════════════════════════════════════════════════ */
.addr-modal-overlay{
  display:none;
  position:fixed;inset:0;
  align-items: center;
  justify-content:center;
  backdrop-filter:blur(5px);
  
}

.addr-modal-overlay.open{display:flex}

/* Sheet slides up from bottom */
.addr-modal-box{
  background:var(--card);
  border-radius:20px 20px 0 0;
  width:100%;max-width:540px;
  max-height:70vh;
  display:flex;flex-direction:column;
  box-shadow:0 -8px 40px rgba(0,26,77,0.25);
  overflow:hidden; /* clip children */
}

/* Drag handle */
.addr-handle{
  width:40px;height:4px;border-radius:4px;
  background:var(--border-dark);margin:10px auto 0;flex-shrink:0;
}

/* Header */
.addr-modal-header{
  margin: 20px;;
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 20px;border-bottom:1px solid var(--border);flex-shrink:0;
}
.addr-modal-title{font-family:var(--serif);font-size:18px;color:var(--deep2)}
.addr-modal-close{
  background:none;border:none;color:var(--text-muted);
  font-size:20px;cursor:pointer;line-height:1;
  width:32px;height:32px;display:flex;align-items:center;justify-content:center;
  border-radius:50%;transition:all 0.15s;
}
.addr-modal-close:hover{background:rgba(0,112,255,0.1);color:var(--water)}

/* Scrollable body */
.addr-modal-body{
  overflow-y:auto;flex:1;padding:16px 20px;
  -webkit-overflow-scrolling:touch;
}

/* Sticky footer */
.addr-modal-footer{
  padding:14px 20px;border-top:1px solid var(--border);flex-shrink:0;
  background:var(--sky);
}
.btn-confirm-addr{
  width:100%;padding:13px;background:var(--water);color:#fff;border:none;
  border-radius:30px;font-family:var(--sans);font-size:14px;font-weight:700;
  cursor:pointer;transition:all 0.2s;
}
.btn-confirm-addr:hover{background:var(--deep2)}
.btn-confirm-addr:disabled{background:var(--border-dark);cursor:not-allowed}

/* ── Saved address items ── */
.saved-addr-list{display:flex;flex-direction:column;gap:10px;margin-bottom:16px}

.saved-addr-item{
  display:flex;           /* horizontal row */
  flex-direction:row;     /* explicit */
  align-items:flex-start;
  gap:12px;
  padding:14px;
  border-radius:12px;
  border:1.5px solid var(--border);
  cursor:pointer;
  transition:border-color 0.2s,background 0.2s;
  background:var(--card);
  position:relative;
}
.saved-addr-item:hover{border-color:var(--border-dark);background:var(--sky)}
.saved-addr-item.selected{border-color:var(--water);background:rgba(0,112,255,0.04)}

/* Radio circle — FIXED SIZE, no flex shrink */
.addr-radio{
  width:20px;
  height:20px;
  min-width:20px;   /* prevent squishing */
  min-height:20px;
  border-radius:50%;
  border:2px solid var(--border-dark);
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
  margin-top:2px;
  transition:border-color 0.2s,background 0.2s;
}
.saved-addr-item.selected .addr-radio{
  border-color:var(--water);background:var(--water);
}
.addr-radio-dot{
  width:8px;height:8px;border-radius:50%;background:#fff;
  display:none;
}
.saved-addr-item.selected .addr-radio-dot{display:block}

/* Info block — takes remaining space, text wraps normally */
.addr-info{
  flex:1;
  min-width:0;        /* CRITICAL: allow text to wrap */
  display:flex;
  flex-direction:column;
  gap:3px;
}
.addr-info-name{
  font-size:14px;font-weight:700;color:var(--deep2);
  display:flex;align-items:center;gap:6px;flex-wrap:wrap;
}
.addr-default-badge{
  display:inline-block;
  background:rgba(0,112,255,0.1);color:var(--water);
  border:1px solid rgba(0,112,255,0.25);
  padding:1px 8px;border-radius:20px;
  font-size:10px;font-family:var(--mono);font-weight:600;
}
.addr-info-phone{font-size:12px;font-family:var(--mono);color:var(--text-muted)}
/* THE FIX: normal white-space so text wraps, not overflows */
.addr-info-text{
  font-size:12px;color:var(--text-soft);
  line-height:1.5;
  white-space:normal;   /* allow wrapping */
  word-break:break-word;
}
.addr-info-coords{font-size:10px;font-family:var(--mono);color:var(--track);margin-top:1px}

/* Delete button — top-right corner of card */
.addr-delete-btn{
  position:absolute;top:10px;right:10px;
  background:none;border:none;
  color:var(--border-dark);font-size:14px;cursor:pointer;
  width:24px;height:24px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  transition:all 0.15s;flex-shrink:0;
}
.addr-delete-btn:hover{background:rgba(192,74,0,0.1);color:#c04a00}

/* ── Accordion: Add new address ── */
.accordion-trigger{
  display:flex;align-items:center;justify-content:space-between;
  padding:12px 14px;
  background:var(--sky);border:1.5px solid var(--border);
  border-radius:10px;cursor:pointer;
  font-size:13px;font-weight:600;color:var(--water);
  transition:all 0.2s;margin-bottom:0;
  user-select:none;
}
.accordion-trigger:hover{background:var(--sky2);border-color:var(--border-dark)}
.accordion-trigger.open{
  border-bottom-left-radius:0;border-bottom-right-radius:0;
  border-color:var(--water);background:rgba(0,112,255,0.05);
}
.accordion-chevron{
  font-size:11px;color:var(--text-muted);
  transition:transform 0.25s;
}
.accordion-trigger.open .accordion-chevron{transform:rotate(180deg)}

/* Accordion body */
.accordion-body{
  display:none;
  border:1.5px solid var(--water);border-top:none;
  border-bottom-left-radius:10px;border-bottom-right-radius:10px;
  padding:16px;background:var(--sky);
  margin-bottom:4px;
}
.accordion-body.open{display:block}

/* GPS row */
.gps-row{
  display:flex;align-items:center;gap:10px;
  padding:10px 12px;background:var(--card);
  border:1px solid var(--border);border-radius:8px;margin-bottom:14px;
}
.btn-gps{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;border-radius:20px;font-size:12px;font-weight:700;
  font-family:var(--mono);cursor:pointer;flex-shrink:0;
  border:1.5px solid var(--track);color:var(--track-dark);
  background:rgba(0,200,150,0.08);transition:all 0.18s;
}
.btn-gps:hover{background:rgba(0,200,150,0.18)}
.btn-gps.success{background:rgba(0,200,150,0.15);color:var(--track-dark)}
.gps-coords-text{font-size:11px;font-family:var(--mono);color:var(--text-muted);flex:1;min-width:0}

/* Map preview */
.addr-map-preview{
  width:100%;height:130px;border-radius:8px;
  border:1px solid var(--border);margin-bottom:14px;display:none;
}
.addr-map-preview.show{display:block}

/* New address form grid */
.new-addr-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.new-addr-grid .full{grid-column:1/-1}
.new-addr-group{display:flex;flex-direction:column;gap:5px}
.new-addr-label{font-size:10px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:1px}
.new-addr-input{
  background:var(--card);border:1.5px solid var(--border);
  border-radius:8px;color:var(--text);font-family:var(--sans);
  font-size:13px;padding:9px 12px;outline:none;width:100%;
  transition:border-color 0.2s;
}
.new-addr-input:focus{border-color:var(--water-bright)}
.default-check-label{
  display:flex;align-items:center;gap:8px;
  font-size:12px;color:var(--text-soft);cursor:pointer;
  margin-top:12px;
}
.default-check-label input[type=checkbox]{width:auto;cursor:pointer}
.btn-save-addr{
  width:100%;padding:12px;
  background:var(--water);color:#fff;border:none;
  border-radius:30px;font-family:var(--sans);font-size:13px;
  font-weight:700;cursor:pointer;margin-top:14px;transition:all 0.2s;
}
.btn-save-addr:hover{background:var(--deep2)}

/* ── General modals (wallet, confirm, autobuy) ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,26,77,0.55);z-index:400;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:32px;max-width:400px;width:90%;box-shadow:0 24px 60px rgba(0,26,77,0.25)}
.modal-icon{font-size:40px;margin-bottom:12px;display:block;text-align:center}
.modal h3{font-family:var(--serif);font-size:20px;color:var(--deep2);margin-bottom:8px;text-align:center}
.modal p{font-size:13px;color:var(--text-muted);margin-bottom:20px;text-align:center;line-height:1.6}
.modal-actions{display:flex;gap:10px;justify-content:center}
.modal-confirm{padding:10px 24px;background:var(--water);color:#fff;border:none;border-radius:30px;font-family:var(--sans);font-size:13px;font-weight:700;cursor:pointer}
.modal-confirm:hover{background:var(--deep2)}
.modal-cancel{padding:10px 24px;background:var(--sky);color:var(--text-soft);border:1.5px solid var(--border);border-radius:30px;font-family:var(--sans);font-size:13px;font-weight:600;cursor:pointer}

/* ── Toast ── */
.toast-bar{position:fixed;bottom:28px;right:28px;background:var(--navy);color:#e6f4ff;padding:13px 22px;border-radius:30px;font-weight:700;font-size:13px;opacity:0;transition:opacity 0.3s,transform 0.4s cubic-bezier(.34,1.56,.64,1);transform:translateY(80px);pointer-events:none;z-index:999;box-shadow:0 8px 24px rgba(0,26,77,0.3)}
.toast-bar.show{opacity:1;transform:translateY(0)}
.toast-bar.error{background:#c04a00}
.toast-bar.success{background:var(--track-dark)}
.btn-warm{color:#fff;background:var(--water)}.btn-warm:hover{background:var(--water-mid);transform:translateY(-1px)}
.footer{background:var(--navy);color:rgba(168,212,245,0.4);text-align:center;padding:20px;font-size:12px;font-family:var(--mono);margin-top:60px}


  </style>
</head>
<body>

<div class="topbar">
  <div class="logo">Aqua<span>Luxe</span></div>
  <div class="topbar-right">
    <a href="shop.php" class="btn btn-outline">Home</a>
    <a href="orders.php" class="btn btn-outline">My Orders</a>
    <a href="wallet.php" class="btn btn-outline">Wallet 💳 ₱<?= number_format($conn->query("SELECT Wallet_Balance FROM users WHERE User_ID=$uid")->fetch_assoc()['Wallet_Balance'],2) ?></a>
    <a href="cart.php" class="btn btn-warm">🛒 Cart</a>
    <a href="../auth/logout.php" class="logout-link btn btn-outline">logout</a>
  </div>
</div>

<div class="content">
  <a href="shop.php" class="btn btn-outline1">← Back to home</a>
  <h1 class="page-title">Your <span>Order</span></h1>
  <p class="page-sub"><?= count($items) ?> item<?= count($items)!=1?'s':'' ?> in your cart</p>

  <?php if (isset($shipping_error)): ?>
    <div class="alert-error">📍 Please select a delivery address before placing your order.</div>
  <?php endif; ?>
  <?php if (isset($wallet_error)): ?>
    <div class="alert-error">✕ Insufficient Wallet balance. Balance: ₱<?= number_format($wallet_balance,2) ?> — Total: ₱<?= number_format($order_grand_total,2) ?>. <a href="wallet.php">Top up →</a></div>
  <?php endif; ?>

  <?php if (empty($items)): ?>
    <div class="empty">
      <span class="icon">🧋</span>
      <h3>Your cart is empty</h3>
      <p>Looks like you haven't added anything yet.</p>
      <a href="shop.php">Browse our menu</a>
    </div>
  <?php else: ?>
  <div class="cart-layout">
    <div>
      <!-- Order Items -->
      <div class="card">
        <div class="card-header">
          <h3>Order Items</h3>
          <span style="font-size:11px;font-family:var(--mono);color:var(--text-muted)"><?= count($items) ?> item<?= count($items)!=1?'s':'' ?></span>
        </div>
        <?php foreach ($items as $item): ?>
        <div class="cart-item" id="row-<?= $item['Cart_ID'] ?>">
          <?php if (!empty($item['Product_Image'])): ?>
            <img class="item-img" src="../<?= htmlspecialchars($item['Product_Image']) ?>" alt="">
          <?php else: ?>
            <div class="item-img-placeholder">🧋</div>
          <?php endif; ?>
          <div class="item-info">
            <div class="item-name"><?= htmlspecialchars($item['Product_Name']) ?></div>
            <?php if (!empty($item['Size_Name'])): ?>
              <span class="item-size"><?= htmlspecialchars($item['Size_Label']) ?> — <?= htmlspecialchars($item['Size_Name']) ?></span>
            <?php endif; ?>
            <div class="item-price">₱<?= number_format($item['Unit_Price'],2) ?> each</div>
          </div>
          <div class="item-controls">
            <button class="qty-btn" onclick="changeQty(<?= $item['Cart_ID'] ?>, -1, <?= $item['Unit_Price'] ?>)">−</button>
            <span class="qty-display" id="qty-<?= $item['Cart_ID'] ?>"><?= $item['Quantity'] ?></span>
            <button class="qty-btn" onclick="changeQty(<?= $item['Cart_ID'] ?>, 1, <?= $item['Unit_Price'] ?>)" <?= $item['Quantity']>=$item['Product_Quantity_Stock']?'disabled style=opacity:0.4':'' ?>>+</button>
          </div>
          <div class="item-subtotal" id="sub-<?= $item['Cart_ID'] ?>">₱<?= number_format($item['Subtotal'],2) ?></div>
          <button class="remove-btn" onclick="removeItem(<?= $item['Cart_ID'] ?>)">✕</button>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Auto-Buy -->
      <div class="autobuy-section">
        <div class="autobuy-header">
          <div>
            <div class="autobuy-label">🔁 Auto-Buy</div>
            <div class="autobuy-sub">Automatically reorder this cart using Wallet</div>
          </div>
          <label class="toggle-wrap">
            <input type="checkbox" id="autobuyToggle" onchange="handleAutobuyToggle(this.checked)">
            <div class="toggle-track"></div>
            <div class="toggle-thumb"></div>
          </label>
        </div>
        <div class="autobuy-settings" id="autobuySettings">
          <div class="form-group" style="margin-bottom:10px">
            <label>Repeat every</label>
            <select id="autobuyDay">
              <option value="1">Every day</option><option value="2">Every 2 days</option>
              <option value="3">Every 3 days</option><option value="7" selected>Every week</option>
              <option value="14">Every 2 weeks</option><option value="30">Every month</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:10px">
            <label>Starting from</label>
            <input type="date" id="autobuyDate" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
          </div>
          <button type="button" class="btn" style="background:var(--water);color:#fff;width:100%;justify-content:center" onclick="confirmAutobuy()">🔁 Enable Auto-Buy</button>
        </div>
        <div id="autobuyActive" style="display:none">
          <div class="autobuy-active-badge">🔁 Auto-Buy is ON <button class="cancel-autobuy" onclick="cancelAutobuy()">Cancel</button></div>
          <div style="font-size:11px;font-family:var(--mono);color:var(--text-muted);margin-top:6px" id="autobuyInfo"></div>
        </div>
      </div>
    </div>

    <!-- Summary + checkout -->
    <div>
      <div class="summary-card">
        <div class="summary-header">
          <h3>Order Summary</h3>
          <p>Review before placing</p>
        </div>
        <div class="summary-body">
          <?php foreach ($items as $item): ?>
          <div class="summary-row" id="srow-<?= $item['Cart_ID'] ?>">
            <span class="summary-label"><?= htmlspecialchars($item['Product_Name']) ?><?php if($item['Size_Label']): ?><br><span style="font-size:10px;opacity:0.7"><?= htmlspecialchars($item['Size_Label']) ?></span><?php endif; ?> ×<span class="qty-lbl-<?= $item['Cart_ID'] ?>"><?= $item['Quantity'] ?></span></span>
            <span class="summary-value" id="sval-<?= $item['Cart_ID'] ?>">₱<?= number_format($item['Subtotal'],2) ?></span>
          </div>
          <?php endforeach; ?>
          <div class="divider"></div>
          <div class="summary-total">
            <span class="summary-total-label">Subtotal</span>
            <span class="summary-value" id="subtotalDisplay">₱<?= number_format($grand_total,2) ?></span>
          </div>

          <div class="summary-row">
            <span class="summary-label">🛵 Delivery Fee</span>
            <span class="summary-value">₱10.00</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">⚙️ Service Fee</span>
            <span class="summary-value">₱10.00</span>
          </div>
          <div class="summary-total">
            <span class="summary-total-label">Total</span>
            <span class="summary-total-value" id="grandTotal">₱<?= number_format($order_grand_total,2) ?></span>
          </div>

          <form method="POST" style="margin-top:20px" id="checkoutForm">
            <input type="hidden" name="checkout" value="1">
            <input type="hidden" name="Customer_Name" value="<?= htmlspecialchars($customer_name) ?>">
            <input type="hidden" name="Address_ID" id="selectedAddressId" value="<?= $default_address ? $default_address['Address_ID'] : '' ?>">

            <div class="form-group">
              <label>Your Name</label>
              <div class="name-display">👤 <?= htmlspecialchars($customer_name) ?></div>
            </div>

            <!-- Delivery Address selector -->
            <div class="form-group">
              <label>Delivery Address <span style="color:#c04a00;font-size:13px;text-transform:none;letter-spacing:0">*</span></label>
              <button type="button" class="addr-select-btn" onclick="openAddrModal()">
                <span class="addr-preview <?= $default_address ? '' : 'placeholder' ?>" id="addrPreviewText">
                  <?php if ($default_address): ?>
                    <?= htmlspecialchars($default_address['Full_Name']) ?> — <?= htmlspecialchars($default_address['Address'].', '.$default_address['City']) ?>
                  <?php else: ?>
                    + Add a delivery address
                  <?php endif; ?>
                </span>
                <span class="chevron">▼</span>
              </button>
            </div>

            <div class="form-group">
              <label>Payment Method</label>
              <select name="Payment_Type_ID" id="paymentSelect" onchange="handlePaymentChange(this.value)" required>
                <option value="">— Select payment —</option>
                <?php $payment_types->data_seek(0); while ($pt=$payment_types->fetch_assoc()): ?>
                  <option value="<?= $pt['Payment_Type_ID'] ?>"><?= htmlspecialchars($pt['Payment_Type_Description']) ?></option>
                <?php endwhile; ?>
                <option value="wallet">💳 Wallet (₱<?= number_format($wallet_balance,2) ?>)</option>
              </select>
              <div class="wallet-breakdown" id="walletBreakdown">
                <div class="wb-row"><span class="wb-label">Subtotal</span><span class="wb-val" id="wbSubtotal">₱<?= number_format($grand_total,2) ?></span></div>
                <div class="wb-row"><span class="wb-label">Delivery Fee</span><span class="wb-val">₱10.00</span></div>
                <div class="wb-row"><span class="wb-label">Service Fee</span><span class="wb-val">₱10.00</span></div>
                <hr class="wb-divider">
                <div class="wb-row"><span class="wb-label">Your balance</span><span class="wb-val">₱<?= number_format($wallet_balance,2) ?></span></div>
                <div class="wb-row"><span class="wb-label">Order total</span><span class="wb-val" id="wbTotal">₱<?= number_format($grand_total,2) ?></span></div>
                <hr class="wb-divider">
                <div class="wb-remaining">
                  <span class="wb-remaining-label">Remaining</span>
                  <span class="wb-remaining-val" id="wbRemaining">₱<?= number_format($wallet_balance - $grand_total,2) ?></span>
                </div>
                <?php if ($wallet_balance < $grand_total): ?>
                  <div class="wb-insufficient">✕ Need ₱<?= number_format($grand_total - $wallet_balance,2) ?> more. <a href="wallet.php">Top up →</a></div>
                <?php endif; ?>
              </div>
            </div>

            <div class="form-group">
              <label>Note / Special Request <span style="font-size:10px;text-transform:none;letter-spacing:0;color:var(--text-muted)">(optional)</span></label>
              <textarea name="Order_Note" rows="3" placeholder="e.g. Leave at gate, call before delivery..."></textarea>
            </div>

            <button type="button" class="checkout-btn" id="checkoutBtn"
              <?= (!$default_address) ? 'disabled' : '' ?> onclick="handleCheckout()">
              <?= $default_address ? 'Place Order — ₱'.number_format($grand_total,2) : 'Add a delivery address first' ?>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="footer">🧋 Aqua<span>Luxe</span></div>

<!-- ══════════════════════════════════════════════════════
     DELIVERY ADDRESS MODAL
══════════════════════════════════════════════════════ -->
<div class="addr-modal-overlay" id="addrModal">
  <div class="addr-modal-box">
    <div class="addr-handle"></div>

    <div class="addr-modal-header">
      <span class="addr-modal-title">📍 Delivery Address</span>
      <button class="addr-modal-close" onclick="closeAddrModal()">✕</button>
    </div>

    <div class="addr-modal-body">

      <!-- Saved addresses list -->
      <div class="saved-addr-list" id="savedAddrList">
        <?php foreach ($addresses as $addr):
          $isDefault = $addr['Is_Default'];
          $addrLine  = htmlspecialchars($addr['Address'].', '.$addr['City'].', '.$addr['Province'].' '.$addr['Zip_Code']);
        ?>
        <div class="saved-addr-item <?= ($default_address && $addr['Address_ID']==$default_address['Address_ID']) ? 'selected' : '' ?>"
             id="addrItem-<?= $addr['Address_ID'] ?>"
             onclick="selectAddress(<?= $addr['Address_ID'] ?>, '<?= htmlspecialchars(addslashes($addr['Full_Name'])) ?>', '<?= addslashes($addrLine) ?>')">

          <div class="addr-radio">
            <div class="addr-radio-dot"></div>
          </div>

          <div class="addr-info">
            <div class="addr-info-name">
              <?= htmlspecialchars($addr['Full_Name']) ?>
              <?php if ($isDefault): ?>
                <span class="addr-default-badge">Default</span>
              <?php endif; ?>
            </div>
            <div class="addr-info-phone">📞 <?= htmlspecialchars($addr['Phone']) ?></div>
            <div class="addr-info-text"><?= $addrLine ?></div>
            <?php if ($addr['Latitude'] && $addr['Longitude']): ?>
              <div class="addr-info-coords">📡 <?= number_format($addr['Latitude'],5) ?>, <?= number_format($addr['Longitude'],5) ?></div>
            <?php endif; ?>
          </div>

          <?php if (!$isDefault): ?>
            <!-- Delete button only on non-default addresses -->
            <button class="addr-delete-btn"
                    onclick="event.stopPropagation(); confirmDeleteAddr(<?= $addr['Address_ID'] ?>)"
                    title="Delete address">✕</button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- ── Accordion: Add new address ── -->
      <div class="accordion-trigger" id="accordionTrigger" onclick="toggleAccordion()">
        <span>＋ Add New Address</span>
        <span class="accordion-chevron" id="accordionChevron">▼</span>
      </div>

      <div class="accordion-body" id="accordionBody">
        <!-- GPS row -->
        <div class="gps-row">
          <button type="button" class="btn-gps" id="btnGps" onclick="detectLocation()">
            📡 Detect Location
          </button>
          <span class="gps-coords-text" id="gpsCoordsText">Not captured yet</span>
        </div>
        <iframe id="addrMapPreview" class="addr-map-preview" frameborder="0"></iframe>

        <div class="new-addr-grid">
          <div class="new-addr-group">
            <label class="new-addr-label">Full Name</label>
            <input type="text" class="new-addr-input" id="aName" placeholder="Juan Dela Cruz">
          </div>
          <div class="new-addr-group">
            <label class="new-addr-label">Phone</label>
            <input type="text" class="new-addr-input" id="aPhone" placeholder="09XX XXX XXXX">
          </div>
          <div class="new-addr-group full">
            <label class="new-addr-label">Street / House No. / Barangay</label>
            <input type="text" class="new-addr-input" id="aAddress" placeholder="123 Rizal St., Brgy. San Jose">
          </div>
          <div class="new-addr-group">
            <label class="new-addr-label">City / Municipality</label>
            <input type="text" class="new-addr-input" id="aCity" placeholder="Angono">
          </div>
          <div class="new-addr-group">
            <label class="new-addr-label">Province</label>
            <input type="text" class="new-addr-input" id="aProvince" placeholder="Rizal">
          </div>
          <div class="new-addr-group">
            <label class="new-addr-label">ZIP Code</label>
            <input type="text" class="new-addr-input" id="aZip" placeholder="1930" maxlength="10">
          </div>
        </div>

        <label class="default-check-label">
          <input type="checkbox" id="aDefault"> Set as default address
        </label>

        <button type="button" class="btn-save-addr" onclick="saveNewAddress()">
          Save New Address
        </button>
      </div>
    </div><!-- /body -->

    <div class="addr-modal-footer">
      <button class="btn-confirm-addr" id="btnConfirmAddr"
              onclick="confirmAddress()"
              <?= empty($addresses) ? 'disabled' : '' ?>>
        Use This Address
      </button>
    </div>
  </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal-overlay" id="deleteAddrModal">
  <div class="modal">
    <span class="modal-icon">🗑️</span>
    <h3>Delete Address?</h3>
    <p id="deleteAddrMsg">This address will be permanently removed.</p>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="document.getElementById('deleteAddrModal').classList.remove('open')">Cancel</button>
      <button class="modal-confirm" style="background:#c04a00" id="btnConfirmDelete">Yes, delete</button>
    </div>
  </div>
</div>

<!-- Wallet confirm modal -->
<div class="modal-overlay" id="walletModal">
  <div class="modal">
    <span class="modal-icon">💳</span>
    <h3>Confirm with Wallet</h3>
    <p id="walletModalBody"></p>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="document.getElementById('walletModal').classList.remove('open')">Cancel</button>
      <button class="modal-confirm" onclick="submitOrder()">Yes, place order</button>
    </div>
  </div>
</div>

<!-- Auto-buy modals -->
<div class="modal-overlay" id="autobuyModal">
  <div class="modal">
    <span class="modal-icon">🔁</span><h3>Enable Auto-Buy?</h3>
    <p id="autobuyModalBody"></p>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="document.getElementById('autobuyModal').classList.remove('open')">Cancel</button>
      <button class="modal-confirm" onclick="activateAutobuy()">Yes, enable</button>
    </div>
  </div>
</div>
<div class="modal-overlay" id="autobuyOffModal">
  <div class="modal">
    <span class="modal-icon">⏹️</span><h3>Cancel Auto-Buy?</h3>
    <p>Your cart will no longer be ordered automatically.</p>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="document.getElementById('autobuyOffModal').classList.remove('open')">Keep it on</button>
      <button class="modal-confirm" style="background:#c04a00" onclick="deactivateAutobuy()">Yes, cancel</button>
    </div>
  </div>
</div>

<div class="toast-bar" id="toastEl"></div>

<script>
/* ═══════════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════════ */
const walletBalance = <?= $wallet_balance ?>;
const DELIVERY_FEE = 10;
const SERVICE_FEE  = 10;
const FEES_TOTAL   = DELIVERY_FEE + SERVICE_FEE;
let itemsTotal = <?= $grand_total ?>;
let grandTotal = itemsTotal + FEES_TOTAL;
let useWallet       = false;
let selectedAddrId  = <?= $default_address ? $default_address['Address_ID'] : 'null' ?>;
let addrLat = null, addrLng = null;
let pendingDeleteId = null;

/* ═══════════════════════════════════════════════════════════
   ADDRESS MODAL
═══════════════════════════════════════════════════════════ */
function openAddrModal()  { document.getElementById('addrModal').classList.add('open'); }
function closeAddrModal() { document.getElementById('addrModal').classList.remove('open'); }

document.getElementById('addrModal').addEventListener('click', function(e) {
  if (e.target === this) closeAddrModal();
});

/* Select a saved address */
function selectAddress(id, name, text) {
  selectedAddrId = id;
  document.querySelectorAll('.saved-addr-item').forEach(el => el.classList.remove('selected'));
  document.getElementById('addrItem-' + id)?.classList.add('selected');
  document.getElementById('btnConfirmAddr').disabled = false;
}

/* Confirm selection — updates the button preview in the checkout form */
function confirmAddress() {
  if (!selectedAddrId) return;

  const el = document.getElementById('addrItem-' + selectedAddrId);
  const nameEl = el?.querySelector('.addr-info-name');
  const textEl = el?.querySelector('.addr-info-text');

  const name = nameEl ? nameEl.childNodes[0].textContent.trim() : '';
  const text = textEl ? textEl.textContent.trim() : '';

  document.getElementById('selectedAddressId').value = selectedAddrId;
  const preview = document.getElementById('addrPreviewText');
  preview.textContent = name + (text ? ' — ' + text : '');
  preview.classList.remove('placeholder');

  updateCheckoutBtn();
  closeAddrModal();
}

/* ── Accordion toggle ── */
function toggleAccordion() {
  const body    = document.getElementById('accordionBody');
  const trigger = document.getElementById('accordionTrigger');
  const isOpen  = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  trigger.classList.toggle('open', !isOpen);
}

/* ── GPS detection ── */
function detectLocation() {
  const btn  = document.getElementById('btnGps');
  const text = document.getElementById('gpsCoordsText');
  if (!navigator.geolocation) { text.textContent = '⚠ Not supported'; return; }

  btn.textContent = '⏳ Detecting…';
  btn.disabled = true;

  navigator.geolocation.getCurrentPosition(
    function(pos) {
      addrLat = pos.coords.latitude.toFixed(7);
      addrLng = pos.coords.longitude.toFixed(7);
      text.innerHTML = '<span style="color:var(--track)">✓</span> ' + addrLat + ', ' + addrLng;
      btn.textContent = '✓ Captured';
      btn.classList.add('success');
      btn.disabled = false;

      // Show mini map
      const map = document.getElementById('addrMapPreview');
      const lat = parseFloat(addrLat), lng = parseFloat(addrLng);
      map.src = `https://www.openstreetmap.org/export/embed.html?bbox=${lng-0.004},${lat-0.004},${lng+0.004},${lat+0.004}&layer=mapnik&marker=${lat},${lng}`;
      map.classList.add('show');

      // Reverse geocode to autofill fields
      fetch(`https://nominatim.openstreetmap.org/reverse?lat=${addrLat}&lon=${addrLng}&format=json`)
        .then(r => r.json())
        .then(d => {
          const a = d.address || {};
          const streetParts = [a.house_number, a.road, a.neighbourhood, a.suburb].filter(Boolean);
          if (!document.getElementById('aAddress').value && streetParts.length)
            document.getElementById('aAddress').value = streetParts.join(', ');
          if (!document.getElementById('aCity').value)
            document.getElementById('aCity').value = a.city || a.town || a.municipality || '';
          if (!document.getElementById('aProvince').value)
            document.getElementById('aProvince').value = a.province || a.state || '';
          if (!document.getElementById('aZip').value)
            document.getElementById('aZip').value = a.postcode || '';
        }).catch(() => {});
    },
    function(err) {
      text.textContent = '⚠ ' + err.message;
      btn.textContent  = '📡 Detect Location';
      btn.disabled = false;
    },
    { enableHighAccuracy: true, timeout: 12000 }
  );
}

/* ── Save new address ── */
function saveNewAddress() {
  const name  = document.getElementById('aName').value.trim();
  const phone = document.getElementById('aPhone').value.trim();
  const addr  = document.getElementById('aAddress').value.trim();
  const city  = document.getElementById('aCity').value.trim();
  const prov  = document.getElementById('aProvince').value.trim();
  const zip   = document.getElementById('aZip').value.trim();
  const def   = document.getElementById('aDefault').checked ? '1' : '';

  if (!name || !phone || !addr || !city || !prov || !zip) {
    showToast('⚠ Please fill in all fields.', 'error'); return;
  }

  const body = new URLSearchParams({
    save_address:'1', addr_name:name, addr_phone:phone,
    addr_address:addr, addr_city:city, addr_province:prov, addr_zip:zip,
    addr_lat: addrLat || '', addr_lng: addrLng || '', addr_default: def
  });

  fetch('cart.php', { method:'POST', body })
    .then(r => r.json())
    .then(data => {
      if (!data.success) { showToast('✕ Could not save address.','error'); return; }
      const a = data.address;
      const addrLine = `${a.Address}, ${a.City}, ${a.Province} ${a.Zip_Code}`;

      // If this is now the default, remove default badge from others
      if (def) {
        document.querySelectorAll('.addr-default-badge').forEach(b => b.remove());
      }

      // Build and insert new item
      const div = document.createElement('div');
      div.className = 'saved-addr-item';
      div.id = 'addrItem-' + a.Address_ID;
      div.onclick = () => selectAddress(a.Address_ID, a.Full_Name, addrLine);
      div.innerHTML = `
        <div class="addr-radio"><div class="addr-radio-dot"></div></div>
        <div class="addr-info">
          <div class="addr-info-name">
            ${escHtml(a.Full_Name)}
            ${def ? '<span class="addr-default-badge">Default</span>' : ''}
          </div>
          <div class="addr-info-phone">📞 ${escHtml(a.Phone)}</div>
          <div class="addr-info-text">${escHtml(addrLine)}</div>
          ${addrLat ? `<div class="addr-info-coords">📡 ${addrLat}, ${addrLng}</div>` : ''}
        </div>
        <button class="addr-delete-btn"
                onclick="event.stopPropagation();confirmDeleteAddr(${a.Address_ID})"
                title="Delete">✕</button>
      `;

      // Insert before accordion
      const list = document.getElementById('savedAddrList');
      list.appendChild(div);

      // Auto-select the new address
      selectAddress(a.Address_ID, a.Full_Name, addrLine);

      // Reset form
      ['aName','aPhone','aAddress','aCity','aProvince','aZip'].forEach(id => document.getElementById(id).value = '');
      document.getElementById('aDefault').checked = false;
      document.getElementById('btnGps').textContent = '📡 Detect Location';
      document.getElementById('btnGps').classList.remove('success');
      document.getElementById('gpsCoordsText').textContent = 'Not captured yet';
      document.getElementById('addrMapPreview').classList.remove('show');
      addrLat = null; addrLng = null;

      // Close accordion
      document.getElementById('accordionBody').classList.remove('open');
      document.getElementById('accordionTrigger').classList.remove('open');

      showToast('📍 Address saved!', 'success');
    })
    .catch(() => showToast('✕ Server error.', 'error'));
}

/* ── Delete address ── */
function confirmDeleteAddr(id) {
  pendingDeleteId = id;
  document.getElementById('deleteAddrMsg').textContent =
    'This address will be permanently removed. You cannot delete your default address.';
  document.getElementById('deleteAddrModal').classList.add('open');
  document.getElementById('btnConfirmDelete').onclick = function() {
    document.getElementById('deleteAddrModal').classList.remove('open');
    doDeleteAddr(id);
  };
}

function doDeleteAddr(id) {
  fetch('cart.php', {
    method: 'POST',
    body: new URLSearchParams({ delete_address:'1', address_id: id })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      const el = document.getElementById('addrItem-' + id);
      if (el) {
        // If this was selected, clear selection
        if (el.classList.contains('selected')) {
          selectedAddrId = null;
          document.getElementById('selectedAddressId').value = '';
          document.getElementById('addrPreviewText').textContent = '+ Add a delivery address';
          document.getElementById('addrPreviewText').classList.add('placeholder');
          updateCheckoutBtn();
        }
        el.remove();
      }
      showToast('Address deleted.', 'success');
    } else {
      showToast('⚠ ' + data.message, 'error');
    }
  })
  .catch(() => showToast('✕ Could not delete.', 'error'));
}

/* ═══════════════════════════════════════════════════════════
   CHECKOUT
═══════════════════════════════════════════════════════════ */
function updateCheckoutBtn() {
  const btn    = document.getElementById('checkoutBtn');
  const payVal = document.getElementById('paymentSelect').value;
  const hasAddr = !!document.getElementById('selectedAddressId').value;

  if (!hasAddr) {
    btn.disabled = true;
    btn.textContent = 'Add a delivery address first';
    return;
  }
  if (!payVal) {
    btn.disabled = true;
    btn.textContent = 'Select a payment method';
    return;
  }
  if (useWallet) {
    const ok = walletBalance >= grandTotal;
    btn.disabled = !ok;
    btn.textContent = ok ? 'Pay with Wallet — ₱' + fmt(grandTotal) : 'Insufficient Wallet';
    return;
  }
  btn.disabled = false;
  btn.textContent = 'Place Order — ₱' + fmt(grandTotal);
}

function handlePaymentChange(val) {
  const bd = document.getElementById('walletBreakdown');
  useWallet = (val === 'wallet');
  bd.classList.toggle('visible', useWallet);
  if (useWallet) updateWalletBreakdown();
  updateCheckoutBtn();
}

function updateWalletBreakdown() {
  const rem = walletBalance - grandTotal;
  document.getElementById('wbSubtotal').textContent  = '₱' + fmt(itemsTotal);
  document.getElementById('wbTotal').textContent     = '₱' + fmt(grandTotal);
  document.getElementById('wbRemaining').textContent = '₱' + fmt(rem);
  document.getElementById('wbRemaining').style.color = rem >= 0 ? 'var(--water-light)' : '#e07040';
}

function handleCheckout() {
  if (!document.getElementById('selectedAddressId').value) { openAddrModal(); return; }
  if (useWallet) {
    const rem = walletBalance - grandTotal;
    document.getElementById('walletModalBody').innerHTML =
        `<strong>Subtotal:</strong> ₱${fmt(itemsTotal)}<br>
        <strong>Delivery Fee:</strong> ₱10.00<br>
        <strong>Service Fee:</strong> ₱10.00<br>
        <strong>Order Total:</strong> ₱${fmt(grandTotal)}<br>
        <strong>Remaining after:</strong> ₱${fmt(walletBalance - grandTotal)}<br><br>
        Wallet balance will be deducted immediately.`;
    document.getElementById('walletModal').classList.add('open');
  } else {
    submitOrder();
  }
}

function submitOrder() {
  document.getElementById('walletModal').classList.remove('open');
  document.getElementById('checkoutForm').submit();
}

/* ═══════════════════════════════════════════════════════════
   CART QTY / REMOVE
═══════════════════════════════════════════════════════════ */
let qtys   = {<?php foreach($items as $i) echo $i['Cart_ID'].':'.$i['Quantity'].','; ?>};
let prices = {<?php foreach($items as $i) echo $i['Cart_ID'].':'.floatval($i['Unit_Price']).','; ?>};

function changeQty(cid, delta, price) {
  const newQty = (qtys[cid]||1) + delta;
  if (newQty < 1) { removeItem(cid); return; }
  fetch('cart_action.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=update&cart_id=${cid}&quantity=${newQty}`})
  .then(r=>r.json()).then(()=>{
    qtys[cid] = newQty;
    document.getElementById('qty-'+cid).textContent = newQty;
    document.querySelectorAll('.qty-lbl-'+cid).forEach(el=>el.textContent=newQty);
    const sub = (newQty*prices[cid]).toFixed(2);
    document.getElementById('sub-'+cid).textContent  = '₱'+fmt(sub);
    document.getElementById('sval-'+cid).textContent = '₱'+fmt(sub);
    recalc();
  });
}

function removeItem(cid) {
  fetch('cart_action.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=remove&cart_id=${cid}`})
  .then(r=>r.json()).then(()=>{
    document.getElementById('row-'+cid)?.remove();
    document.getElementById('srow-'+cid)?.remove();
    delete qtys[cid]; delete prices[cid];
    recalc();
    if(Object.keys(prices).length===0) location.reload();
  });
}

function recalc() {
  let total = 0;
  Object.keys(prices).forEach(cid => { total += (qtys[cid]||0)*prices[cid]; });
  itemsTotal = total;
  grandTotal = total + FEES_TOTAL;
  document.getElementById('subtotalDisplay').textContent = '₱' + fmt(total.toFixed(2));
  document.getElementById('grandTotal').textContent = '₱' + fmt(grandTotal.toFixed(2));
  if (useWallet) updateWalletBreakdown();
  updateCheckoutBtn();
}

/* ═══════════════════════════════════════════════════════════
   AUTO-BUY
═══════════════════════════════════════════════════════════ */
function handleAutobuyToggle(checked) {
  document.getElementById('autobuySettings').classList.toggle('visible', checked);
  if (!checked) document.getElementById('autobuySettings').classList.remove('visible');
}
function confirmAutobuy() {
  const day = document.getElementById('autobuyDay');
  const date = document.getElementById('autobuyDate').value;
  if (!date) { showToast('Please select a start date.','error'); return; }
  document.getElementById('autobuyModalBody').innerHTML =
    `Auto-Buy: <strong>${day.options[day.selectedIndex].text}</strong> from <strong>${date}</strong> — ₱${fmt(grandTotal)}/order using Wallet.`;
  document.getElementById('autobuyModal').classList.add('open');
}
function activateAutobuy() {
  const day = document.getElementById('autobuyDay');
  const date = document.getElementById('autobuyDate').value;
  document.getElementById('autobuyModal').classList.remove('open');
  document.getElementById('autobuySettings').classList.remove('visible');
  document.getElementById('autobuyActive').style.display = 'block';
  document.getElementById('autobuyInfo').textContent = `${day.options[day.selectedIndex].text} · starts ${date} · ₱${fmt(grandTotal)}/order`;
  localStorage.setItem('autobuy', JSON.stringify({ day:day.value, dayLabel:day.options[day.selectedIndex].text, date, total:grandTotal }));
  showToast('🔁 Auto-Buy enabled!');
}
function cancelAutobuy()    { document.getElementById('autobuyOffModal').classList.add('open'); }
function deactivateAutobuy() {
  localStorage.removeItem('autobuy');
  document.getElementById('autobuyOffModal').classList.remove('open');
  document.getElementById('autobuyActive').style.display = 'none';
  document.getElementById('autobuyToggle').checked = false;
  document.getElementById('autobuySettings').classList.remove('visible');
  showToast('Auto-Buy cancelled.');
}
window.addEventListener('load', () => {
  const saved = localStorage.getItem('autobuy');
  if (saved) {
    try {
      const ab = JSON.parse(saved);
      if (walletBalance < ab.total) { localStorage.removeItem('autobuy'); return; }
      document.getElementById('autobuyToggle').checked = true;
      document.getElementById('autobuyActive').style.display = 'block';
      document.getElementById('autobuyInfo').textContent = `${ab.dayLabel} · starts ${ab.date} · ₱${fmt(ab.total)}/order`;
    } catch(e) { localStorage.removeItem('autobuy'); }
  }
  // Init checkout button state
  updateCheckoutBtn();
});

/* ═══════════════════════════════════════════════════════════
   UTILITIES
═══════════════════════════════════════════════════════════ */
function showToast(msg, type) {
  const t = document.getElementById('toastEl');
  t.textContent = msg;
  t.className = 'toast-bar' + (type ? ' '+type : '');
  setTimeout(()=>t.classList.add('show'),50);
  setTimeout(()=>t.classList.remove('show'),4000);
}
function fmt(n) {
  return parseFloat(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
}
function escHtml(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Overlay click closes generic modals
['walletModal','autobuyModal','autobuyOffModal','deleteAddrModal'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});
</script>
</body>
</html>