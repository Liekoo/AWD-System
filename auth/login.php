<?php
session_start();
require '../config.php';
require_once '../env.php'; // adjust path

if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php'); exit;
}

$redirect = $_GET['redirect'] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $conn->real_escape_string(trim($_POST['username']));
    $password = $_POST['password'];
    $redirect = $conn->real_escape_string($_POST['redirect'] ?? '');

    $result = $conn->query("SELECT * FROM users WHERE Username = '$username' AND Status = 'active'");
    $user   = $result->fetch_assoc();

    if ($user && password_verify($password, $user['Password'])) {
        $_SESSION['user_id']   = $user['User_ID'];
        $_SESSION['username']  = $user['Username'];
        $_SESSION['full_name'] = $user['Full_Name'];
        $_SESSION['role']      = $user['Role'];

        if ($redirect && $user['Role'] === 'user') {
            header('Location: ../' . $redirect); exit;
        }
        switch ($user['Role']) {
            case 'admin': header('Location: ../admin/dashboard.php'); break;
            case 'staff': header('Location: ../staff/dashboard.php'); break;
            case 'user':  header('Location: ../user/shop.php');       break;
        }
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}

$google_auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => $_ENV['GOOGLE_CLIENT_ID'],
    'redirect_uri'  => $_ENV['GOOGLE_REDIRECT_URI'],
    'response_type' => 'code',
    'scope'         => 'email profile',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — Sip & Savor</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
  <style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --water:#0070ff;--water-mid:#1588ff;--water-bright:#0097ff;--water-light:#00b1ff;
  --sky:#e6f4ff;--sky2:#cceeff;
  --deep:#002d6e;--deep2:#003d8f;--navy:#001a4d;
  --text:#051c3a;--text-muted:#5e8ab4;--border:#b3d4f0;
  --serif:'Playfair Display',serif;--sans:'DM Sans',sans-serif;--mono:'DM Mono',monospace;
}
body{background:var(--sky);font-family:var(--sans);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.wrap{display:flex;width:100%;max-width:900px;border-radius:24px;overflow:hidden;box-shadow:0 24px 60px rgba(0,26,77,0.2)}

.left{
  flex:1;background:linear-gradient(160deg,var(--navy) 0%,var(--deep2) 60%,#005bb5 100%);
  padding:60px 48px;display:flex;flex-direction:column;justify-content:center;
  position:relative;overflow:hidden;
}
.left::before{content:'💧';position:absolute;font-size:200px;opacity:0.06;bottom:-30px;right:-20px;line-height:1}
.left-tag{display:inline-block;background:rgba(0,177,255,0.15);color:var(--water-light);border:1px solid rgba(0,177,255,0.3);padding:5px 14px;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:20px}
.left-title{font-family:var(--serif);font-size:36px;color:#e6f4ff;line-height:1.2;margin-bottom:12px}
.left-title span{color:var(--water-light)}
.left-sub{font-size:13px;color:rgba(168,212,245,0.6);line-height:1.7}
.left-divider{width:40px;height:2px;background:var(--water-light);opacity:0.4;margin:24px 0}
.left-features{display:flex;flex-direction:column;gap:10px}
.left-feature{font-size:13px;color:rgba(168,212,245,0.65);display:flex;align-items:center;gap:8px}
.left-feature::before{content:'✦';color:var(--water-light);font-size:10px}

.right{flex:1;background:#f5faff;padding:60px 48px;display:flex;flex-direction:column;justify-content:center}
.logo{font-family:var(--serif);font-size:20px;color:var(--deep2);margin-bottom:4px}
.logo span{color:var(--water)}
.tagline{font-size:12px;color:var(--text-muted);font-family:var(--mono);margin-bottom:36px}
h2{font-family:var(--serif);font-size:26px;color:var(--deep2);margin-bottom:6px}
.subtitle{font-size:13px;color:var(--text-muted);margin-bottom:28px}
.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}
label{font-size:11px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:1px}
input{background:var(--sky);border:1.5px solid var(--border);border-radius:12px;color:var(--text);font-family:var(--sans);font-size:14px;padding:12px 16px;outline:none;transition:border-color 0.2s;width:100%}
input:focus{border-color:var(--water-bright);background:#fff}
.btn{width:100%;padding:13px;background:var(--water);color:#fff;border:none;border-radius:30px;font-family:var(--sans);font-size:14px;font-weight:600;cursor:pointer;margin-top:8px;transition:all 0.2s}
.btn:hover{background:var(--deep2);box-shadow:0 6px 20px rgba(0,112,255,0.3);transform:translateY(-1px)}
.error{background:rgba(192,74,0,0.08);border:1px solid rgba(192,74,0,0.22);color:#c04a00;padding:12px 16px;border-radius:12px;font-size:13px;font-family:var(--mono);margin-bottom:20px}
.footer-links{display:flex;flex-direction:column;gap:10px;align-items:center;margin-top:20px}
.footer-links a{font-size:13px;color:var(--water);text-decoration:none;font-weight:500}
.footer-links a:hover{color:var(--deep2);text-decoration:underline}
.footer-links .back{font-size:12px;color:var(--text-muted);font-family:var(--mono)}
.footer-links .back:hover{color:var(--water)}
.divider{border:none;border-top:1px dashed var(--border);margin:4px 0}

.google-wrap{margin-top:24px}
.or-divider{display:flex;align-items:center;color:var(--text-muted);font-size:10px;font-family:var(--mono);text-transform:uppercase;letter-spacing:1px;margin-bottom:20px}
.or-divider::before,.or-divider::after{content:"";flex:1;height:1px;background:var(--border)}
.or-divider span{padding:0 12px}
.btn-google{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:12px;background:#f5faff;color:var(--deep2);border:1.5px solid var(--border);border-radius:30px;font-family:var(--sans);font-size:13px;font-weight:600;text-decoration:none;transition:all 0.2s}
.btn-google img{width:18px;height:18px}
.btn-google:hover{background:#fff;border-color:var(--water-bright);box-shadow:0 4px 15px rgba(0,112,255,0.1);transform:translateY(-1px)}
</style>
</head>
<body>
<div class="wrap">
  <div class="left">
    <div class="left-tag">Welcome back 💧</div>
    <h2 class="left-title">Your favorite<br><span>cup awaits</span></h2>
    <p class="left-sub">Sign in and continue your milky tea journey with us.</p>
    <div class="left-divider"></div>
    <div class="left-features">
      <div class="left-feature">Order your favorite drinks</div>
      <div class="left-feature">Track your order history</div>
      <div class="left-feature">Easy checkout & payment</div>
    </div>
  </div>
  <div class="right">
    <div class="logo">Aqua<span>luxe</span></div>
    <div class="tagline">Pure water, pure care.</div>
    <h2>Sign In</h2>
    <p class="subtitle">Good to see you again!</p>

    <?php if ($error): ?>
      <div class="error">✕ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <button type="submit" class="btn">Sign In</button>
    </form>
    <div class="google-wrap">
      <div class="or-divider"><span>or continue with</span></div>
        <a href="<?= htmlspecialchars($google_auth_url) ?>" class="btn-google">
        <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" alt="Google">
        Sign in with Google Account
    </a>
    </div>
    <div class="footer-links">
      <a href="register.php">Don't have an account? Join us</a>
      <hr class="divider" style="width:100%">
      <a href="../user/shop.php" class="back">← Back to menu</a>
    </div>
  </div>
</div>
</body>
</html>