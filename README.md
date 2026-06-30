# 💧 AquaLuxe — Water Refilling Station Ordering System

A full-stack web ordering system for a water refilling station business, built with **HTML, CSS, JS, PHP, MySQL**. Customers can browse products, manage a cart, pay via multiple methods, and track their orders — all from a clean, mobile-friendly interface. Staff have a dedicated dashboard to prepare, dispatch, and deliver orders with proof-of-delivery verification.

> 📚 **Academic Project:** Developed for the **Techno Entrepreneurship** subject, 3rd Year — BS Information Technology.

---

## ✨ Features

### 🛒 Customer Side
- **Product browsing** with size variants and dynamic pricing
- **Shopping cart** with real-time quantity updates and subtotal calculation
- **Saved delivery addresses** with GPS auto-detection and OpenStreetMap preview
- **Multiple payment methods:**
  - 💵 Cash on Delivery
  - 💳 Wallet (prepaid balance)
  - 📱 **GCash** (via PayMongo Payment Intent API — Payment Method + Attach flow) ✅ **Live**
  - 🔲 QR Ph (via PayMongo Payment Intents API — supports GCash, Maya, BDO, BPI, UnionBank, and more)
- **Auto-Buy** — schedule recurring orders using wallet balance
- **Order history** tracking
- **Wallet top-up** system with transaction history
- **Live delivery tracking** — customers get a shareable tracker link to follow their rider's location in real time

### 🛠️ Staff Side
- **Order dashboard** — filter by status (Pending / Preparing / Out for Delivery / Completed / Cancelled) and by service type (Delivery vs. Refill)
- **Single or batch dispatch** — staff can claim one order at a time, or select multiple "Preparing" orders and dispatch all of them at once as the assigned rider
- **Rider locking** — once an order is claimed by a staff member, it's locked from other staff to prevent double-assignment
- **Proof of delivery** — riders upload a photo (camera capture or file upload) per order; an order **cannot** be marked Completed until proof is uploaded
- **Live rider tracker links** — each "Out for Delivery" order generates a tracker URL the rider can open to broadcast live location
- **Automatic stock restoration** on order cancellation
- **My Deliveries view** — quick filter to see only the orders the logged-in staff member is currently riding

### 🧑‍💼 Admin Side
- Product and inventory management
- Order management and status updates
- Stock level tracking (auto-decremented on order)
- Customer management

---

## 💳 Payment Integration

This project integrates with **[PayMongo](https://paymongo.com)** for online payments.

| Method | API Used | Flow | Status |
|--------|----------|------|--------|
| GCash | Payment Intents API (Payment Method + Attach) | Create Intent → Create Payment Method → Attach → Redirect → Authorize → Confirm status on return | ✅ Live |
| QR Ph | Payment Intents API | 3-step intent → QR code displayed → Webhook inserts order | ✅ Live |

### How GCash payment works
1. Backend creates a **Payment Intent** (secret key) with `payment_method_allowed: ['gcash']`
2. Frontend creates a **Payment Method** of type `gcash` (public key) with the customer's billing details
3. Frontend **attaches** the Payment Method to the Payment Intent (public key) — this returns a redirect URL
4. Customer is redirected to GCash to authorize the payment
5. Customer is redirected back to `payment_return.php`, which queries the Payment Intent status (secret key) to confirm success
6. Order is finalized once status is `succeeded`

### How QR Ph payment works
1. User selects QR Ph at checkout
2. A pending order is saved to `gcash_pending_orders` table
3. PayMongo generates a scannable QR code from the Payment Intent
4. User completes payment by scanning with any participating bank/e-wallet app
5. PayMongo fires a webhook to `payment_webhook.php`
6. Webhook verifies signature, inserts order into DB, clears cart

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
│   ├── staff_header.php
│   └── footer.php
├── staff/
│   ├── dashboard.php
│   ├── orders.php             # Order dashboard, batch dispatch, proof-of-delivery upload
│   └── products.php
├── uploads/
│   └── pod/                   # Proof-of-delivery photos (per Order_ID)
├── user/
│   ├── cart.php               # Main checkout page
│   ├── cart_action.php        # AJAX cart qty/remove
│   ├── shop.php               # Product listing
│   ├── orders.php             # Order history
│   ├── wallet.php             # Wallet top-up
│   ├── payment_return.php     # GCash redirect landing page — confirms Payment Intent status
│   ├── payment_webhook.php    # PayMongo webhook handler (QR Ph)
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
| `orders` | Placed orders — now includes `Rider_ID`, `Rider_Name`, `Dispatched_At`, `Completed_At`, `Proof_Image`, `Proof_Uploaded_At` |
| `payments_type` | Payment methods (COD, Wallet, GCash, QR Ph) |
| `shipping_addresses` | Saved delivery addresses per user |
| `gcash_pending_orders` | Holds order data while awaiting PayMongo webhook (QR Ph flow) |
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

### Required SQL — Orders Table Additions (Staff Dispatch & Proof of Delivery)
```sql
ALTER TABLE orders
    ADD COLUMN Rider_ID INT NULL,
    ADD COLUMN Rider_Name VARCHAR(255) NULL,
    ADD COLUMN Dispatched_At TIMESTAMP NULL,
    ADD COLUMN Completed_At TIMESTAMP NULL,
    ADD COLUMN Proof_Image VARCHAR(255) NULL,
    ADD COLUMN Proof_Uploaded_At TIMESTAMP NULL;
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
- Write permissions on `uploads/pod/` for proof-of-delivery photo uploads

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
$pk = 'pk_live_YOUR_PUBLIC_KEY';
$sk = 'sk_live_YOUR_SECRET_KEY';
```

In `user/payment_return.php`:
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

### 6. Set up proof-of-delivery uploads folder
```bash
mkdir -p uploads/pod
chmod 775 uploads/pod
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
- Enable events: ✅ `payment.paid` ✅ `payment_intent.succeeded`

> 💡 **Tip:** Reserve a free static domain in ngrok dashboard so the URL doesn't change on restart.

---

## 🔐 Security Notes

- Webhook signature verification is implemented using HMAC-SHA256
- Proof-of-delivery uploads are restricted by file type (JPG/PNG/WEBP/GIF) and max size (8 MB), and ownership is checked against the logged-in rider before allowing upload or completion
- All DB inputs are escaped with `real_escape_string` (consider migrating to PDO prepared statements for production)
- `CURLOPT_SSL_VERIFYPEER` is disabled locally — **re-enable this in production**
- Secret keys should be moved to environment variables or a `.env` file before deploying

---

## 🚀 Going Live Checklist

- [ ] Replace all `pk_test_...` / `sk_test_...` keys with `pk_live_...` / `sk_live_...`
- [ ] Replace ngrok webhook URL with your real domain
- [ ] Update redirect/return URLs from localhost/ngrok to production domain
- [ ] Set `CURLOPT_SSL_VERIFYPEER => true` in all cURL calls
- [ ] Remove `debug.txt` and `webhook_log.txt` debug files
- [ ] Apply for GCash on your PayMongo live account (requires business verification)
- [ ] Move secret keys to environment variables
- [ ] Confirm `uploads/pod/` exists with correct write permissions on the production server

---

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8+ |
| Database | MySQL |
| Frontend | HTML, CSS, Vanilla JS |
| Fonts | Google Fonts (Playfair Display, DM Sans, DM Mono) |
| Maps | OpenStreetMap + Nominatim (reverse geocoding) |
| Payments | PayMongo (Payment Intent API — GCash & QR Ph) |
| Tunneling (dev) | ngrok |

---

## 📸 Screenshots

> Add screenshots here of the shop, cart, QR payment page, staff order dashboard, and admin dashboard.

---

## 📄 License

This project is for educational purposes. All rights reserved.

---

## 🙏 Acknowledgements

- [PayMongo](https://paymongo.com) — Philippine payment gateway
- [OpenStreetMap](https://openstreetmap.org) — Address and map data
- [ngrok](https://ngrok.com) — Local tunnel for webhook testing
