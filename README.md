# 💧 AquaLuxe — Water Refilling Station Ordering System

A full-stack web ordering system for a water refilling station business, built with **PHP + MySQL**. Customers can browse products, manage a cart, pay via multiple methods, and track their orders — all from a clean, mobile-friendly interface.

---

## ✨ Features

### 🛒 Customer Side
- **Product browsing** with size variants and dynamic pricing
- **Shopping cart** with real-time quantity updates and subtotal calculation
- **Saved delivery addresses** with GPS auto-detection and OpenStreetMap preview
- **Multiple payment methods:**
  - 💵 Cash on Delivery
  - 💳 Wallet (prepaid balance)
  - 📱 (WIP) GCash (via PayMongo Sources API)
  - 🔲 QR Ph (via PayMongo Payment Intents API — supports GCash, Maya, BDO, BPI, UnionBank, and more)
- **Auto-Buy** — schedule recurring orders using wallet balance
- **Order history** tracking
- **Wallet top-up** system with transaction history

### 🛠️ Admin Side
- Product and inventory management
- Order management and status updates
- Stock level tracking (auto-decremented on order)
- Customer management

---

## 💳 Payment Integration

This project integrates with **[PayMongo](https://paymongo.com)** for online payments.

| Method | API Used | Flow |
|--------|----------|------|
| GCash | Sources API | Redirect → Authorize → Webhook inserts order |
| QR Ph | Payment Intents API | 3-step intent → QR code displayed → Webhook inserts order |

### How it works
1. User selects payments method GCash or QR Ph at checkout
2. A pending order is saved to `gcash_pending_orders` table
3. User completes payment on PayMongo's interface
4. PayMongo fires a webhook to `payment_webhook.php`
5. Webhook verifies signature, inserts order into DB, clears cart

---

## 🗂️ Project Structure

```
water/
├── auth/
│   ├── login.php
│   ├── logout.php
│   └── register.php
├── includes/
│   ├── auth_check.php
│   ├── header.php
│   └── footer.php
├── staff/
│   ├── dashboard.php
│   ├── orders.php
│   └── products.php
├── user/
│   ├── cart.php               # Main checkout page
│   ├── cart_action.php        # AJAX cart qty/remove
│   ├── shop.php               # Product listing
│   ├── orders.php             # Order history
│   ├── wallet.php             # Wallet top-up
│   ├── payment_return.php     # GCash redirect landing page
│   ├── payment_webhook.php    # PayMongo webhook handler
│   ├── qrph_payment.php       # QR Ph display page
│   ├── qrph_check.php         # Payment intent status poller
│   └── qrph_clear.php         # Clears QR session after payment
├── config.php                 # DB connection
└── index.php
```

---

## 🗄️ Database Tables (Key)

| Table | Purpose |
|-------|---------|
| `users` | Customer accounts + wallet balance |
| `products` | Menu items with stock |
| `sizes` | Size variants with price modifiers |
| `cart` | Active cart items |
| `orders` | Placed orders |
| `payments_type` | Payment methods (COD, Wallet, GCash, QR Ph) |
| `shipping_addresses` | Saved delivery addresses per user |
| `gcash_pending_orders` | Holds order data while awaiting PayMongo webhook |
| `wallet_transactions` | Wallet top-up and deduction history |
| `customer_type` | Customer classification |

### Required SQL — GCash Pending Orders Table
```sql
CREATE TABLE gcash_pending_orders (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    Source_ID VARCHAR(100) NOT NULL,
    User_ID INT NOT NULL,
    Items_JSON TEXT NOT NULL,
    Address_ID INT NOT NULL,
    Payment_Type_ID INT NOT NULL,
    Customer_Name VARCHAR(255),
    Order_Note TEXT,
    Grand_Total DECIMAL(10,2),
    Created_At TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Add Payment Methods
```sql
INSERT INTO payments_type (Payment_Type_Description) VALUES ('GCash');
INSERT INTO payments_type (Payment_Type_Description) VALUES ('QR Ph');
```

---

## ⚙️ Setup & Installation

### Requirements
- PHP 7.4+
- MySQL 5.7+
- Apache / XAMPP / Laragon
- cURL enabled in PHP
- A [PayMongo](https://paymongo.com) account

### 1. Clone the repo
```bash
git clone https://github.com/YOUR_USERNAME/aqualuxe.git
cd aqualuxe
```

### 2. Import the database
```bash
mysql -u root -p < sql/water.sql
```

### 3. Configure database connection
Edit `config.php`:
```php
$conn = new mysqli('localhost', 'root', '', 'water');
```

### 4. Add your PayMongo keys
In `user/cart.php`:
```php
$sk = 'sk_live_YOUR_SECRET_KEY';
```

In `user/payment_webhook.php`:
```php
$sk    = 'sk_live_YOUR_SECRET_KEY';
$whsec = 'whsk_YOUR_WEBHOOK_SECRET';
```

In `user/qrph_check.php`:
```php
$sk = 'sk_live_YOUR_SECRET_KEY';
```

### 5. Set redirect URLs
In `user/cart.php`, update both GCash and QR Ph redirect/return URLs to your domain or ngrok URL:
```php
'success' => 'https://YOUR-DOMAIN/water/user/payment_return.php?status=success',
'failed'  => 'https://YOUR-DOMAIN/water/user/payment_return.php?status=failed',

// QR Ph return:
'return_url' => 'https://YOUR-DOMAIN/water/user/payment_return.php',
```

---

## 🧪 Local Testing with ngrok

Since PayMongo webhooks require a public HTTPS URL, use **[ngrok](https://ngrok.com)** for local development.

```bash
# Start ngrok (adjust port to match your local server)
ngrok http 80
```

Then in your **PayMongo Dashboard → Developers → Webhooks**:
- Set URL to: `https://YOUR-NGROK-URL.ngrok-free.app/water/user/payment_webhook.php`
- Enable events: ✅ `source.chargeable` ✅ `payment_intent.succeeded`

> 💡 **Tip:** Reserve a free static domain in ngrok dashboard so the URL doesn't change on restart.

---

## 🔐 Security Notes

- Webhook signature verification is implemented using HMAC-SHA256
- All DB inputs are escaped with `real_escape_string` (consider migrating to PDO prepared statements for production)
- `CURLOPT_SSL_VERIFYPEER` is disabled locally — **re-enable this in production**
- Secret keys should be moved to environment variables or a `.env` file before deploying

---

## 🚀 Going Live Checklist

- [ ] Replace all `sk_test_...` keys with `sk_live_...`
- [ ] Replace ngrok webhook URL with your real domain
- [ ] Update redirect URLs from localhost/ngrok to production domain
- [ ] Set `CURLOPT_SSL_VERIFYPEER => true` in all cURL calls
- [ ] Remove `debug.txt` and `webhook_log.txt` debug files
- [ ] Apply for GCash on your PayMongo live account (requires business verification)
- [ ] Move secret keys to environment variables

---

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8+ |
| Database | MySQL |
| Frontend | HTML, CSS, Vanilla JS |
| Fonts | Google Fonts (Playfair Display, DM Sans, DM Mono) |
| Maps | OpenStreetMap + Nominatim (reverse geocoding) |
| Payments | PayMongo (Sources API + Payment Intents API) |
| Tunneling (dev) | ngrok |

---

## 📸 Screenshots

> Add screenshots here of the shop, cart, QR payment page, and admin dashboard.

---

## 📄 License

This project is for educational purposes. All rights reserved.

---

## 🙏 Acknowledgements

- [PayMongo](https://paymongo.com) — Philippine payment gateway
- [OpenStreetMap](https://openstreetmap.org) — Address and map data
- [ngrok](https://ngrok.com) — Local tunnel for webhook testing
