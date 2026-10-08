<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/database.php');
include_once('./lib/billing_policy.php');
include_once('./lib/billing_report.php');

$reportError = '';
$reportMessage = '';
$canManageFinance = mikhmonIsAdmin() || mikhmonIsFinance();
$canViewSettlement = $canManageFinance || mikhmonIsBiller();
$canViewRevenue = $canManageFinance || mikhmonIsMitra();
$canViewCommission = $canViewSettlement || $canViewRevenue;
$mitraUsers = mikhmonGetUsers('mitra', $session);
$billerUsers = mikhmonGetUsers('biller', $session);
$mitraNames = $billerNames = array();
foreach ($mitraUsers as $user) $mitraNames[(string) ($user['id'] ?? '')] = (string) ($user['name'] ?? $user['username'] ?? 'Mitra');
foreach ($billerUsers as $user) $billerNames[(string) ($user['id'] ?? '')] = (string) ($user['name'] ?? $user['username'] ?? 'Penagih');

$dateFromValue = trim((string) ($_GET['report_from'] ?? ''));
$dateToValue = trim((string) ($_GET['report_to'] ?? ''));
if ($dateFromValue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromValue)) { $reportError = 'Tanggal awal filter tidak valid.'; $dateFromValue = ''; }
if ($dateToValue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToValue)) { $reportError = 'Tanggal akhir filter tidak valid.'; $dateToValue = ''; }
$dateFrom = $dateFromValue !== '' ? strtotime($dateFromValue . ' 00:00:00') : 0;
$dateTo = $dateToValue !== '' ? strtotime($dateToValue . ' 23:59:59') : 0;
if ($dateFrom > 0 && $dateTo > 0 && $dateFrom > $dateTo) { $reportError = 'Tanggal awal tidak boleh melewati tanggal akhir.'; $dateFrom = $dateTo = 0; }

$selectedMitra = $canManageFinance ? trim((string) ($_GET['report_mitra'] ?? '')) : (mikhmonIsMitra() ? mikhmonUserId() : '');
$selectedBiller = $canManageFinance ? trim((string) ($_GET['report_biller'] ?? '')) : (mikhmonIsBiller() ? mikhmonUserId() : '');
if ($selectedMitra !== '' && $selectedMitra !== 'unassigned' && !isset($mitraNames[$selectedMitra])) $selectedMitra = '';
if ($selectedBiller !== '' && !isset($billerNames[$selectedBiller])) $selectedBiller = '';
$selectedService = in_array($_GET['report_service'] ?? '', array('hotspot', 'pppoe'), true) ? (string) $_GET['report_service'] : '';
$selectedMethod = in_array($_GET['report_method'] ?? '', array('manual', 'midtrans'), true) ? (string) $_GET['report_method'] : '';
$selectedStatus = in_array($_GET['report_status'] ?? '', array('draft', 'issued', 'overdue', 'isolated', 'paid', 'void'), true) ? (string) $_GET['report_status'] : '';
$availableReportPanels = array('receivable', 'payment', 'history');
if ($canViewSettlement) $availableReportPanels[] = 'settlement';
if ($canManageFinance) $availableReportPanels[] = 'cashflow';
$initialReportPanel = in_array($_GET['report_tab'] ?? '', $availableReportPanels, true) ? (string) $_GET['report_tab'] : '';
if ($initialReportPanel === '') $initialReportPanel = $selectedStatus === 'paid' ? 'payment' : (in_array($selectedStatus, array('draft', 'void'), true) ? 'history' : 'receivable');
$reportFilters = array(
  'date_from' => $dateFrom, 'date_to' => $dateTo, 'mitra_id' => $selectedMitra, 'biller_id' => $selectedBiller,
  'service' => $selectedService, 'method' => $selectedMethod, 'status' => $selectedStatus,
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['billing_report_action'])) {
  if (!$canManageFinance) $reportError = 'Peran Anda tidak dapat mengubah data keuangan.';
  elseif ($_POST['billing_report_action'] === 'add_entry') {
    $saved = mikhmonSaveFinanceEntry($session, array(
      'type' => $_POST['entry_type'] ?? '', 'amount' => $_POST['entry_amount'] ?? 0,
      'description' => $_POST['entry_description'] ?? '', 'date' => $_POST['entry_date'] ?? '',
      'created_by' => mikhmonUserName(),
    ));
    if ($saved === false) $reportError = 'Transaksi gagal disimpan. Periksa tanggal, keterangan, dan nominal.';
    else $reportMessage = 'Transaksi arus kas berhasil disimpan.';
  } elseif ($_POST['billing_report_action'] === 'settle_commission') {
    $invoiceId = (string) ($_POST['invoice_id'] ?? '');
    $invoice = array();
    foreach (mikhmonGetInvoices($session) as $candidate) if ((string) ($candidate['id'] ?? '') === $invoiceId) { $invoice = $candidate; break; }
    if (!$invoice || ($invoice['status'] ?? '') !== 'paid' || (float) ($invoice['biller_commission'] ?? 0) <= 0) $reportError = 'Komisi invoice tidak ditemukan.';
    elseif (!empty($invoice['commission_settled_at'])) $reportError = 'Komisi invoice ini sudah diselesaikan.';
    else {
      $invoice['commission_settled_at'] = time();
      $invoice['commission_settled_by'] = mikhmonUserName();
      if (mikhmonSaveInvoice($session, $invoice) === false) $reportError = 'Settlement komisi gagal disimpan.';
      else $reportMessage = 'Komisi berhasil ditandai sudah dibayar.';
    }
  }
}

$customersById = array();
foreach (mikhmonVisibleCustomers($session) as $customerRow) $customersById[(string) ($customerRow['id'] ?? '')] = $customerRow;
$allInvoices = mikhmonVisibleInvoices($session);
if (mikhmonIsBiller()) {
  $allInvoices = array_values(array_filter($allInvoices, function ($invoice) {
    return (string) ($invoice['paid_by_user_id'] ?? '') === mikhmonUserId() || (string) ($invoice['collection_biller_user_id'] ?? '') === mikhmonUserId();
  }));
}
$filteredInvoices = array();
$now = time();
foreach ($allInvoices as $invoice) {
  if (($invoice['kind'] ?? 'monthly') !== 'monthly') continue;
  $customerRow = $customersById[(string) ($invoice['customer_id'] ?? '')] ?? array();
  $prepared = mikhmonBillingReportPrepareInvoice($invoice, $customerRow, $reportFilters, $now);
  if ($prepared === false) continue;
  $prepared['_mitra_name'] = $prepared['_mitra_id'] !== '' ? ($mitraNames[$prepared['_mitra_id']] ?? 'Mitra tidak ditemukan') : 'Belum ditetapkan';
  $prepared['_biller_name'] = $prepared['_biller_id'] !== '' ? ($billerNames[$prepared['_biller_id']] ?? ($prepared['paid_by_name'] ?? 'Penagih tidak ditemukan')) : '-';
  $filteredInvoices[] = $prepared;
}

$receivables = $payments = $settlements = array();
$receivableTotal = $paidSubtotal = $paidTotal = $commissionTotal = $commissionOpen = $netRevenue = 0;
$overdueCount = 0;
foreach ($filteredInvoices as $invoice) {
  $effectiveStatus = $invoice['_effective_status'];
  if (in_array($effectiveStatus, array('issued', 'overdue', 'isolated'), true)) {
    $dueAt = mikhmonBillingDueTimestamp($invoice['due_date'] ?? '');
    $daysOverdue = $dueAt > 0 && $now > $dueAt ? (int) floor(($now - $dueAt) / 86400) + 1 : 0;
    $invoice['_days_overdue'] = $daysOverdue;
    $receivables[] = $invoice;
    $receivableTotal += (float) $invoice['_report_amount'];
    if (in_array($effectiveStatus, array('overdue', 'isolated'), true)) $overdueCount++;
  }
  if (($invoice['status'] ?? '') === 'paid') {
    $payments[] = $invoice;
    $paidSubtotal += (float) $invoice['_report_subtotal'];
    $paidTotal += (float) $invoice['_report_amount'];
    $commissionTotal += (float) $invoice['_report_commission'];
    $netRevenue += (float) $invoice['_report_net'];
    if ($canViewSettlement && (float) $invoice['_report_commission'] > 0) {
      $settlements[] = $invoice;
      if (empty($invoice['commission_settled_at'])) $commissionOpen += (float) $invoice['_report_commission'];
    }
  }
}
usort($receivables, function ($a, $b) { return ($b['_days_overdue'] ?? 0) <=> ($a['_days_overdue'] ?? 0); });
usort($payments, function ($a, $b) { return (int) ($b['paid_at'] ?? 0) <=> (int) ($a['paid_at'] ?? 0); });
usort($settlements, function ($a, $b) { return (int) ($b['paid_at'] ?? 0) <=> (int) ($a['paid_at'] ?? 0); });

$cashRows = array();
foreach ($payments as $invoice) $cashRows[] = array('date' => date('Y-m-d', (int) ($invoice['paid_at'] ?? time())), 'description' => 'Pembayaran ' . ($invoice['number'] ?? ''), 'income' => (float) $invoice['_report_amount'], 'expense' => 0);
foreach ($settlements as $invoice) if (!empty($invoice['commission_settled_at'])) $cashRows[] = array('date' => date('Y-m-d', (int) $invoice['commission_settled_at']), 'description' => 'Settlement komisi ' . ($invoice['number'] ?? ''), 'income' => 0, 'expense' => (float) $invoice['_report_commission']);
$hasInvoiceDimensionFilter = $selectedMitra !== '' || $selectedBiller !== '' || $selectedService !== '' || $selectedMethod !== '' || $selectedStatus !== '';
if ($canManageFinance && !$hasInvoiceDimensionFilter) foreach (mikhmonGetFinanceEntries($session) as $entry) {
  $entryAt = strtotime((string) ($entry['date'] ?? '') . ' 12:00:00');
  if (($dateFrom > 0 && $entryAt < $dateFrom) || ($dateTo > 0 && $entryAt > $dateTo)) continue;
  $cashRows[] = array('date' => (string) ($entry['date'] ?? ''), 'description' => (string) ($entry['description'] ?? ''), 'income' => ($entry['type'] ?? '') === 'income' ? (float) $entry['amount'] : 0, 'expense' => ($entry['type'] ?? '') === 'expense' ? (float) $entry['amount'] : 0);
}
usort($cashRows, function ($a, $b) { return strcmp($b['date'], $a['date']); });
$cashIncome = array_sum(array_column($cashRows, 'income'));
$cashExpense = array_sum(array_column($cashRows, 'expense'));
$money = function ($amount) use ($currency) { return $currency . ' ' . number_format((float) $amount, 0, ',', '.'); };
?>
<style>
  .billing-report-page :focus-visible{outline:2px solid #fff;outline-offset:2px;box-shadow:0 0 0 4px #000}.billing-report-filters{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:10px;align-items:end;margin-bottom:16px}.billing-report-filters label{display:block;font-weight:700}.billing-report-filters input,.billing-report-filters select,.billing-report-filters button,.billing-report-filters a{width:100%;min-height:44px;box-sizing:border-box}.billing-report-filter-actions{display:flex;gap:8px}.billing-report-filter-actions .btn{display:flex;align-items:center;justify-content:center;margin:0}.billing-report-filter-note{grid-column:1/-1;margin:0}.billing-report-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}.billing-report-summary>div{border:1px solid rgba(127,127,127,.45);border-radius:4px;padding:14px}.billing-report-summary small{display:block;margin-bottom:7px;font-weight:700}.billing-report-summary strong{font-size:22px;overflow-wrap:anywhere}.billing-report-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}.billing-report-tabs button{min-height:44px}.billing-report-panel[hidden]{display:none}.billing-report-toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}.billing-report-toolbar input{min-height:44px;min-width:220px;color:inherit;font-size:14px}.billing-report-toolbar input::placeholder{color:currentColor;opacity:.8}.billing-report-toolbar .btn{min-height:44px}.billing-cash-form{display:grid;grid-template-columns:150px 160px 180px minmax(220px,1fr) auto;gap:10px;align-items:end;margin-bottom:16px}.billing-cash-form label{display:block;font-weight:700}.billing-cash-form input,.billing-cash-form select,.billing-cash-form button{width:100%;min-height:44px;box-sizing:border-box}.billing-priority{font-weight:700}.billing-priority.is-high{text-decoration:underline;text-underline-offset:3px}.billing-empty{padding:24px;text-align:center}.billing-report-table td,.billing-report-table th{vertical-align:middle}.billing-table-hint{display:none;font-size:14px}
  @media(max-width:1100px){.billing-report-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.billing-cash-form{grid-template-columns:1fr 1fr}.billing-cash-form .cash-description,.billing-cash-form .cash-submit{grid-column:1/-1}}
  @media(max-width:620px){.billing-report-filters,.billing-cash-form{grid-template-columns:1fr}.billing-report-filter-actions{flex-direction:column}.billing-cash-form .cash-description,.billing-cash-form .cash-submit{grid-column:auto}.billing-report-tabs{display:grid;grid-template-columns:1fr 1fr}.billing-report-tabs button{width:100%}.billing-report-tabs button:last-child:nth-child(odd){grid-column:1/-1}.billing-report-toolbar{display:grid;grid-template-columns:1fr}.billing-report-toolbar input{width:100%;min-width:0}.billing-table-hint{display:block;margin:0 0 8px}}
</style>
<div class="billing-report-page">
  <?php if ($reportMessage !== ''): ?><div class="box bg-success" role="status"><i class="fa fa-check"></i> <?= htmlspecialchars($reportMessage, ENT_QUOTES); ?></div><?php endif; ?>
  <?php if ($reportError !== ''): ?><div class="box bg-danger" role="alert"><i class="fa fa-warning"></i> <?= htmlspecialchars($reportError, ENT_QUOTES); ?></div><?php endif; ?>
  <form class="card card-body billing-report-filters" method="get" action="./">
    <input type="hidden" name="billing" value="reports"><input type="hidden" name="session" value="<?= htmlspecialchars($session, ENT_QUOTES); ?>"><input id="billingReportTabInput" type="hidden" name="report_tab" value="<?= htmlspecialchars($initialReportPanel, ENT_QUOTES); ?>">
    <label>Dari Tanggal<input class="form-control" type="date" name="report_from" value="<?= htmlspecialchars($dateFromValue, ENT_QUOTES); ?>"></label>
    <label>Sampai Tanggal<input class="form-control" type="date" name="report_to" value="<?= htmlspecialchars($dateToValue, ENT_QUOTES); ?>"></label>
    <?php if ($canManageFinance): ?>
      <label>Mitra<select class="form-control" name="report_mitra"><option value="">Semua Mitra</option><option value="unassigned"<?= $selectedMitra === 'unassigned' ? ' selected' : ''; ?>>Belum ditetapkan</option><?php foreach ($mitraNames as $userId => $userName): ?><option value="<?= htmlspecialchars($userId, ENT_QUOTES); ?>"<?= $selectedMitra === $userId ? ' selected' : ''; ?>><?= htmlspecialchars($userName, ENT_QUOTES); ?></option><?php endforeach; ?></select></label>
      <label>Penagih<select class="form-control" name="report_biller"><option value="">Semua Penagih</option><?php foreach ($billerNames as $userId => $userName): ?><option value="<?= htmlspecialchars($userId, ENT_QUOTES); ?>"<?= $selectedBiller === $userId ? ' selected' : ''; ?>><?= htmlspecialchars($userName, ENT_QUOTES); ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <label>Layanan<select class="form-control" name="report_service"><option value="">Semua Layanan</option><option value="hotspot"<?= $selectedService === 'hotspot' ? ' selected' : ''; ?>>Hotspot</option><option value="pppoe"<?= $selectedService === 'pppoe' ? ' selected' : ''; ?>>PPPoE</option></select></label>
    <label>Metode Pembayaran<select class="form-control" name="report_method"><option value="">Semua Metode</option><option value="manual"<?= $selectedMethod === 'manual' ? ' selected' : ''; ?>>Tunai atau Manual</option><option value="midtrans"<?= $selectedMethod === 'midtrans' ? ' selected' : ''; ?>>Midtrans</option></select></label>
    <label>Status<select class="form-control" name="report_status"><option value="">Semua Status</option><?php foreach (array('draft'=>'Draft','issued'=>'Terbit','overdue'=>'Jatuh Tempo','isolated'=>'Isolir','paid'=>'Lunas','void'=>'Batal') as $statusValue => $statusLabel): ?><option value="<?= $statusValue; ?>"<?= $selectedStatus === $statusValue ? ' selected' : ''; ?>><?= $statusLabel; ?></option><?php endforeach; ?></select></label>
    <div class="billing-report-filter-actions"><button class="btn bg-primary" type="submit"><i class="fa fa-filter"></i> Terapkan</button><a class="btn bg-warning" href="./?billing=reports&amp;session=<?= rawurlencode($session); ?>"><i class="fa fa-refresh"></i> Reset</a></div>
    <p class="billing-report-filter-note"><small>Pendapatan memakai tanggal pembayaran. Piutang memakai tanggal jatuh tempo. Filter layanan membagi biaya invoice gabungan secara proporsional.</small></p>
  </form>
  <div class="billing-report-summary" aria-label="Ringkasan billing">
    <div><small>Piutang terbuka</small><strong><?= htmlspecialchars($money($receivableTotal), ENT_QUOTES); ?></strong></div>
    <?php if ($canViewRevenue): ?><div><small>Subtotal layanan</small><strong><?= htmlspecialchars($money($paidSubtotal), ENT_QUOTES); ?></strong></div><?php endif; ?>
    <div><small>Pembayaran pelanggan</small><strong><?= htmlspecialchars($money($paidTotal), ENT_QUOTES); ?></strong></div>
    <?php if ($canViewSettlement || $canViewRevenue): ?><div><small>Komisi penagihan</small><strong><?= htmlspecialchars($money($commissionTotal), ENT_QUOTES); ?></strong></div><?php endif; ?>
    <?php if ($canManageFinance): ?><div><small>Pendapatan bersih ISP</small><strong><?= htmlspecialchars($money($netRevenue), ENT_QUOTES); ?></strong></div><div><small>Komisi belum settlement</small><strong><?= htmlspecialchars($money($commissionOpen), ENT_QUOTES); ?></strong></div><?php elseif ($canViewSettlement): ?><div><small>Komisi belum settlement</small><strong><?= htmlspecialchars($money($commissionOpen), ENT_QUOTES); ?></strong></div><?php else: ?><div><small>Invoice lunas</small><strong><?= count($payments); ?></strong></div><div><small>Invoice prioritas</small><strong><?= (int) $overdueCount; ?></strong></div><?php endif; ?>
  </div>
  <div class="card"><div class="card-header"><h3><i class="fa fa-table"></i> Laporan Billing</h3></div><div class="card-body">
    <div class="billing-report-tabs" role="tablist" aria-label="Jenis laporan">
      <button id="billingTabReceivable" class="btn bg-primary" type="button" role="tab" aria-controls="billingPanelReceivable" aria-selected="true" data-panel="receivable">Piutang</button>
      <button id="billingTabPayment" class="btn" type="button" role="tab" aria-controls="billingPanelPayment" aria-selected="false" data-panel="payment">Pembayaran</button>
      <?php if ($canViewSettlement): ?><button id="billingTabSettlement" class="btn" type="button" role="tab" aria-controls="billingPanelSettlement" aria-selected="false" data-panel="settlement">Settlement</button><?php endif; ?>
      <button id="billingTabHistory" class="btn" type="button" role="tab" aria-controls="billingPanelHistory" aria-selected="false" data-panel="history">Riwayat Status</button>
      <?php if ($canManageFinance): ?><button id="billingTabCashflow" class="btn" type="button" role="tab" aria-controls="billingPanelCashflow" aria-selected="false" data-panel="cashflow">Arus Kas</button><?php endif; ?>
    </div>
    <div class="billing-report-toolbar"><input id="billingReportSearch" class="form-control" type="search" placeholder="Cari invoice, pelanggan, mitra, atau penagih" aria-label="Cari laporan"><button id="billingReportExport" class="btn bg-success" type="button"><i class="fa fa-file-excel-o"></i> Export CSV</button></div>
    <p class="billing-table-hint"><small>Geser tabel ke samping untuk melihat kolom lainnya.</small></p>
    <section id="billingPanelReceivable" class="billing-report-panel" data-report-panel="receivable" role="tabpanel" aria-labelledby="billingTabReceivable">
      <div class="overflow box-bordered"><table class="table table-bordered table-hover billing-report-table"><thead><tr><th>Prioritas</th><th>Invoice</th><th>Pelanggan</th><th>Mitra</th><th>Penagih</th><th>Jatuh Tempo</th><th>Status</th><?php if ($canViewCommission): ?><th class="text-right">Komisi Penagihan</th><?php endif; ?><th class="text-right">Piutang</th></tr></thead><tbody>
      <?php foreach ($receivables as $invoice): ?><tr class="billing-report-row"><td class="billing-priority <?= ($invoice['_days_overdue'] ?? 0) >= 7 ? 'is-high' : ''; ?>"><?= ($invoice['_days_overdue'] ?? 0) > 0 ? (int) $invoice['_days_overdue'] . ' hari' : 'Belum jatuh tempo'; ?></td><td><?= htmlspecialchars($invoice['number'] ?? '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['customer_name'] ?? '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['_mitra_name'], ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['_biller_name'], ENT_QUOTES); ?></td><td><?= htmlspecialchars(substr((string) ($invoice['due_date'] ?? '-'), 0, 10), ENT_QUOTES); ?></td><td><?= htmlspecialchars(mikhmonInvoiceStatusLabel($invoice['_effective_status']), ENT_QUOTES); ?></td><?php if ($canViewCommission): ?><td class="text-right"><?= htmlspecialchars($money($invoice['_report_commission']), ENT_QUOTES); ?></td><?php endif; ?><td class="text-right"><?= htmlspecialchars($money($invoice['_report_amount']), ENT_QUOTES); ?></td></tr><?php endforeach; ?>
      <?php if (!$receivables): ?><tr><td colspan="<?= $canViewCommission ? 9 : 8; ?>" class="billing-empty">Tidak ada piutang terbuka sesuai filter.</td></tr><?php endif; ?></tbody></table></div>
    </section>
    <section id="billingPanelPayment" class="billing-report-panel" data-report-panel="payment" role="tabpanel" aria-labelledby="billingTabPayment" hidden>
      <div class="overflow box-bordered"><table class="table table-bordered table-hover billing-report-table"><thead><tr><th>Tanggal</th><th>Invoice</th><th>Pelanggan</th><th>Mitra</th><th>Penagih</th><th>Metode</th><?php if ($canViewRevenue): ?><th class="text-right">Subtotal</th><?php endif; ?><?php if ($canViewCommission): ?><th class="text-right">Komisi</th><?php endif; ?><th class="text-right">Dibayar Pelanggan</th><?php if ($canViewRevenue): ?><th class="text-right">Pendapatan Bersih</th><?php endif; ?></tr></thead><tbody>
      <?php foreach ($payments as $invoice): ?><tr class="billing-report-row"><td><?= !empty($invoice['paid_at']) ? date('d/m/Y H:i', (int) $invoice['paid_at']) : '-'; ?></td><td><?= htmlspecialchars($invoice['number'] ?? '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['customer_name'] ?? '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['_mitra_name'], ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['_biller_name'], ENT_QUOTES); ?></td><td><?= $invoice['_payment_method'] === 'manual' ? 'Tunai atau Manual' : htmlspecialchars(strtoupper($invoice['_payment_method']), ENT_QUOTES); ?></td><?php if ($canViewRevenue): ?><td class="text-right"><?= htmlspecialchars($money($invoice['_report_subtotal']), ENT_QUOTES); ?></td><?php endif; ?><?php if ($canViewCommission): ?><td class="text-right"><?= htmlspecialchars($money($invoice['_report_commission']), ENT_QUOTES); ?></td><?php endif; ?><td class="text-right"><?= htmlspecialchars($money($invoice['_report_amount']), ENT_QUOTES); ?></td><?php if ($canViewRevenue): ?><td class="text-right"><?= htmlspecialchars($money($invoice['_report_net']), ENT_QUOTES); ?></td><?php endif; ?></tr><?php endforeach; ?>
      <?php if (!$payments): ?><tr><td colspan="<?= 7 + ($canViewRevenue ? 2 : 0) + ($canViewCommission ? 1 : 0); ?>" class="billing-empty">Belum ada pembayaran sesuai filter.</td></tr><?php endif; ?></tbody></table></div>
    </section>
    <?php if ($canViewSettlement): ?><section id="billingPanelSettlement" class="billing-report-panel" data-report-panel="settlement" role="tabpanel" aria-labelledby="billingTabSettlement" hidden>
      <div class="overflow box-bordered"><table class="table table-bordered table-hover billing-report-table"><thead><tr><th>Invoice</th><th>Mitra</th><th>Penagih</th><th>Tanggal pembayaran</th><th class="text-right">Komisi</th><th>Status</th><?php if ($canManageFinance): ?><th>Aksi</th><?php endif; ?></tr></thead><tbody>
      <?php foreach ($settlements as $invoice): ?><tr class="billing-report-row"><td><?= htmlspecialchars($invoice['number'] ?? '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['_mitra_name'], ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['_biller_name'], ENT_QUOTES); ?></td><td><?= !empty($invoice['paid_at']) ? date('d/m/Y H:i', (int) $invoice['paid_at']) : '-'; ?></td><td class="text-right"><?= htmlspecialchars($money($invoice['_report_commission']), ENT_QUOTES); ?></td><td><?= !empty($invoice['commission_settled_at']) ? 'Sudah dibayar ' . date('d/m/Y', (int) $invoice['commission_settled_at']) : 'Belum dibayar'; ?></td><?php if ($canManageFinance): ?><td><?php if (empty($invoice['commission_settled_at'])): ?><form method="post" onsubmit="return confirm('Tandai komisi ini sudah dibayar?');"><?= mikhmonCsrfField(); ?><input type="hidden" name="billing_report_action" value="settle_commission"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars($invoice['id'] ?? '', ENT_QUOTES); ?>"><button class="btn bg-success" type="submit">Bayar Komisi</button></form><?php else: ?>-<?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
      <?php if (!$settlements): ?><tr><td colspan="<?= $canManageFinance ? 7 : 6; ?>" class="billing-empty">Belum ada komisi sesuai filter.</td></tr><?php endif; ?></tbody></table></div>
    </section><?php endif; ?>
    <section id="billingPanelHistory" class="billing-report-panel" data-report-panel="history" role="tabpanel" aria-labelledby="billingTabHistory" hidden>
      <div class="overflow box-bordered"><table class="table table-bordered table-hover billing-report-table"><thead><tr><th>Dibuat</th><th>Invoice</th><th>Pelanggan</th><th>Mitra</th><th>Penagih</th><th>Jatuh Tempo</th><th>Status</th><th class="text-right">Total</th></tr></thead><tbody>
      <?php foreach (array_reverse($filteredInvoices) as $invoice): ?><tr class="billing-report-row"><td><?= !empty($invoice['created_at']) ? date('d/m/Y H:i', (int) $invoice['created_at']) : '-'; ?></td><td><?= htmlspecialchars($invoice['number'] ?? '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['customer_name'] ?? '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['_mitra_name'], ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['_biller_name'], ENT_QUOTES); ?></td><td><?= htmlspecialchars(substr((string) ($invoice['due_date'] ?? '-'), 0, 10), ENT_QUOTES); ?></td><td><?= htmlspecialchars(mikhmonInvoiceStatusLabel($invoice['_effective_status']), ENT_QUOTES); ?></td><td class="text-right"><?= htmlspecialchars($money($invoice['_report_amount']), ENT_QUOTES); ?></td></tr><?php endforeach; ?>
      <?php if (!$filteredInvoices): ?><tr><td colspan="8" class="billing-empty">Belum ada riwayat invoice sesuai filter.</td></tr><?php endif; ?></tbody></table></div>
    </section>
    <?php if ($canManageFinance): ?><section id="billingPanelCashflow" class="billing-report-panel" data-report-panel="cashflow" role="tabpanel" aria-labelledby="billingTabCashflow" hidden>
      <form class="billing-cash-form" method="post"><?= mikhmonCsrfField(); ?><input type="hidden" name="billing_report_action" value="add_entry">
        <label>Jenis<select class="form-control" name="entry_type"><option value="expense">Pengeluaran</option><option value="income">Pemasukan</option></select></label>
        <label>Nominal<input class="form-control" type="number" name="entry_amount" min="1" step="1" required></label>
        <label>Tanggal<input class="form-control" type="date" name="entry_date" value="<?= date('Y-m-d'); ?>" required></label>
        <label class="cash-description">Keterangan<input class="form-control" name="entry_description" maxlength="160" required placeholder="Contoh: listrik kantor"></label>
        <button class="btn bg-primary cash-submit" type="submit"><i class="fa fa-save"></i> Catat</button>
      </form>
      <div class="overflow box-bordered"><table class="table table-bordered table-hover billing-report-table"><thead><tr><th>Tanggal</th><th>Keterangan</th><th class="text-right">Masuk</th><th class="text-right">Keluar</th><th class="text-right">Saldo berjalan</th></tr></thead><tbody><?php $running = 0; foreach (array_reverse($cashRows) as $row): $running += $row['income'] - $row['expense']; ?><tr class="billing-report-row"><td><?= htmlspecialchars($row['date'], ENT_QUOTES); ?></td><td><?= htmlspecialchars($row['description'], ENT_QUOTES); ?></td><td class="text-right"><?= $row['income'] > 0 ? htmlspecialchars($money($row['income']), ENT_QUOTES) : '-'; ?></td><td class="text-right"><?= $row['expense'] > 0 ? htmlspecialchars($money($row['expense']), ENT_QUOTES) : '-'; ?></td><td class="text-right"><?= htmlspecialchars($money($running), ENT_QUOTES); ?></td></tr><?php endforeach; ?><?php if (!$cashRows): ?><tr><td colspan="5" class="billing-empty">Belum ada arus kas tercatat.</td></tr><?php endif; ?></tbody></table></div>
    </section><?php endif; ?>
  </div></div>
</div>
<script>
(function(){
  var tabs=Array.prototype.slice.call(document.querySelectorAll('.billing-report-tabs [role="tab"]'));
  var panels=document.querySelectorAll('.billing-report-panel');
  var search=document.getElementById('billingReportSearch');
  var exportButton=document.getElementById('billingReportExport');
  var tabInput=document.getElementById('billingReportTabInput');
  function filterRows(){
    var term=(search.value||'').toLowerCase();
    document.querySelectorAll('.billing-report-panel:not([hidden]) .billing-report-row').forEach(function(row){
      row.style.display=row.textContent.toLowerCase().indexOf(term)>-1?'':'none';
    });
  }
  function openPanel(name){
    tabs.forEach(function(tab){
      var active=tab.getAttribute('data-panel')===name;
      tab.setAttribute('aria-selected',active?'true':'false');
      tab.setAttribute('tabindex',active?'0':'-1');
      tab.classList.toggle('bg-primary',active);
    });
    panels.forEach(function(panel){panel.hidden=panel.getAttribute('data-report-panel')!==name;});
    if(tabInput)tabInput.value=name;
    filterRows();
  }
  function csvCell(value){return '"'+String(value).replace(/"/g,'""')+'"';}
  function exportActiveTable(){
    var panel=document.querySelector('.billing-report-panel:not([hidden])');
    var table=panel?panel.querySelector('table'):null;
    if(!table)return;
    var lines=[];
    table.querySelectorAll('tr').forEach(function(row){
      if(row.style.display==='none'||row.querySelector('.billing-empty'))return;
      lines.push(Array.prototype.map.call(row.querySelectorAll('th,td'),function(cell){return csvCell(cell.textContent.trim().replace(/\s+/g,' '));}).join(','));
    });
    if(lines.length<2){alert('Tidak ada data sesuai filter untuk diekspor.');return;}
    var blob=new Blob(["\uFEFF"+lines.join("\r\n")],{type:'text/csv;charset=utf-8'});
    var url=URL.createObjectURL(blob);
    var link=document.createElement('a');
    link.href=url;
    link.download='laporan-billing-'+panel.getAttribute('data-report-panel')+'-<?= date('Ymd-His'); ?>.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }
  tabs.forEach(function(tab,index){
    tab.addEventListener('click',function(){openPanel(tab.getAttribute('data-panel'));});
    tab.addEventListener('keydown',function(event){
      var next=index;
      if(event.key==='ArrowRight')next=(index+1)%tabs.length;
      else if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;
      else if(event.key==='Home')next=0;
      else if(event.key==='End')next=tabs.length-1;
      else return;
      event.preventDefault();tabs[next].focus();openPanel(tabs[next].getAttribute('data-panel'));
    });
  });
  search.addEventListener('input',filterRows);
  exportButton.addEventListener('click',exportActiveTable);
  openPanel(<?= json_encode($initialReportPanel); ?>);
})();
</script>
