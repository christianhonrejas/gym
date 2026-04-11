<?php
require_once 'auth.php';
$pageTitle = 'Walk-in Members';

// Handle Add Walk-in POST
$successMsg = $errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

// Delete walk-in record
if ($_POST['action'] === 'delete_walkin' && in_array($_SESSION['admin_role'], ['superadmin', 'admin'])) {
    $mid = trim($_POST['member_id'] ?? '');
    if ($mid) {
        $tables = ['attendance_logs', 'walkins', 'payments', 'members'];
        foreach ($tables as $tbl) {
            $d = $conn->prepare("DELETE FROM `$tbl` WHERE member_id = ?");
            $d->bind_param("s", $mid);
            $d->execute();
            $d->close();
        }
        $successMsg = "Walk-in record deleted successfully.";
    }
}

if ($_POST['action'] === 'add_walkin') {
    $name = trim($_POST['name'] ?? '');
    $payment = floatval($_POST['payment'] ?? 0);
    
    if (!$name || !$payment) {
        $errorMsg = 'Please fill in all required fields.';
    } else {
        $today = date('Y-m-d');
        $countToday = (int)$conn->query("SELECT COUNT(*) as c FROM members WHERE member_type = 'walkin' AND DATE(created_at) = '$today'")->fetch_assoc()['c'];
        $seq = str_pad($countToday + 1, 3, '0', STR_PAD_LEFT);
        $memberId = 'W-' . date('Ymd') . '-' . $seq;

            // 1. Insert member
            $stmt = $conn->prepare("INSERT INTO members (member_id, name, member_type, status) VALUES (?, ?, 'walkin', 'active')");
            if (!$stmt) { $errorMsg = 'DB error: ' . $conn->error; }
            else {
                $stmt->bind_param("ss", $memberId, $name);
                if ($stmt->execute()) {
                    $stmt->close();

                    // 2. Insert walkins record
                    $stmt2 = $conn->prepare("INSERT INTO walkins (member_id, payment_amount, visit_date) VALUES (?, ?, ?)");
                    $stmt2->bind_param("sds", $memberId, $payment, $today);
                    $stmt2->execute();
                    $stmt2->close();

                    // 3. Insert into payments table
                    $stmt3 = $conn->prepare("INSERT INTO payments (member_id, member_type, amount, payment_date) VALUES (?, 'walkin', ?, ?)");
                    $stmt3->bind_param("sds", $memberId, $payment, $today);
                    $stmt3->execute();
                    $stmt3->close();

                    // 4. Auto check-in attendance
                    $now = date('Y-m-d H:i:s');
                    $stmt4 = $conn->prepare("INSERT INTO attendance_logs (member_id, member_name, member_type, time_in, access_result, status) VALUES (?, ?, 'walkin', ?, 'granted', 'active')");
                    $stmt4->bind_param("sss", $memberId, $name, $now);
                    $stmt4->execute();
                    $stmt4->close();

                    $successMsg = "Walk-in member <strong>" . htmlspecialchars($name) . "</strong> registered and checked in successfully at <strong>" . date('h:i A') . "</strong>.";
                } else {
                    $stmt->close();
                    $errorMsg = 'Failed to add member: ' . $conn->error;
                }
            }
    }
} // end add_walkin
} // end POST

// Fetch walk-in prices
$prices = $conn->query("SELECT * FROM price_settings WHERE type = 'walkin' AND is_active = 1 ORDER BY price ASC");

// Fetch today's walk-ins
$todayWalkins = $conn->query("
    SELECT m.*, w.payment_amount, w.visit_date
    FROM members m
    LEFT JOIN walkins w ON m.member_id = w.member_id AND w.visit_date = CURDATE()
    WHERE m.member_type = 'walkin' AND DATE(m.created_at) = CURDATE()
    ORDER BY m.created_at DESC
");

// All walk-in history
$allWalkins = $conn->query("
    SELECT m.*, w.payment_amount, w.visit_date, a.time_in
    FROM members m
    LEFT JOIN walkins w ON m.member_id = w.member_id
    LEFT JOIN attendance_logs a ON m.member_id = a.member_id AND DATE(a.time_in) = w.visit_date
    WHERE m.member_type = 'walkin'
    ORDER BY m.created_at DESC
    LIMIT 100
");

include 'header.php';
?>

<div class="page-header mb-4">
  <div>
    <h4 class="fw-800 mb-1" style="font-family:'Barlow Condensed',sans-serif;font-size:26px;font-weight:800;">Walk-in Members</h4>
    <p class="text-muted mb-0" style="font-size:13px;">Record walk-in payments</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <div class="stat-card py-2 px-3" style="min-width:0;">
      <div class="card-label" style="font-size:11px;">Today's Walk-ins</div>
      <div class="card-value" style="font-size:20px;"><?= $todayWalkins->num_rows ?></div>
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

<!-- Add Walk-in Form -->
<div class="section-card mb-4">
  <div class="section-card-header">
    <span class="section-card-title"><i class="fas fa-plus-circle me-2" style="color:var(--primary)"></i>Add Walk-in Member</span>
  </div>
  <div class="section-card-body">
    <form method="POST" action="">
      <input type="hidden" name="action" value="add_walkin">
      <div class="row g-3 align-items-end">
        <div class="col-md-5">
          <label class="form-label">Visitor Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="Enter full name" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Select Payment <span class="text-danger">*</span></label>
          <select name="payment" class="form-select" required>
            <option value="">-- Select Payment --</option>
            <?php while($p = $prices->fetch_assoc()): ?>
            <option value="<?= $p['price'] ?>">₱<?= number_format($p['price'], 2) ?> – <?= htmlspecialchars($p['label']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Payment Date</label>
          <input type="text" class="form-control" value="<?= date('M d, Y') ?>" readonly style="background:#f8faff;">
          <button type="submit" class="btn-primary-custom w-100 mt-2" style="justify-content:center;padding:10px;">
            <i class="fas fa-user-plus"></i> Add Walk-in
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Today's Walk-ins Table -->
<div class="section-card mb-4">
  <div class="section-card-header">
    <span class="section-card-title"><i class="fas fa-calendar-day me-2" style="color:var(--success)"></i>Today's Walk-ins – <?= date('M d, Y') ?></span>
    <span class="badge-active"><?= $todayWalkins->num_rows ?> today</span>
  </div>
  <div class="section-card-body p-0">
    <div class="table-responsive">     <table class="table mb-0">
        <thead>
          <tr>
            <th style="width:50px;">No.</th>
            <th>Name</th>
            <th>Payment</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $todayWalkins->data_seek(0);
          $no = 1;
          if ($todayWalkins->num_rows > 0):
          while($row = $todayWalkins->fetch_assoc()): ?>
          <tr>
            <td style="color:var(--text-muted);font-weight:600;font-size:13px;"><?= $no++ ?></td>
            <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
            <td><span style="color:var(--success);font-weight:700;">₱<?= number_format($row['payment_amount'], 2) ?></span></td>
            <td><?= date('F d, Y', strtotime($row['visit_date'])) ?></td>
            <td>
              <?php if (in_array($_SESSION['admin_role'], ['superadmin','admin'])): ?>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this walk-in record permanently?')">
                <input type="hidden" name="action" value="delete_walkin">
                <input type="hidden" name="member_id" value="<?= htmlspecialchars($row['member_id']) ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill" style="font-size:11px;"><i class="fas fa-trash me-1"></i>Delete</button>
              </form>
              <?php else: ?>
              <span style="font-size:12px;color:var(--text-muted);">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
          <?php else: ?>
          <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-inbox me-2"></i>No walk-in members today yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<div class="section-card">
  <div class="section-card-header">
    <span class="section-card-title"><i class="fas fa-history me-2" style="color:var(--text-muted)"></i>Walk-in History</span>
  </div>
  <div class="section-card-body p-0">
    <div class="table-responsive">     <table class="table mb-0" id="walkinHistoryTable">
        <thead>
          <tr>
            <th style="width:50px;">No.</th>
            <th>Name</th>
            <th>Payment</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php while($row = $allWalkins->fetch_assoc()): ?>
          <tr>
            <td style="color:var(--text-muted);font-weight:600;font-size:13px;"></td>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td>₱<?= number_format($row['payment_amount'] ?? 0, 2) ?></td>
            <td><?= $row['visit_date'] ? date('F d, Y', strtotime($row['visit_date'])) : date('F d, Y', strtotime($row['created_at'])) ?></td>
            <td>
              <?php if (in_array($_SESSION['admin_role'], ['superadmin','admin'])): ?>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this walk-in record permanently?')">
                <input type="hidden" name="action" value="delete_walkin">
                <input type="hidden" name="member_id" value="<?= htmlspecialchars($row['member_id']) ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill" style="font-size:11px;"><i class="fas fa-trash me-1"></i>Delete</button>
              </form>
              <?php else: ?>
              <span style="font-size:12px;color:var(--text-muted);">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraScripts = '
<script>
$(document).ready(function() {
  var table = $("#walkinHistoryTable").DataTable({
    responsive: true,
    order: [[3,"desc"]],
    pageLength: -1, lengthMenu: [[10,25,50,100,-1],["10","25","50","100","All"]],
    language: { emptyTable: "No walk-in history found" },
    columnDefs: [{ orderable: false, targets: 0 }],
    drawCallback: function() {
      var info = this.api().page.info();
      $("#walkinHistoryTable tbody tr").each(function(i) {
        $(this).find("td:first").html(info.start + i + 1);
      });
    }
  });
});
</script>';
include 'footer.php';
?>
