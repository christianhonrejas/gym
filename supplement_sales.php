<?php
require_once 'auth.php';
$pageTitle = 'Supplement Sales';

// Safely add columns only if they don't exist (compatible with older MySQL)
$existingCols = [];
$colRes = $conn->query("SHOW COLUMNS FROM supplements");
if ($colRes) { while($c = $colRes->fetch_assoc()) $existingCols[] = $c['Field']; }

if (!in_array('brand', $existingCols))
    $conn->query("ALTER TABLE supplements ADD COLUMN brand VARCHAR(100) NULL AFTER name");
if (!in_array('category', $existingCols))
    $conn->query("ALTER TABLE supplements ADD COLUMN category ENUM('Protein','Pre-workout','Creatine','Vitamins','BCAAs','Weight Gainer','Fat Burner','Other') DEFAULT 'Other' AFTER brand");
if (!in_array('stock_quantity', $existingCols))
    $conn->query("ALTER TABLE supplements ADD COLUMN stock_quantity INT DEFAULT 0 AFTER category");

// Ensure supplement_sales table exists
$conn->query("CREATE TABLE IF NOT EXISTS supplement_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_no VARCHAR(30) UNIQUE NOT NULL,
    member_name VARCHAR(100) NOT NULL,
    user_id VARCHAR(30) NULL,
    supplement_id INT NULL,
    supplement_name VARCHAR(100) NOT NULL,
    brand VARCHAR(100) NULL,
    category VARCHAR(50) NULL,
    price DECIMAL(10,2) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('Cash','Card','GCash','Maya','Others') DEFAULT 'Cash',
    payment_status ENUM('Paid','Pending') DEFAULT 'Paid',
    staff_name VARCHAR(100) NULL,
    notes TEXT NULL,
    sale_date DATE NOT NULL,
    sale_time TIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$successMsg = $errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Record a new sale ────────────────────────────────────────────────────
    if ($action === 'record_sale') {
        $memberName    = trim($_POST['member_name'] ?? '');
        $userId        = trim($_POST['user_id'] ?? '');
        $suppId        = intval($_POST['supplement_id'] ?? 0);
        $customSupp    = trim($_POST['custom_supplement'] ?? '');
        $price         = floatval($_POST['price'] ?? 0);
        $qty           = max(1, intval($_POST['quantity'] ?? 1));
        $paymentMethod = $_POST['payment_method'] ?? 'Cash';
        $payStatus     = $_POST['payment_status'] ?? 'Paid';
        $staffName     = trim($_POST['staff_name'] ?? '');
        $notes         = trim($_POST['notes'] ?? '');
        $saleDate      = $_POST['sale_date'] ?? date('Y-m-d');
        $saleTime      = date('H:i:s');

        if (!$memberName) {
            $errorMsg = 'Customer name is required.';
        } elseif (!$suppId && !$customSupp) {
            $errorMsg = 'Please select a supplement or enter a custom name.';
        } elseif ($price <= 0) {
            $errorMsg = 'Price must be greater than zero.';
        } else {
            // Resolve supplement info
            $suppName = $customSupp;
            $suppBrand = '';
            $suppCat = '';
            if ($suppId) {
                $sr = $conn->query("SELECT * FROM supplements WHERE id = $suppId")->fetch_assoc();
                if ($sr) {
                    $suppName  = $sr['name'];
                    $suppBrand = $sr['brand'] ?? '';
                    $suppCat   = $sr['category'] ?? '';
                    // Check stock
                    if (($sr['stock_quantity'] ?? 0) < $qty) {
                        $errorMsg = "Insufficient stock. Only " . ($sr['stock_quantity'] ?? 0) . " unit(s) available.";
                    }
                }
            }

            if (!$errorMsg) {
                $total = $price * $qty;
                // Generate receipt no
                $receiptNo = 'RCP-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 5));

                $safeMemName  = $conn->real_escape_string($memberName);
                $safeUserId   = $conn->real_escape_string($userId);
                $safeSuppName = $conn->real_escape_string($suppName);
                $safeBrand    = $conn->real_escape_string($suppBrand);
                $safeCat      = $conn->real_escape_string($suppCat);
                $safeStaff    = $conn->real_escape_string($staffName);
                $safeNotes    = $conn->real_escape_string($notes);
                $safeReceipt  = $conn->real_escape_string($receiptNo);
                $safePay      = $conn->real_escape_string($paymentMethod);
                $safeStatus   = $conn->real_escape_string($payStatus);

                $insert = $conn->query("INSERT INTO supplement_sales
                    (receipt_no, member_name, user_id, supplement_id, supplement_name, brand, category,
                     price, quantity, total_amount, payment_method, payment_status, staff_name, notes, sale_date, sale_time)
                    VALUES
                    ('$safeReceipt','$safeMemName','$safeUserId',".($suppId ?: 'NULL').",'$safeSuppName','$safeBrand','$safeCat',
                     $price,$qty,$total,'$safePay','$safeStatus','$safeStaff','$safeNotes','$saleDate','$saleTime')");

                if ($insert) {
                    // Deduct stock if supplement from catalog
                    if ($suppId) {
                        $conn->query("UPDATE supplements SET stock_quantity = GREATEST(0, stock_quantity - $qty) WHERE id = $suppId");
                    }
                    $successMsg = "Sale recorded! Receipt: <strong>$receiptNo</strong> — <strong>" . htmlspecialchars($suppName) . "</strong> x$qty for <strong>₱" . number_format($total, 2) . "</strong>.";
                } else {
                    $errorMsg = 'Failed to record sale. Please try again.';
                }
            }
        }
    }

    // ── Delete a sale record ─────────────────────────────────────────────────
    if ($action === 'delete_sale') {
        $saleId = intval($_POST['sale_id'] ?? 0);
        if ($saleId) {
            // Restore stock
            $sale = $conn->query("SELECT supplement_id, quantity FROM supplement_sales WHERE id = $saleId")->fetch_assoc();
            if ($sale && $sale['supplement_id']) {
                $conn->query("UPDATE supplements SET stock_quantity = stock_quantity + {$sale['quantity']} WHERE id = {$sale['supplement_id']}");
            }
            $conn->query("DELETE FROM supplement_sales WHERE id = $saleId");
            $successMsg = "Sale record deleted and stock restored.";
        }
    }

    // ── Restock supplement ───────────────────────────────────────────────────
    if ($action === 'restock') {
        $suppId  = intval($_POST['restock_id'] ?? 0);
        $addQty  = intval($_POST['restock_qty'] ?? 0);
        if ($suppId && $addQty > 0) {
            $conn->query("UPDATE supplements SET stock_quantity = stock_quantity + $addQty WHERE id = $suppId");
            $successMsg = "Stock updated successfully.";
        }
    }
}

// ── Filters ──────────────────────────────────────────────────────────────────
$fromDate = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : date('Y-m-d');
$toDate   = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : date('Y-m-d');

$salesWhere = "WHERE sale_date BETWEEN '$fromDate' AND '$toDate' AND payment_status = 'Paid'";
$sales = $conn->query("SELECT * FROM supplement_sales WHERE sale_date BETWEEN '$fromDate' AND '$toDate' ORDER BY created_at DESC");
$totalRevenue = $conn->query("SELECT COALESCE(SUM(total_amount),0) as rev FROM supplement_sales $salesWhere")->fetch_assoc()['rev'] ?? 0;
$totalTx      = $conn->query("SELECT COUNT(*) as c FROM supplement_sales WHERE sale_date BETWEEN '$fromDate' AND '$toDate'")->fetch_assoc()['c'] ?? 0;
$todaySuppRev = $conn->query("SELECT COALESCE(SUM(total_amount),0) as rev FROM supplement_sales WHERE sale_date = CURDATE() AND payment_status='Paid'")->fetch_assoc()['rev'] ?? 0;

// Supplements for form dropdown
$supplements = $conn->query("SELECT * FROM supplements WHERE is_active = 1 ORDER BY name ASC");
// Supplements for inventory
$inventory = $conn->query("SELECT * FROM supplements ORDER BY name ASC");

include 'header.php';
?>

<style>
.supp-badge {
  display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;
}
.supp-badge.Protein      { background:rgba(30,120,255,.12);color:#1e78ff; }
.supp-badge.Pre-workout  { background:rgba(239,68,68,.12);color:#ef4444; }
.supp-badge.Creatine     { background:rgba(139,92,246,.12);color:#8b5cf6; }
.supp-badge.Vitamins     { background:rgba(16,185,129,.12);color:#10b981; }
.supp-badge.BCAAs        { background:rgba(20,184,166,.12);color:#14b8a6; }
.supp-badge.Weight-Gainer { background:rgba(245,158,11,.12);color:#f59e0b; }
.supp-badge.Fat-Burner   { background:rgba(249,115,22,.12);color:#f97316; }
.supp-badge.Other        { background:rgba(107,122,153,.12);color:#6b7a99; }
.stock-low  { color:#ef4444;font-weight:700; }
.stock-ok   { color:#10b981;font-weight:700; }
.stock-warn { color:#f59e0b;font-weight:700; }
.receipt-code { background:#f8faff;border:1px solid #e8ecf4;padding:2px 8px;border-radius:6px;font-size:11px;font-family:monospace;letter-spacing:.5px; }
</style>

<div id="toastWrap" style="position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;"></div>

<!-- Page Header -->
<div class="page-header mb-4">
  <div>
    <h4 class="fw-800 mb-1" style="font-family:'Barlow Condensed',sans-serif;font-size:26px;font-weight:800;">Supplement Sales</h4>
    <p class="text-muted mb-0" style="font-size:13px;">Record supplement purchases and manage inventory</p>
  </div>
  <div class="d-flex gap-2 flex-wrap align-items-center">
    <div class="stat-card py-2 px-3" style="min-width:0;text-align:center;">
      <div class="card-label" style="font-size:11px;">Today's Revenue</div>
      <div class="card-value" style="font-size:18px;color:var(--success);">₱<?= number_format($todaySuppRev, 2) ?></div>
    </div>
    <div class="stat-card py-2 px-3" style="min-width:0;text-align:center;">
      <div class="card-label" style="font-size:11px;">Filtered Period Revenue</div>
      <div class="card-value" style="font-size:18px;color:#f59e0b;">₱<?= number_format($totalRevenue, 2) ?></div>
    </div>
    <div class="stat-card py-2 px-3" style="min-width:0;text-align:center;">
      <div class="card-label" style="font-size:11px;">Transactions</div>
      <div class="card-value" style="font-size:18px;"><?= $totalTx ?></div>
    </div>
  </div>
</div>

<?php if ($successMsg): ?>
<div class="alert" style="background:#f0fff8;border:1px solid #a7f3d0;border-radius:12px;padding:14px 18px;color:#065f46;font-size:14px;margin-bottom:20px;">
  <i class="fas fa-check-circle me-2"></i><?= $successMsg ?>
</div>
<?php endif; ?>
<?php if ($errorMsg): ?>
<div class="alert" style="background:#fff0f0;border:1px solid #fca5a5;border-radius:12px;padding:14px 18px;color:#991b1b;font-size:14px;margin-bottom:20px;">
  <i class="fas fa-exclamation-circle me-2"></i><?= $errorMsg ?>
</div>
<?php endif; ?>

<!-- ── RECORD SALE FORM ───────────────────────────────────────────────────── -->
<div class="section-card mb-4">
  <div class="section-card-header">
    <span class="section-card-title"><i class="fas fa-cart-plus me-2" style="color:var(--primary)"></i>Record Supplement Purchase</span>
  </div>
  <div class="section-card-body">
    <form method="POST" action="" id="saleForm">
      <input type="hidden" name="action" value="record_sale">
      <input type="hidden" name="user_id" value="">
      <input type="hidden" name="staff_name" value="<?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?>">
      <input type="hidden" name="payment_method" value="Cash">
      <input type="hidden" name="payment_status" value="Paid">
      <input type="hidden" name="notes" value="">
      <div class="row g-3">

        <!-- CUSTOMER INFO -->
        <div class="col-md-4">
          <label class="form-label">Customer Name <span class="text-danger">*</span></label>
          <input type="text" name="member_name" class="form-control" placeholder="Enter customer name" required>
        </div>

        <!-- PRODUCT INFO -->
        <div class="col-md-4">
          <label class="form-label">Select Supplement <span class="text-danger">*</span></label>
          <select name="supplement_id" id="suppSelect" class="form-select" onchange="fillSuppData(this)">
            <option value="">-- Select from catalog --</option>
            <?php $supplements->data_seek(0); while($s = $supplements->fetch_assoc()):
              $qty = (int)($s['stock_quantity'] ?? 0);
            ?>
            <option value="<?= $s['id'] ?>"
              data-price="<?= $s['price'] ?>"
              data-stock="<?= $qty ?>"
              data-name="<?= htmlspecialchars($s['name']) ?>"
              <?= $qty === 0 ? 'disabled style="color:#ccc;"' : '' ?>>
              <?= htmlspecialchars($s['name']) ?>
              <?= $s['brand'] ? '— ' . htmlspecialchars($s['brand']) : '' ?>
              <?= $qty === 0 ? '(Not Available)' : '(Stock: ' . $qty . ')' ?>
            </option>
            <?php endwhile; ?>
          </select>
          <div id="stockWarn" style="font-size:12px;margin-top:4px;display:none;"></div>
        </div>

        <!-- PURCHASE DETAILS -->
        <div class="col-md-2">
          <label class="form-label">Price per Item (₱) <span class="text-danger">*</span></label>
          <input type="number" name="price" id="priceInput" class="form-control" placeholder="0.00" min="0.01" step="0.01" required oninput="computeTotal()">
        </div>
        <div class="col-md-1">
          <label class="form-label">Qty <span class="text-danger">*</span></label>
          <input type="number" name="quantity" id="qtyInput" class="form-control" value="1" min="1" required oninput="computeTotal()">
        </div>
        <div class="col-md-2">
          <label class="form-label">Total Amount</label>
          <div class="form-control" id="totalDisplay" style="background:#f0fff8;font-weight:700;font-size:16px;color:#10b981;border-color:#a7f3d0;">₱0.00</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Date</label>
          <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button type="submit" class="btn-primary-custom w-100" style="justify-content:center;padding:12px;">
            <i class="fas fa-cart-plus me-1"></i> Record
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── INVENTORY OVERVIEW ─────────────────────────────────────────────────── -->
<div class="section-card mb-4">
  <div class="section-card-header" style="background:rgba(245,158,11,.04);">
    <span class="section-card-title"><i class="fas fa-boxes-stacked me-2" style="color:#f59e0b"></i>Supplement Inventory</span>
    <span style="font-size:12px;color:var(--text-muted);">Stock levels — restock directly from here</span>
  </div>
  <div class="section-card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0" id="inventoryTable">
        <thead>
          <tr>
            <th>Supplement</th>
            <th>Brand</th>
            <th>Category</th>
            <th style="width:90px;">Price</th>
            <th style="width:100px;">Stock</th>
            <th style="width:160px;">Restock</th>
            <th style="width:100px;">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php $inventory->data_seek(0); while($s = $inventory->fetch_assoc()):
            $stock = (int)($s['stock_quantity'] ?? 0);
            $stockClass = $stock === 0 ? 'stock-low' : ($stock <= 5 ? 'stock-warn' : 'stock-ok');
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
            <td style="font-size:13px;color:var(--text-muted);"><?= htmlspecialchars($s['brand'] ?? '—') ?></td>
            <td><?php $cat = str_replace(' ', '-', $s['category'] ?? 'Other'); ?><span class="supp-badge <?= $cat ?>"><?= htmlspecialchars($s['category'] ?? 'Other') ?></span></td>
            <td><strong style="color:#f59e0b;">₱<?= number_format($s['price'], 2) ?></strong></td>
            <td><span class="<?= $stockClass ?>"><?= $stock ?> unit<?= $stock != 1 ? 's' : '' ?></span><?php if ($stock === 0): ?><br><span style="font-size:10px;color:#ef4444;">Out of stock</span><?php elseif ($stock <= 5): ?><br><span style="font-size:10px;color:#f59e0b;">Low stock</span><?php endif; ?></td>
            <td>
              <form method="POST" class="d-flex gap-1" style="align-items:center;">
                <input type="hidden" name="action" value="restock">
                <input type="hidden" name="restock_id" value="<?= $s['id'] ?>">
                <input type="number" name="restock_qty" class="form-control form-control-sm" placeholder="Qty" min="1" style="width:65px;" required>
                <button type="submit" class="btn btn-sm btn-outline-warning rounded-pill" style="font-size:11px;white-space:nowrap;">
                  <i class="fas fa-plus"></i> Add
                </button>
              </form>
            </td>
            <td>
              <?php
                if ($stock === 0):
              ?>
              <span class="badge-inactive">Not Available</span>
              <?php elseif ($s['is_active']): ?>
              <span class="badge-active">Available</span>
              <?php else: ?>
              <span class="badge-inactive">Unavailable</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── SALES HISTORY ─────────────────────────────────────────────────────── -->
<div class="section-card">
  <div class="section-card-header">
    <span class="section-card-title"><i class="fas fa-history me-2" style="color:var(--text-muted)"></i>Sales History</span>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn-outline-custom" onclick="exportSalesPDF()" style="padding:7px 14px;font-size:12px;">
        <i class="fas fa-file-pdf me-1"></i>PDF
      </button>
      <button class="btn-outline-custom" onclick="exportSalesCSV()" style="padding:7px 14px;font-size:12px;">
        <i class="fas fa-file-csv me-1"></i>CSV
      </button>
      <button class="btn-outline-custom" onclick="window.print()" style="padding:7px 14px;font-size:12px;">
        <i class="fas fa-print me-1"></i>Print
      </button>
    </div>
  </div>

  <!-- Date Filter -->
  <div style="padding:14px 18px;border-bottom:1px solid var(--border-color,#e8ecf4);background:#fafbff;">
    <form method="GET" class="d-flex flex-wrap gap-3 align-items-end">
      <div>
        <label class="form-label" style="font-size:12px;">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>" style="width:150px;">
      </div>
      <div>
        <label class="form-label" style="font-size:12px;">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>" style="width:150px;">
      </div>
      <button type="submit" class="btn-primary-custom" style="padding:8px 16px;font-size:13px;">
        <i class="fas fa-filter me-1"></i>Filter
      </button>
      <a href="supplement_sales.php" class="btn-outline-custom" style="padding:8px 16px;font-size:13px;">Reset</a>
      <div style="margin-left:auto;font-size:13px;color:var(--text-muted);align-self:center;">
        Period total: <strong style="color:#10b981;">₱<?= number_format($totalRevenue, 2) ?></strong>
        &nbsp;·&nbsp; <?= $totalTx ?> transaction<?= $totalTx != 1 ? 's' : '' ?>
      </div>
    </form>
  </div>

  <div class="section-card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0" id="salesTable">
        <thead>
          <tr>
            <th>Receipt No.</th>
            <th>Customer</th>
            <th>Supplement</th>
            <th>Category</th>
            <th>Qty</th>
            <th>Price</th>
            <th>Total</th>
            <th>Payment</th>
            <th>Date & Time</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($sales && $sales->num_rows > 0):
          while($s = $sales->fetch_assoc()): ?>
          <tr>
            <td><span class="receipt-code"><?= htmlspecialchars($s['receipt_no']) ?></span></td>
            <td><strong style="font-size:13px;"><?= htmlspecialchars($s['member_name']) ?></strong></td>
            <td><strong><?= htmlspecialchars($s['supplement_name']) ?></strong></td>
            <td><?php $cat = str_replace(' ', '-', $s['category'] ?? 'Other'); ?><span class="supp-badge <?= $cat ?>"><?= htmlspecialchars($s['category'] ?? '—') ?></span></td>
            <td style="font-weight:600;"><?= (int)$s['quantity'] ?></td>
            <td>₱<?= number_format($s['price'], 2) ?></td>
            <td><strong style="color:#10b981;">₱<?= number_format($s['total_amount'], 2) ?></strong></td>
            <td style="font-size:12px;font-weight:600;">💵 Cash</td>
            <td style="font-size:12px;white-space:nowrap;">
              <?= date('F d, Y', strtotime($s['sale_date'])) ?>
              <br><span style="color:var(--text-muted);"><?= date('h:i A', strtotime($s['sale_time'])) ?></span>
            </td>
            <td>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this sale record? Stock will be restored.')">
                <input type="hidden" name="action" value="delete_sale">
                <input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill" style="font-size:11px;"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Print Header -->
<div id="printHeader" style="display:none;margin-bottom:20px;">
  <h3 style="font-family:'Barlow Condensed',sans-serif;font-weight:800;">Diozabeth Fitness — Supplement Sales Report</h3>
  <p>Period: <?= date('F d, Y', strtotime($fromDate)) ?> – <?= date('F d, Y', strtotime($toDate)) ?></p>
  <p>Generated: <?= date('F d, Y h:i A') ?> | Total Revenue: ₱<?= number_format($totalRevenue, 2) ?></p>
  <hr>
</div>

<style>
@media print {
  .sidebar, .top-navbar, .page-footer, .btn-outline-custom, .section-card-header .d-flex,
  form:not(#printForms), .section-card:first-of-type, .section-card:nth-of-type(2) { display:none !important; }
  .main-wrapper { margin-left:0 !important; }
  body { background:white; }
  .section-card { box-shadow:none; border:1px solid #ddd; }
  .table { font-size:10px; }
  #printHeader { display:block !important; }
  .page-content { padding:0; }
}
</style>

<?php
$jsPDFSrc    = file_exists(__DIR__.'/assets/js/jspdf.umd.min.js')               ? 'assets/js/jspdf.umd.min.js'               : 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
$autoTableSrc= file_exists(__DIR__.'/assets/js/jspdf.plugin.autotable.min.js')  ? 'assets/js/jspdf.plugin.autotable.min.js'  : 'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.6.0/jspdf.plugin.autotable.min.js';
$extraScripts = <<<SCRIPTS
<script src="{$jsPDFSrc}"></script>
<script src="{$autoTableSrc}"></script>
<script>
// Auto-fill price from supplement select
function fillSuppData(sel) {
  var opt = sel.options[sel.selectedIndex];
  if (opt.value) {
    document.getElementById('priceInput').value = opt.dataset.price || '';
    var stock = parseInt(opt.dataset.stock || 0);
    var warn  = document.getElementById('stockWarn');
    if (stock === 0) {
      warn.style.display = 'block';
      warn.innerHTML = '<span style="color:#ef4444;"><i class="fas fa-exclamation-triangle me-1"></i>Out of stock!</span>';
    } else if (stock <= 5) {
      warn.style.display = 'block';
      warn.innerHTML = '<span style="color:#f59e0b;"><i class="fas fa-exclamation-circle me-1"></i>Low stock — only ' + stock + ' unit(s) left.</span>';
    } else {
      warn.style.display = 'block';
      warn.innerHTML = '<span style="color:#10b981;"><i class="fas fa-check-circle me-1"></i>' + stock + ' unit(s) in stock.</span>';
    }
  } else {
    document.getElementById('priceInput').value = '';
    document.getElementById('stockWarn').style.display = 'none';
  }
  computeTotal();
}

// Auto-compute total
function computeTotal() {
  var price = parseFloat(document.getElementById('priceInput').value) || 0;
  var qty   = parseInt(document.getElementById('qtyInput').value) || 1;
  var total = price * qty;
  document.getElementById('totalDisplay').textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// DataTable
$(document).ready(function() {
  $('#salesTable').DataTable({
    responsive: true,
    order: [[8, 'desc']],
    pageLength: 25,
    lengthMenu: [[10,25,50,100,-1],['10','25','50','100','All']],
    language: { emptyTable: 'No sales records found' },
    columnDefs: [{ orderable: false, targets: 9 }]
  });
  $('#inventoryTable').DataTable({
    responsive: true,
    pageLength: -1,
    searching: false,
    paging: false,
    info: false,
    columnDefs: [{ orderable: false, targets: [5,6] }]
  });
});

// Export PDF
function exportSalesPDF() {
  var jsPDF = window.jspdf.jsPDF;
  var doc = new jsPDF('l', 'mm', 'a4');
  doc.setFontSize(14); doc.setFont('helvetica','bold');
  doc.text('Diozabeth Fitness — Supplement Sales Report', 14, 16);
  doc.setFontSize(9); doc.setFont('helvetica','normal');
  doc.text('Generated: ' + new Date().toLocaleString('en-PH'), 14, 22);
  var rows = [];
  document.querySelectorAll('#salesTable tbody tr').forEach(function(tr) {
    var cells = tr.querySelectorAll('td');
    if (cells.length >= 9) rows.push([
      cells[0].innerText.trim(), cells[1].innerText.trim(),
      cells[2].innerText.trim(), cells[3].innerText.trim(),
      cells[4].innerText.trim(), cells[5].innerText.trim(),
      cells[6].innerText.trim(), cells[7].innerText.trim(),
      cells[8].innerText.trim().replace(/\n/g,' ')
    ]);
  });
  doc.autoTable({
    head: [['Receipt','Customer','Supplement','Category','Qty','Price','Total','Payment','Date & Time']],
    body: rows, startY: 26,
    styles: { fontSize: 8, cellPadding: 2 },
    headStyles: { fillColor: [245,158,11], textColor: 255, fontStyle: 'bold' },
    alternateRowStyles: { fillColor: [255,253,245] }
  });
  doc.save('supplement_sales_' + new Date().toISOString().split('T')[0] + '.pdf');
}

// Export CSV
function exportSalesCSV() {
  var rows = [['Receipt No','Customer','Supplement','Category','Qty','Price','Total','Payment','Date','Time']];
  document.querySelectorAll('#salesTable tbody tr').forEach(function(tr) {
    var cells = tr.querySelectorAll('td');
    if (cells.length >= 9) {
      var dateParts = cells[8].innerText.trim().split('\n');
      rows.push([
        cells[0].innerText.trim(), cells[1].innerText.trim(),
        cells[2].innerText.trim(), cells[3].innerText.trim(),
        cells[4].innerText.trim(), cells[5].innerText.trim(),
        cells[6].innerText.trim(), cells[7].innerText.trim(),
        dateParts[0] ? dateParts[0].trim() : '',
        dateParts[1] ? dateParts[1].trim() : ''
      ]);
    }
  });
  var csv = rows.map(function(r) {
    return r.map(function(c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(',');
  }).join('\n');
  var a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv'}));
  a.download = 'supplement_sales.csv';
  a.click();
}
</script>
SCRIPTS;
include 'footer.php';
?>