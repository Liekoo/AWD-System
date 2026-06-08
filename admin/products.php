<?php
require '../config.php';
$pageTitle = 'Products';

// ── CSV Import (ADDED) ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    $added = $updated = $skipped = 0;
    if (!empty($_FILES['csv_file']['name']) && $_FILES['csv_file']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') { header('Location: products.php?import_error=filetype'); exit; }
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $header = array_map(fn($h) => strtolower(trim($h)), fgetcsv($handle));
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($header)) { $skipped++; continue; }
            $d      = array_combine($header, $row);
            $name   = $conn->real_escape_string(trim($d['product_name']            ?? ''));
            $price  = (float)($d['product_price']                                  ?? 0);
            $stock  = (int)($d['product_quantity_stock']                            ?? 0);
            $status = $conn->real_escape_string(trim($d['product_status']           ?? 'Active'));
            $desc   = $conn->real_escape_string(trim($d['product_description']      ?? ''));
            $image  = $conn->real_escape_string(trim($d['product_image']            ?? ''));
            $pid    = (int)($d['product_id']                                        ?? 0);
            if (empty($name)) { $skipped++; continue; }
            if (!in_array($status, ['Active','Inactive','Out of Stock'])) $status = 'Active';
            $img_sql  = !empty($image) ? "'$image'" : 'NULL';
            $img_part = !empty($image) ? ", Product_Image='$image'" : '';
            if ($pid > 0 && $conn->query("SELECT Product_ID FROM products WHERE Product_ID=$pid")->num_rows > 0) {
                $conn->query("UPDATE products SET Product_Name='$name', Product_Price=$price,
                    Product_Quantity_Stock=$stock, Product_Status='$status',
                    Product_Description='$desc' $img_part WHERE Product_ID=$pid");
                $updated++;
            } else {
                $conn->query("INSERT INTO products (Product_Name,Product_Price,Product_Quantity_Stock,
                    Product_Status,Product_Description,Product_Image)
                    VALUES ('$name',$price,$stock,'$status','$desc',$img_sql)");
                $added++;
            }
        }
        fclose($handle);
        header("Location: products.php?import_ok=1&added=$added&updated=$updated&skipped=$skipped");
    } else {
        header('Location: products.php?import_error=nofile');
    }
    exit;
}
// ── END CSV Import ─────────────────────────────────────────────────────────────

// ── YOUR EXISTING CODE (unchanged) ────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id   = (int)$_GET['delete'];
    $used = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE Product_ID = $id")->fetch_assoc()['c'];
    if ($used > 0) { header('Location: products.php?error=inuse'); exit; }
    $row = $conn->query("SELECT Product_Image FROM products WHERE Product_ID = $id")->fetch_assoc();
    if (!empty($row['Product_Image']) && file_exists('../' . $row['Product_Image'])) unlink('../' . $row['Product_Image']);
    $conn->query("DELETE FROM products WHERE Product_ID = $id");
    header('Location: products.php?success=deleted'); exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $editRow = $conn->query("SELECT * FROM products WHERE Product_ID = $id")->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = $conn->real_escape_string($_POST['Product_Name']);
    $price  = (float)$_POST['Product_Price'];
    $stock  = (int)$_POST['Product_Quantity_Stock'];
    $status = $conn->real_escape_string($_POST['Product_Status']);
    $desc   = $conn->real_escape_string($_POST['Product_Description']);

    $filename = null;
    if (!empty($_FILES['Product_Image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['Product_Image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
            $filename = 'uploads/products/' . uniqid('prod_') . '.' . $ext;
            move_uploaded_file($_FILES['Product_Image']['tmp_name'], '../' . $filename);
        }
    }

    if (isset($_POST['Product_ID']) && $_POST['Product_ID'] !== '') {
        $id = (int)$_POST['Product_ID'];
        $img_part = $filename ? ", Product_Image='$filename'" : '';
        $conn->query("UPDATE products SET
            Product_Name='$name',
            Product_Price=$price,
            Product_Quantity_Stock=$stock,
            Product_Status='$status',
            Product_Description='$desc'
            $img_part
            WHERE Product_ID=$id");
        header('Location: products.php?success=updated');
    } else {
        if ($filename) {
            $conn->query("INSERT INTO products (Product_Name, Product_Price, Product_Quantity_Stock, Product_Status, Product_Description, Product_Image)
                          VALUES ('$name', $price, $stock, '$status', '$desc', '$filename')");
        } else {
            $conn->query("INSERT INTO products (Product_Name, Product_Price, Product_Quantity_Stock, Product_Status, Product_Description)
                          VALUES ('$name', $price, $stock, '$status', '$desc')");
        }
        header('Location: products.php?success=created');
    }
    exit;
}

$products = $conn->query("SELECT * FROM products ORDER BY Product_ID DESC");
$sizeCount = $conn->query("SELECT COUNT(*) AS c FROM sizes")->fetch_assoc()['c'];
require "../includes/header.php";
?>

<div class="page-header"><h1 class="page-title">Pro<span>ducts</span></h1></div>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success">✓ Product <?= htmlspecialchars($_GET['success']) ?> successfully.</div>
<?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] === 'inuse'): ?>
  <div class="alert alert-error">✕ Cannot delete — this product has existing orders.</div>
<?php endif; ?>

<!-- ADDED: Import result alerts -->
<?php if (isset($_GET['import_ok'])): ?>
  <div class="alert alert-success">
    ✓ Import complete — <?= (int)$_GET['added'] ?> added, <?= (int)$_GET['updated'] ?> updated, <?= (int)$_GET['skipped'] ?> skipped.
  </div>
<?php endif; ?>
<?php if (isset($_GET['import_error'])): ?>
  <div class="alert alert-error">
    ✕ Import failed — <?= $_GET['import_error'] === 'filetype' ? 'only .csv files are allowed.' : 'no file was uploaded.' ?>
  </div>
<?php endif; ?>
<!-- END ADDED -->

<div class="card">
  <div class="card-title"><?= $editRow ? 'Edit Product #'.$editRow['Product_ID'] : 'New Product' ?></div>
  <form method="POST" enctype="multipart/form-data">
    <?php if ($editRow): ?><input type="hidden" name="Product_ID" value="<?= $editRow['Product_ID'] ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="form-group">
        <label>Product Name</label>
        <input type="text" name="Product_Name" required value="<?= htmlspecialchars($editRow['Product_Name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Base Price (₱)</label>
        <input type="number" name="Product_Price" step="0.01" min="0" required value="<?= $editRow['Product_Price'] ?? '' ?>">
      </div>
      <div class="form-group">
        <label>Stock</label>
        <input type="number" name="Product_Quantity_Stock" min="0" required value="<?= $editRow['Product_Quantity_Stock'] ?? 0 ?>">
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="Product_Status">
          <?php foreach (['Active','Inactive','Out of Stock'] as $s): ?>
            <option <?= ($editRow && $editRow['Product_Status']==$s) ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Image <?= ($editRow && !empty($editRow['Product_Image'])) ? '(leave blank to keep)' : '' ?></label>
        <input type="file" name="Product_Image" accept="image/*" onchange="previewImg(this)">
        <?php if ($editRow && !empty($editRow['Product_Image'])): ?>
          <img src="../<?= htmlspecialchars($editRow['Product_Image']) ?>" id="imgPreview"
               style="margin-top:8px;width:60px;height:60px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
        <?php else: ?>
          <img id="imgPreview" style="display:none;margin-top:8px;width:60px;height:60px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
        <?php endif; ?>
      </div>
      <div class="form-group full">
        <label>Description</label>
        <textarea name="Product_Description"><?= htmlspecialchars($editRow['Product_Description'] ?? '') ?></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= $editRow ? '✓ Update Product' : '+ Add Product' ?></button>
      <?php if ($editRow): ?><a href="products.php" class="btn btn-ghost">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <!-- ADDED: Export buttons + Import accordion header row -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:4px">
    <div class="card-title" style="margin:0">All Products</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="export_products.php?format=csv"   class="btn btn-ghost btn-sm">⬇ CSV</a>
      <a href="export_products.php?format=excel" class="btn btn-ghost btn-sm">⬇ Excel</a>
      <a href="export_products.php?format=svg"   class="btn btn-ghost btn-sm">⬇ SVG</a>
      <button type="button" id="importToggleBtn"
              class="btn btn-sm"
              style="background:rgba(0,200,150,0.12);color:#009e77;border:1px solid rgba(0,200,150,0.3)">
        ⬆ Import CSV
      </button>
    </div>
  </div>

  <!-- ADDED: Import accordion -->
  <div id="importAccordion" style="display:none;margin-bottom:16px;margin-top:12px">
    <div style="background:rgba(0,200,150,0.06);border:1px solid rgba(0,200,150,0.25);border-radius:10px;padding:16px">
      <div style="font-size:12px;font-family:var(--mono);color:var(--muted);margin-bottom:10px">
        Upload a <strong>.csv</strong> file to bulk-add or update products.<br>
        Columns: <code>Product_ID, Product_Name, Product_Price, Product_Quantity_Stock, Product_Status, Product_Description, Product_Image</code><br>
        Leave <code>Product_ID</code> blank to add new. Fill it to update an existing product.
        &nbsp;·&nbsp;
        <a href="export_products.php?format=template" style="color:#009e77">Download blank template →</a>
      </div>
      <form method="POST" enctype="multipart/form-data"
            style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <input type="hidden" name="import_csv" value="1">
        <input type="file" name="csv_file" accept=".csv" required
               style="font-size:13px;flex:1;min-width:200px">
        <button type="submit" class="btn btn-sm btn-primary">⬆ Import</button>
      </form>
    </div>
  </div>
  <!-- END ADDED -->

  <?php if ($sizeCount > 0): ?>
    <p style="font-size:12px;color:var(--muted);font-family:var(--mono);margin-bottom:16px">
      ✓ <?= $sizeCount ?> global size<?= $sizeCount != 1 ? 's' : '' ?> active —
      <a href="sizes.php" style="color:var(--accent2)">manage sizes</a>
    </p>
  <?php else: ?>
    <p style="font-size:12px;color:var(--warn);font-family:var(--mono);margin-bottom:16px">
      ⚠ No sizes defined yet — <a href="sizes.php" style="color:var(--warn)">add sizes</a> so customers can choose S / M / L
    </p>
  <?php endif; ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Image</th><th>#ID</th><th>Name</th><th>Base Price</th>
          <th>Sizes</th><th>Stock</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = $products->fetch_assoc()): ?>
        <tr>
          <td>
            <?php if (!empty($row['Product_Image'])): ?>
              <img src="../<?= htmlspecialchars($row['Product_Image']) ?>"
                   style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">
            <?php else: ?>
              <div style="width:44px;height:44px;border-radius:6px;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:18px">📦</div>
            <?php endif; ?>
          </td>
          <td class="mono">#<?= $row['Product_ID'] ?></td>
          <td><?= htmlspecialchars($row['Product_Name']) ?></td>
          <td class="mono">₱<?= number_format($row['Product_Price'], 2) ?></td>
          <td>
            <span class="badge <?= $sizeCount > 0 ? 'badge-blue' : 'badge-yellow' ?>">
              <?= $sizeCount ?> size<?= $sizeCount != 1 ? 's' : '' ?>
            </span>
          </td>
          <td><?= $row['Product_Quantity_Stock'] ?></td>
          <td>
            <?php $b = match($row['Product_Status']) {
              'Active'   => 'badge-green',
              'Inactive' => 'badge-red',
              default    => 'badge-yellow'
            }; ?>
            <span class="badge <?= $b ?>"><?= $row['Product_Status'] ?></span>
          </td>
          <td style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="?edit=<?= $row['Product_ID'] ?>" class="btn btn-ghost btn-sm">Edit</a>
            <a href="sizes.php" class="btn btn-sm" style="background:rgba(34,211,238,0.1);color:var(--accent2);border:1px solid rgba(34,211,238,0.2)">Sizes</a>
            <a href="?delete=<?= $row['Product_ID'] ?>" class="btn btn-danger btn-sm"
               onclick="return confirm('Delete this product?')">Del</a>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// YOUR EXISTING script (unchanged)
function previewImg(input) {
  const p = document.getElementById('imgPreview');
  if (input.files && input.files[0]) { p.src = URL.createObjectURL(input.files[0]); p.style.display = 'block'; }
}

// ADDED: Import accordion toggle
const importAcc = document.getElementById('importAccordion');
document.getElementById('importToggleBtn').addEventListener('click', function() {
  importAcc.style.display = importAcc.style.display === 'none' ? 'block' : 'none';
});
// Keep accordion open if import just ran
<?php if (isset($_GET['import_ok']) || isset($_GET['import_error'])): ?>
importAcc.style.display = 'block';
<?php endif; ?>
</script>

<?php require '../includes/footer.php'; ?>