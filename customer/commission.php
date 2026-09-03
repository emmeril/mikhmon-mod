<?php
error_reporting(0);

if (!isset($_SESSION['mikhmon']) || !mikhmonIsBiller()) {
  header('Location:./?billing=1&session=' . rawurlencode($session));
  exit;
}

function billerCommissionMonthLabel($month) {
  $names = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');
  $parts = explode('-', $month);
  $monthNumber = isset($parts[1]) ? (int) $parts[1] : 0;
  return isset($names[$monthNumber]) ? $names[$monthNumber] . ' ' . $parts[0] : $month;
}

$customersById = array();
foreach (mikhmonGetCustomers($session) as $customer) {
  if (isset($customer['id'])) $customersById[(string) $customer['id']] = $customer;
}

$commissionRows = array();
$availableMonths = array(date('Y-m') => true);
foreach (mikhmonGetInvoices($session) as $invoice) {
  if (($invoice['status'] ?? '') !== 'paid' || (string) ($invoice['paid_by_user_id'] ?? '') !== mikhmonUserId()) continue;
  $paidAt = isset($invoice['paid_at']) ? (int) $invoice['paid_at'] : 0;
  if ($paidAt > 0) $availableMonths[date('Y-m', $paidAt)] = true;
  $commissionRows[] = $invoice;
}
usort($commissionRows, function ($left, $right) {
  return (int) ($right['paid_at'] ?? 0) <=> (int) ($left['paid_at'] ?? 0);
});
krsort($availableMonths);

$selectedMonth = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['month']) ? (string) $_GET['month'] : date('Y-m');
$totalAmount = 0;
foreach ($commissionRows as $invoice) {
  if (empty($invoice['paid_at']) || date('Y-m', (int) $invoice['paid_at']) !== $selectedMonth) continue;
  $commission = isset($invoice['biller_commission']) ? (float) $invoice['biller_commission'] : mikhmonBillerCommissionAmount();
  $totalAmount += $commission;
}
?>
<style>
  .commission-toolbar{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:5px 0 10px}
  .commission-toolbar .form-control{height:32px;margin:0}
  @media(max-width:750px){.commission-toolbar{grid-template-columns:1fr}}
</style>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-money"></i> Komisi Saya</h3></div>
  <div class="card-body">
    <div class="commission-toolbar">
      <input id="commissionSearch" type="text" class="form-control" placeholder="Cari pelanggan, username, atau invoice">
      <select id="commissionMonth" class="form-control" onchange="location='./?commission=1&session=<?= rawurlencode($session); ?>&month='+encodeURIComponent(this.value)"><?php foreach (array_keys($availableMonths) as $month): ?><option value="<?= htmlspecialchars($month, ENT_QUOTES); ?>"<?= $month === $selectedMonth ? ' selected' : ''; ?>><?= htmlspecialchars(billerCommissionMonthLabel($month), ENT_QUOTES); ?></option><?php endforeach; ?></select>
    </div>
    <div class="overflow box-bordered" style="max-height:75vh"><table id="commissionTable" class="table table-bordered table-hover text-nowrap">
      <thead><tr><th>No</th><th>Tanggal Bayar</th><th>Pelanggan</th><th>Layanan</th><th>Username</th><th>Invoice</th><th>Jumlah Tagihan</th><th>Komisi</th></tr></thead>
      <tbody>
      <?php $visibleIndex = 0; foreach ($commissionRows as $invoice):
        if (empty($invoice['paid_at']) || date('Y-m', (int) $invoice['paid_at']) !== $selectedMonth) continue;
        $visibleIndex++;
        $customer = isset($invoice['customer_id'], $customersById[(string) $invoice['customer_id']]) ? $customersById[(string) $invoice['customer_id']] : array();
        $commission = isset($invoice['biller_commission']) ? (float) $invoice['biller_commission'] : mikhmonBillerCommissionAmount();
        $invoiceServices = isset($invoice['services']) && is_array($invoice['services']) ? $invoice['services'] : array();
        $invoiceServiceLabel = $invoiceServices ? count($invoiceServices) . ' layanan' : ($invoice['service'] ?? ($customer['service'] ?? '-'));
        $invoiceUsernameLabel = $invoiceServices ? implode(', ', array_map(function ($service) { return $service['username'] ?? ''; }, $invoiceServices)) : ($invoice['username'] ?? ($customer['username'] ?? '-'));
      ?>
        <tr class="commission-row"><td><?= $visibleIndex; ?></td><td><?= htmlspecialchars(date('d-m-Y H:i', (int) $invoice['paid_at']), ENT_QUOTES); ?></td><td><?= htmlspecialchars($customer['name'] ?? ($invoice['customer_name'] ?? '-'), ENT_QUOTES); ?></td><td><?= strtoupper(htmlspecialchars($invoiceServiceLabel, ENT_QUOTES)); ?></td><td><?= htmlspecialchars($invoiceUsernameLabel, ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['number'] ?? '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($currency . ' ' . number_format((float) ($invoice['amount'] ?? 0), 0, ',', '.'), ENT_QUOTES); ?></td><td class="text-success"><strong><?= htmlspecialchars($currency . ' ' . number_format($commission, 0, ',', '.'), ENT_QUOTES); ?></strong></td></tr>
      <?php endforeach; ?>
      <?php if ($visibleIndex === 0): ?><tr id="commissionEmpty"><td colspan="8" class="text-center">Belum ada komisi pada periode ini.</td></tr><?php endif; ?>
      <tr id="commissionNoResults" style="display:none"><td colspan="8" class="text-center">Data komisi tidak ditemukan.</td></tr>
      </tbody>
      <tfoot><tr><th colspan="7" class="text-right">Total</th><th class="text-success"><strong><?= htmlspecialchars($currency . ' ' . number_format($totalAmount, 0, ',', '.'), ENT_QUOTES); ?></strong></th></tr></tfoot>
    </table></div>
  </div>
</div></div></div>
<script>
$(function() {
  $('#commissionSearch').on('keyup', function() {
    var search = $(this).val().toLowerCase();
    var visible = 0;
    $('.commission-row').each(function() {
      var show = $(this).text().toLowerCase().indexOf(search) !== -1;
      $(this).toggle(show);
      if (show) visible++;
    });
    $('#commissionNoResults').toggle(visible === 0 && $('.commission-row').length > 0);
  });
});
</script>
