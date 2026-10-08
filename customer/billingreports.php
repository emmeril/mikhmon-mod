<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/database.php');
include_once('./lib/billing_policy.php');
include_once('./lib/billing_report.php');
include_once('./lib/revenue_report.php');

$reportError = $reportMessage = '';
$canManageFinance = mikhmonIsAdmin() || mikhmonIsFinance();
$canViewRevenue = $canManageFinance || mikhmonIsMitra();
$canViewSettlement = $canManageFinance || mikhmonIsBiller();
$mitraNames = $billerNames = $collectorNames = array();
foreach (mikhmonGetUsers('mitra', $session) as $user) {
  $id = (string) ($user['id'] ?? '');
  $mitraNames[$id] = (string) ($user['name'] ?? $user['username'] ?? 'Mitra');
  $collectorNames[$id] = $mitraNames[$id];
}
foreach (mikhmonGetUsers('biller', $session) as $user) {
  $id = (string) ($user['id'] ?? '');
  $billerNames[$id] = (string) ($user['name'] ?? $user['username'] ?? 'Biller');
  $collectorNames[$id] = $billerNames[$id];
}

$dateFromValue = trim((string) ($_GET['report_from'] ?? ''));
$dateToValue = trim((string) ($_GET['report_to'] ?? ''));
if ($dateFromValue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromValue)) { $reportError = 'Tanggal awal filter tidak valid.'; $dateFromValue = ''; }
if ($dateToValue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToValue)) { $reportError = 'Tanggal akhir filter tidak valid.'; $dateToValue = ''; }
$dateFrom = $dateFromValue !== '' ? strtotime($dateFromValue . ' 00:00:00') : 0;
$dateTo = $dateToValue !== '' ? strtotime($dateToValue . ' 23:59:59') : 0;
if ($dateFrom && $dateTo && $dateFrom > $dateTo) { $reportError = 'Tanggal awal tidak boleh melewati tanggal akhir.'; $dateFrom = $dateTo = 0; }
$selectedMitra = $canManageFinance ? trim((string) ($_GET['report_mitra'] ?? '')) : (mikhmonIsMitra() ? mikhmonUserId() : '');
$selectedCollector = $canManageFinance ? trim((string) ($_GET['report_collector'] ?? $_GET['report_biller'] ?? '')) : (mikhmonIsBiller() ? mikhmonUserId() : '');
if ($selectedMitra !== '' && $selectedMitra !== 'unassigned' && !isset($mitraNames[$selectedMitra])) $selectedMitra = '';
if ($selectedCollector !== '' && !isset($collectorNames[$selectedCollector])) $selectedCollector = '';
$selectedSource = in_array($_GET['report_source'] ?? '', array('customer', 'voucher'), true) ? (string) $_GET['report_source'] : '';
$selectedMethod = in_array($_GET['report_method'] ?? '', array('manual', 'direct'), true) ? (string) $_GET['report_method'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revenue_report_action'])) {
  if (!mikhmonValidCsrf($_POST['_csrf'] ?? '')) $reportError = 'Sesi formulir tidak valid. Muat ulang halaman.';
  elseif ($_POST['revenue_report_action'] === 'add_deposit') {
    if (!$canManageFinance) $reportError = 'Hanya Administrator atau Keuangan yang dapat mencatat setoran.';
    else {
      $saved = mikhmonSaveRevenueDeposit($session, array(
        'collector_user_id' => $_POST['collector_user_id'] ?? '', 'amount' => $_POST['deposit_amount'] ?? 0,
        'date' => $_POST['deposit_date'] ?? '', 'method' => $_POST['deposit_method'] ?? '',
        'reference' => $_POST['deposit_reference'] ?? '', 'note' => $_POST['deposit_note'] ?? '', 'created_by' => mikhmonUserName(),
      ));
      $saved === false ? $reportError = 'Setoran gagal disimpan. Periksa petugas, tanggal, dan nominal.' : $reportMessage = 'Setoran berhasil dicatat.';
    }
  } elseif ($_POST['revenue_report_action'] === 'settle_commission') {
    if (!$canManageFinance) $reportError = 'Hanya Administrator atau Keuangan yang dapat membayar komisi.';
    else {
      $invoice = array();
      foreach (mikhmonGetInvoices($session) as $candidate) if ((string) ($candidate['id'] ?? '') === (string) ($_POST['invoice_id'] ?? '')) { $invoice = $candidate; break; }
      if (!$invoice || ($invoice['status'] ?? '') !== 'paid' || (float) ($invoice['biller_commission'] ?? 0) <= 0) $reportError = 'Komisi invoice tidak ditemukan.';
      elseif (!empty($invoice['commission_settled_at'])) $reportError = 'Komisi invoice ini sudah dibayar.';
      else {
        $invoice['commission_settled_at'] = time(); $invoice['commission_settled_by'] = mikhmonUserName();
        mikhmonSaveInvoice($session, $invoice) === false ? $reportError = 'Pembayaran komisi gagal disimpan.' : $reportMessage = 'Komisi berhasil ditandai sudah dibayar.';
      }
    }
  }
}

$profiles = isset($billingReportHotspotProfiles) && is_array($billingReportHotspotProfiles) ? $billingReportHotspotProfiles : array();
if (!$profiles && isset($API) && is_object($API) && method_exists($API, 'comm')) {
  $response = $API->comm('/ip/hotspot/user/profile/print');
  if (is_array($response) && !isset($response['!trap']) && !isset($response['!fatal'])) $profiles = $response;
}
$allTransactions = mikhmonRevenueTransactions($session, $profiles);
$filters = array('date_from'=>$dateFrom,'date_to'=>$dateTo,'owner_user_id'=>$selectedMitra,'collector_user_id'=>$selectedCollector,'source'=>$selectedSource,'method'=>$selectedMethod);
if (mikhmonIsMitra()) $filters['owner_user_id'] = mikhmonUserId();
if (mikhmonIsBiller()) $filters['collector_user_id'] = mikhmonUserId();
$transactions = ($canViewRevenue || mikhmonIsBiller()) ? mikhmonRevenueFilterTransactions($allTransactions, $filters) : array();
$totals = mikhmonRevenueTotals($transactions);
$partnerSummaries = mikhmonRevenuePartnerSummaries($transactions, $mitraNames);
$allDeposits = mikhmonGetRevenueDeposits($session);
$visibleDeposits = $allDeposits;
if (mikhmonIsMitra() || mikhmonIsBiller()) $visibleDeposits = mikhmonGetRevenueDeposits($session, mikhmonUserId());
elseif ($selectedCollector !== '') $visibleDeposits = mikhmonGetRevenueDeposits($session, $selectedCollector);
usort($visibleDeposits, function ($a, $b) { return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')); });

$collectors = $collectorNames;
if (mikhmonIsMitra() || mikhmonIsBiller()) $collectors = array(mikhmonUserId() => mikhmonUserName());
elseif ($selectedCollector !== '' && isset($collectorNames[$selectedCollector])) $collectors = array($selectedCollector => $collectorNames[$selectedCollector]);
$collectorSummaries = array();
foreach ($collectors as $collectorId => $collectorName) {
  $user = mikhmonFindUser($collectorId);
  $summary = mikhmonRevenueCollectorSummary($allTransactions, $allDeposits, $collectorId);
  if ($summary['manual'] <= 0 && $summary['deposited'] <= 0 && $summary['commission'] <= 0) continue;
  $summary['user_id']=$collectorId; $summary['name']=$collectorName; $summary['role']=($user['role'] ?? '') === 'biller' ? 'Biller' : 'Mitra';
  $collectorSummaries[] = $summary;
}
$outstandingTotal = $commissionOpenTotal = 0;
foreach ($collectorSummaries as $summary) { $outstandingTotal += $summary['outstanding']; $commissionOpenTotal += $summary['commission_open']; }

$customers = array();
foreach (mikhmonVisibleCustomers($session) as $customer) $customers[(string) ($customer['id'] ?? '')] = $customer;
$invoices = mikhmonVisibleInvoices($session);
if (mikhmonIsBiller()) $invoices = array_values(array_filter($invoices, function ($invoice) {
  return (string) ($invoice['paid_by_user_id'] ?? '') === mikhmonUserId() || (string) ($invoice['collection_biller_user_id'] ?? '') === mikhmonUserId();
}));
$receivables = array(); $receivableTotal = 0; $now = time();
foreach ($invoices as $invoice) {
  if (($invoice['kind'] ?? 'monthly') !== 'monthly') continue;
  $customer = $customers[(string) ($invoice['customer_id'] ?? '')] ?? array();
  $prepared = mikhmonBillingReportPrepareInvoice($invoice, $customer, array('date_from'=>$dateFrom,'date_to'=>$dateTo,'mitra_id'=>$selectedMitra,'biller_id'=>$selectedCollector,'service'=>'','method'=>'','status'=>''), $now);
  if ($prepared === false || !in_array($prepared['_effective_status'], array('issued','overdue','isolated'), true)) continue;
  $dueAt = mikhmonBillingDueTimestamp($prepared['due_date'] ?? '');
  $prepared['_days_overdue'] = $dueAt && $now > $dueAt ? (int) floor(($now-$dueAt)/86400)+1 : 0;
  $prepared['_mitra_name'] = $prepared['_mitra_id'] !== '' ? ($mitraNames[$prepared['_mitra_id']] ?? 'Mitra tidak ditemukan') : 'Belum ditetapkan';
  $prepared['_collector_name'] = $prepared['_biller_id'] !== '' ? ($collectorNames[$prepared['_biller_id']] ?? 'Petugas tidak ditemukan') : '-';
  $receivables[] = $prepared; $receivableTotal += $prepared['_report_amount'];
}
usort($receivables, function ($a, $b) { return (int) ($b['_days_overdue'] ?? 0) <=> (int) ($a['_days_overdue'] ?? 0); });
$commissionRows = array_values(array_filter($transactions, function ($row) { return (float) ($row['commission'] ?? 0) > 0; }));

$panels = array('receivable');
if ($canViewRevenue || mikhmonIsBiller()) array_unshift($panels, 'transactions');
if ($canManageFinance) $panels[] = 'partners';
if ($canManageFinance || mikhmonIsMitra() || mikhmonIsBiller()) $panels[] = 'deposits';
if ($canViewSettlement) $panels[] = 'commission';
$initialPanel = in_array($_GET['report_tab'] ?? '', $panels, true) ? (string) $_GET['report_tab'] : (($canViewRevenue || mikhmonIsBiller()) ? 'transactions' : 'receivable');
$money = function ($amount) use ($currency) { $label=trim((string)$currency) ?: 'Rp'; $rp=in_array(strtolower(rtrim($label,'.')),array('rp','idr'),true); return $label.' '.number_format((float)$amount,$rp?0:2,$rp?',':'.',$rp?'.':','); };
?>
<style>
.revenue-report :focus-visible{outline:2px solid #fff;outline-offset:2px;box-shadow:0 0 0 4px #000}.revenue-filters,.revenue-form{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:10px;align-items:end;margin-bottom:16px}.revenue-filters label,.revenue-form label{display:block;font-weight:700}.revenue-filters input,.revenue-filters select,.revenue-filters button,.revenue-filters a,.revenue-form input,.revenue-form select,.revenue-form button{width:100%;min-height:44px;box-sizing:border-box}.revenue-filter-actions{display:flex;gap:8px}.revenue-filter-actions .btn{display:flex;align-items:center;justify-content:center;margin:0}.revenue-filter-note{grid-column:1/-1;margin:0}.revenue-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}.revenue-summary>div{border:1px solid rgba(127,127,127,.45);border-radius:4px;padding:14px}.revenue-summary small{display:block;margin-bottom:7px;font-weight:700}.revenue-summary strong{font-size:22px;overflow-wrap:anywhere}.revenue-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}.revenue-tabs button,.revenue-toolbar .btn{min-height:44px}.revenue-panel[hidden]{display:none}.revenue-toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}.revenue-toolbar input{min-height:44px;min-width:220px;color:inherit;font-size:14px}.revenue-toolbar input::placeholder{color:currentColor;opacity:.8}.revenue-form .wide{grid-column:span 2}.revenue-empty{padding:24px;text-align:center}.revenue-table td,.revenue-table th{vertical-align:middle}.revenue-priority,.revenue-status-open{font-weight:700}.revenue-priority.is-high,.revenue-status-open{text-decoration:underline;text-underline-offset:3px}.revenue-table-hint{display:none}
@media(max-width:1100px){.revenue-filters,.revenue-form{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.revenue-filters,.revenue-form{grid-template-columns:1fr}.revenue-filter-actions{flex-direction:column}.revenue-form .wide{grid-column:auto}.revenue-tabs{display:grid;grid-template-columns:1fr 1fr}.revenue-tabs button{width:100%;margin:0}.revenue-tabs button:last-child:nth-child(odd){grid-column:1/-1}.revenue-toolbar{display:grid;grid-template-columns:1fr}.revenue-toolbar input{width:100%;min-width:0}.revenue-table-hint{display:block;margin:0 0 8px}}
</style>
<div class="revenue-report">
<?php if ($reportMessage !== ''): ?><div class="box bg-success" role="status"><i class="fa fa-check"></i> <?= htmlspecialchars($reportMessage,ENT_QUOTES); ?></div><?php endif; ?>
<?php if ($reportError !== ''): ?><div class="box bg-danger" role="alert"><i class="fa fa-warning"></i> <?= htmlspecialchars($reportError,ENT_QUOTES); ?></div><?php endif; ?>
<form class="card card-body revenue-filters" method="get" action="./"><input type="hidden" name="billing" value="reports"><input type="hidden" name="session" value="<?= htmlspecialchars($session,ENT_QUOTES); ?>"><input id="revenueReportTabInput" type="hidden" name="report_tab" value="<?= htmlspecialchars($initialPanel,ENT_QUOTES); ?>">
<label>Dari Tanggal<input class="form-control" type="date" name="report_from" value="<?= htmlspecialchars($dateFromValue,ENT_QUOTES); ?>"></label><label>Sampai Tanggal<input class="form-control" type="date" name="report_to" value="<?= htmlspecialchars($dateToValue,ENT_QUOTES); ?>"></label>
<?php if ($canManageFinance): ?><label>Mitra<select class="form-control" name="report_mitra"><option value="">Semua Mitra</option><option value="unassigned"<?= $selectedMitra==='unassigned'?' selected':''; ?>>Belum ditetapkan</option><?php foreach($mitraNames as $id=>$name): ?><option value="<?= htmlspecialchars($id,ENT_QUOTES); ?>"<?= $selectedMitra===$id?' selected':''; ?>><?= htmlspecialchars($name,ENT_QUOTES); ?></option><?php endforeach; ?></select></label><label>Penerima Tunai<select class="form-control" name="report_collector"><option value="">Semua Petugas</option><?php foreach($collectorNames as $id=>$name): ?><option value="<?= htmlspecialchars($id,ENT_QUOTES); ?>"<?= $selectedCollector===$id?' selected':''; ?>><?= htmlspecialchars($name,ENT_QUOTES); ?></option><?php endforeach; ?></select></label><?php endif; ?>
<?php if ($canViewRevenue): ?><label>Sumber<select class="form-control" name="report_source"><option value="">Pelanggan dan Voucher</option><option value="customer"<?= $selectedSource==='customer'?' selected':''; ?>>Pelanggan</option><option value="voucher"<?= $selectedSource==='voucher'?' selected':''; ?>>Voucher</option></select></label><?php endif; ?><?php if($canViewRevenue||mikhmonIsBiller()): ?><label>Aliran Dana<select class="form-control" name="report_method"><option value="">Semua</option><option value="direct"<?= $selectedMethod==='direct'?' selected':''; ?>>Langsung ke Admin</option><option value="manual"<?= $selectedMethod==='manual'?' selected':''; ?>>Tunai melalui Petugas</option></select></label><?php endif; ?>
<div class="revenue-filter-actions"><button class="btn bg-primary" type="submit"><i class="fa fa-filter"></i> Terapkan</button><a class="btn bg-warning" href="./?billing=reports&amp;session=<?= rawurlencode($session); ?>"><i class="fa fa-refresh"></i> Reset</a></div><p class="revenue-filter-note"><small>Pendapatan hanya memakai transaksi lunas. Pembayaran gateway langsung diterima admin. Saldo setoran dan komisi memakai akumulasi seluruh waktu agar kewajiban lama tidak hilang oleh filter tanggal.</small></p></form>

<div class="revenue-summary" aria-label="Ringkasan laporan pendapatan">
<?php if ($canViewRevenue): ?><div><small>Pendapatan Pelanggan</small><strong><?= htmlspecialchars($money($totals['customer']),ENT_QUOTES); ?></strong></div><div><small>Pendapatan Voucher</small><strong><?= htmlspecialchars($money($totals['voucher']),ENT_QUOTES); ?></strong></div><div><small>Total Pendapatan</small><strong><?= htmlspecialchars($money($totals['revenue']),ENT_QUOTES); ?></strong></div><div><small>Langsung Diterima Admin</small><strong><?= htmlspecialchars($money($totals['direct']),ENT_QUOTES); ?></strong></div><?php endif; ?>
<?php if (mikhmonIsBiller()): $own=$collectorSummaries[0]??array('manual'=>0,'deposited'=>0,'outstanding'=>0,'commission_open'=>0); ?><div><small>Tunai Ditagih</small><strong><?= htmlspecialchars($money($own['manual']),ENT_QUOTES); ?></strong></div><div><small>Sudah Disetor</small><strong><?= htmlspecialchars($money($own['deposited']),ENT_QUOTES); ?></strong></div><div><small>Belum Disetor</small><strong><?= htmlspecialchars($money($own['outstanding']),ENT_QUOTES); ?></strong></div><div><small>Komisi Belum Dibayar</small><strong><?= htmlspecialchars($money($own['commission_open']),ENT_QUOTES); ?></strong></div><?php elseif($canManageFinance): ?><div><small>Saldo Belum Disetor</small><strong><?= htmlspecialchars($money($outstandingTotal),ENT_QUOTES); ?></strong></div><div><small>Komisi Belum Dibayar</small><strong><?= htmlspecialchars($money($commissionOpenTotal),ENT_QUOTES); ?></strong></div><?php elseif(mikhmonIsOperator()): ?><div><small>Piutang Terbuka</small><strong><?= htmlspecialchars($money($receivableTotal),ENT_QUOTES); ?></strong></div><?php endif; ?>
</div>

<div class="card"><div class="card-header"><h3><i class="fa fa-line-chart"></i> <?= mikhmonIsBiller()?'Aktivitas Penagihan':(mikhmonIsOperator()?'Prioritas Piutang':'Laporan Pendapatan'); ?></h3></div><div class="card-body">
<div class="revenue-tabs" role="tablist" aria-label="Jenis laporan"><?php if($canViewRevenue||mikhmonIsBiller()): ?><button id="revenueTabTransactions" class="btn" type="button" role="tab" data-panel="transactions" aria-controls="revenuePanelTransactions"><?= mikhmonIsBiller()?'Pembayaran Ditagih':'Semua Transaksi'; ?></button><?php endif; ?><?php if($canManageFinance): ?><button id="revenueTabPartners" class="btn" type="button" role="tab" data-panel="partners" aria-controls="revenuePanelPartners">Pendapatan per Mitra</button><?php endif; ?><button id="revenueTabReceivable" class="btn" type="button" role="tab" data-panel="receivable" aria-controls="revenuePanelReceivable">Piutang</button><?php if($canManageFinance||mikhmonIsMitra()||mikhmonIsBiller()): ?><button id="revenueTabDeposits" class="btn" type="button" role="tab" data-panel="deposits" aria-controls="revenuePanelDeposits">Setoran</button><?php endif; ?><?php if($canViewSettlement): ?><button id="revenueTabCommission" class="btn" type="button" role="tab" data-panel="commission" aria-controls="revenuePanelCommission">Komisi</button><?php endif; ?></div>
<div class="revenue-toolbar"><input id="revenueReportSearch" class="form-control" type="search" placeholder="Cari transaksi, pelanggan, voucher, mitra, atau petugas" aria-label="Cari laporan"><button id="revenueReportExport" class="btn bg-success" type="button"><i class="fa fa-file-excel-o"></i> Export CSV</button></div><p class="revenue-table-hint"><small>Geser tabel ke samping untuk melihat kolom lainnya.</small></p>

<?php if($canViewRevenue||mikhmonIsBiller()): ?><section id="revenuePanelTransactions" class="revenue-panel" data-report-panel="transactions" role="tabpanel" hidden><div class="overflow box-bordered"><table class="table table-bordered table-hover revenue-table"><thead><tr><th>Tanggal</th><th>Sumber</th><th>Referensi</th><th>Keterangan</th><th>Mitra</th><th>Penerima</th><th>Aliran Dana</th><th class="text-right">Pendapatan</th><th class="text-right">Dibayar</th></tr></thead><tbody><?php foreach($transactions as $row): ?><tr class="revenue-row"><td><?= date('d/m/Y H:i',(int)$row['date']); ?></td><td><?= $row['source']==='voucher'?'Voucher':'Pelanggan'; ?></td><td><?= htmlspecialchars($row['reference'],ENT_QUOTES); ?></td><td><?= htmlspecialchars($row['description'],ENT_QUOTES); ?></td><td><?= htmlspecialchars($row['owner_user_id']!==''?($mitraNames[$row['owner_user_id']]??'Mitra tidak ditemukan'):'Belum ditetapkan',ENT_QUOTES); ?></td><td><?= htmlspecialchars($row['collector_user_id']!==''?($collectorNames[$row['collector_user_id']]??'Petugas tidak ditemukan'):'Admin',ENT_QUOTES); ?></td><td><?= !empty($row['direct_received'])?'Langsung ke Admin':'Tunai melalui Petugas'; ?></td><td class="text-right"><?= htmlspecialchars($money($row['revenue']),ENT_QUOTES); ?></td><td class="text-right"><?= htmlspecialchars($money($row['gross']),ENT_QUOTES); ?></td></tr><?php endforeach; ?><?php if(!$transactions): ?><tr><td colspan="9" class="revenue-empty">Belum ada transaksi lunas sesuai filter.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>

<?php if($canManageFinance): ?><section id="revenuePanelPartners" class="revenue-panel" data-report-panel="partners" role="tabpanel" hidden><div class="overflow box-bordered"><table class="table table-bordered table-hover revenue-table"><thead><tr><th>Mitra</th><th class="text-right">Dari Pelanggan</th><th class="text-right">Dari Voucher</th><th class="text-right">Total Pendapatan</th></tr></thead><tbody><?php foreach($partnerSummaries as $summary): ?><tr class="revenue-row"><td><?= htmlspecialchars($summary['name'],ENT_QUOTES); ?></td><td class="text-right"><?= htmlspecialchars($money($summary['customer']),ENT_QUOTES); ?></td><td class="text-right"><?= htmlspecialchars($money($summary['voucher']),ENT_QUOTES); ?></td><td class="text-right"><strong><?= htmlspecialchars($money($summary['total']),ENT_QUOTES); ?></strong></td></tr><?php endforeach; ?><?php if(!$partnerSummaries): ?><tr><td colspan="4" class="revenue-empty">Belum ada pendapatan mitra sesuai filter.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>

<section id="revenuePanelReceivable" class="revenue-panel" data-report-panel="receivable" role="tabpanel" hidden><div class="overflow box-bordered"><table class="table table-bordered table-hover revenue-table"><thead><tr><th>Prioritas</th><th>Invoice</th><th>Pelanggan</th><th>Mitra</th><th>Petugas</th><th>Jatuh Tempo</th><th>Status</th><th class="text-right">Piutang</th></tr></thead><tbody><?php foreach($receivables as $invoice): ?><tr class="revenue-row"><td class="revenue-priority <?= ($invoice['_days_overdue']??0)>=7?'is-high':''; ?>"><?= ($invoice['_days_overdue']??0)>0?(int)$invoice['_days_overdue'].' hari':'Belum jatuh tempo'; ?></td><td><?= htmlspecialchars($invoice['number']??'-',ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['customer_name']??'-',ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['_mitra_name'],ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['_collector_name'],ENT_QUOTES); ?></td><td><?= htmlspecialchars(substr((string)($invoice['due_date']??'-'),0,10),ENT_QUOTES); ?></td><td><?= htmlspecialchars(mikhmonInvoiceStatusLabel($invoice['_effective_status']),ENT_QUOTES); ?></td><td class="text-right"><?= htmlspecialchars($money($invoice['_report_amount']),ENT_QUOTES); ?></td></tr><?php endforeach; ?><?php if(!$receivables): ?><tr><td colspan="8" class="revenue-empty">Tidak ada piutang terbuka sesuai filter.</td></tr><?php endif; ?></tbody></table></div></section>

<?php if($canManageFinance||mikhmonIsMitra()||mikhmonIsBiller()): ?><section id="revenuePanelDeposits" class="revenue-panel" data-report-panel="deposits" role="tabpanel" hidden><?php if($canManageFinance): ?><form class="revenue-form" method="post"><?= mikhmonCsrfField(); ?><input type="hidden" name="revenue_report_action" value="add_deposit"><label>Petugas<select class="form-control" name="collector_user_id" required><option value="">Pilih Mitra atau Biller</option><?php foreach($collectorNames as $id=>$name): ?><option value="<?= htmlspecialchars($id,ENT_QUOTES); ?>"><?= htmlspecialchars($name,ENT_QUOTES); ?></option><?php endforeach; ?></select></label><label>Nominal Setoran<input class="form-control" type="number" name="deposit_amount" min="1" step="1" required></label><label>Tanggal Diterima<input class="form-control" type="date" name="deposit_date" value="<?= date('Y-m-d'); ?>" required></label><label>Metode<select class="form-control" name="deposit_method"><option value="transfer">Transfer</option><option value="cash">Tunai</option></select></label><label class="wide">Referensi<input class="form-control" name="deposit_reference" maxlength="100" placeholder="Nomor transfer atau tanda terima"></label><label class="wide">Catatan<input class="form-control" name="deposit_note" maxlength="160" placeholder="Catatan opsional"></label><button class="btn bg-primary" type="submit"><i class="fa fa-save"></i> Catat Setoran</button></form><?php endif; ?>
<div class="overflow box-bordered"><table class="table table-bordered table-hover revenue-table"><thead><tr><th>Petugas</th><th>Peran</th><th class="text-right">Tunai Diterima</th><th class="text-right">Sudah Disetor</th><th class="text-right">Belum Disetor</th><th class="text-right">Komisi Belum Dibayar</th></tr></thead><tbody><?php foreach($collectorSummaries as $summary): ?><tr class="revenue-row"><td><?= htmlspecialchars($summary['name'],ENT_QUOTES); ?></td><td><?= htmlspecialchars($summary['role'],ENT_QUOTES); ?></td><td class="text-right"><?= htmlspecialchars($money($summary['manual']),ENT_QUOTES); ?></td><td class="text-right"><?= htmlspecialchars($money($summary['deposited']),ENT_QUOTES); ?></td><td class="text-right <?= $summary['outstanding']>0?'revenue-status-open':''; ?>"><?= htmlspecialchars($money($summary['outstanding']),ENT_QUOTES); ?></td><td class="text-right"><?= htmlspecialchars($money($summary['commission_open']),ENT_QUOTES); ?></td></tr><?php endforeach; ?><?php if(!$collectorSummaries): ?><tr><td colspan="6" class="revenue-empty">Belum ada saldo setoran petugas.</td></tr><?php endif; ?></tbody></table></div>
<h3 style="margin:18px 0 8px">Riwayat Setoran</h3><div class="overflow box-bordered"><table class="table table-bordered table-hover revenue-table"><thead><tr><th>Tanggal</th><th>Petugas</th><th>Metode</th><th>Referensi</th><th>Catatan</th><th>Dicatat Oleh</th><th class="text-right">Nominal</th></tr></thead><tbody><?php foreach($visibleDeposits as $deposit): ?><tr class="revenue-row"><td><?= htmlspecialchars($deposit['date']??'-',ENT_QUOTES); ?></td><td><?= htmlspecialchars($deposit['collector_name']??($collectorNames[$deposit['collector_user_id']??'']??'-'),ENT_QUOTES); ?></td><td><?= ($deposit['method']??'')==='cash'?'Tunai':'Transfer'; ?></td><td><?= htmlspecialchars(($deposit['reference']??'')!==''?$deposit['reference']:'-',ENT_QUOTES); ?></td><td><?= htmlspecialchars(($deposit['note']??'')!==''?$deposit['note']:'-',ENT_QUOTES); ?></td><td><?= htmlspecialchars($deposit['created_by']??'-',ENT_QUOTES); ?></td><td class="text-right"><?= htmlspecialchars($money($deposit['amount']??0),ENT_QUOTES); ?></td></tr><?php endforeach; ?><?php if(!$visibleDeposits): ?><tr><td colspan="7" class="revenue-empty">Belum ada setoran yang dicatat.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>

<?php if($canViewSettlement): ?><section id="revenuePanelCommission" class="revenue-panel" data-report-panel="commission" role="tabpanel" hidden><div class="overflow box-bordered"><table class="table table-bordered table-hover revenue-table"><thead><tr><th>Tanggal</th><th>Invoice</th><th>Petugas</th><th>Status</th><th class="text-right">Komisi</th><?php if($canManageFinance): ?><th>Aksi</th><?php endif; ?></tr></thead><tbody><?php foreach($commissionRows as $row): ?><tr class="revenue-row"><td><?= date('d/m/Y H:i',(int)$row['date']); ?></td><td><?= htmlspecialchars($row['reference'],ENT_QUOTES); ?></td><td><?= htmlspecialchars($collectorNames[$row['collector_user_id']]??'Petugas tidak ditemukan',ENT_QUOTES); ?></td><td><?= !empty($row['commission_settled'])?'Sudah dibayar':'Belum dibayar'; ?></td><td class="text-right"><?= htmlspecialchars($money($row['commission']),ENT_QUOTES); ?></td><?php if($canManageFinance): ?><td><?php if(empty($row['commission_settled'])&&$row['invoice_id']!==''): ?><form method="post" onsubmit="return confirm('Tandai komisi ini sudah dibayar?');"><?= mikhmonCsrfField(); ?><input type="hidden" name="revenue_report_action" value="settle_commission"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars($row['invoice_id'],ENT_QUOTES); ?>"><button class="btn bg-success" type="submit">Bayar Komisi</button></form><?php else: ?>-<?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?><?php if(!$commissionRows): ?><tr><td colspan="<?= $canManageFinance?6:5; ?>" class="revenue-empty">Belum ada komisi sesuai filter.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>
</div></div></div>
<script>(function(){var tabs=Array.prototype.slice.call(document.querySelectorAll('.revenue-tabs [role="tab"]')),panels=document.querySelectorAll('.revenue-panel'),search=document.getElementById('revenueReportSearch'),exportButton=document.getElementById('revenueReportExport'),tabInput=document.getElementById('revenueReportTabInput');function filterRows(){var term=(search.value||'').toLowerCase();document.querySelectorAll('.revenue-panel:not([hidden]) .revenue-row').forEach(function(row){row.style.display=row.textContent.toLowerCase().indexOf(term)>-1?'':'none';});}function openPanel(name){tabs.forEach(function(tab){var active=tab.getAttribute('data-panel')===name;tab.setAttribute('aria-selected',active?'true':'false');tab.setAttribute('tabindex',active?'0':'-1');tab.classList.toggle('bg-primary',active);});panels.forEach(function(panel){panel.hidden=panel.getAttribute('data-report-panel')!==name;});if(tabInput)tabInput.value=name;filterRows();}function csvCell(value){return '"'+String(value).replace(/"/g,'""')+'"';}function exportTable(){var panel=document.querySelector('.revenue-panel:not([hidden])'),table=panel?panel.querySelector('table'):null;if(!table)return;var lines=[];table.querySelectorAll('tr').forEach(function(row){if(row.style.display==='none'||row.querySelector('.revenue-empty'))return;lines.push(Array.prototype.map.call(row.querySelectorAll('th,td'),function(cell){return csvCell(cell.textContent.trim().replace(/\s+/g,' '));}).join(','));});if(lines.length<2){alert('Tidak ada data sesuai filter untuk diekspor.');return;}var blob=new Blob(["\uFEFF"+lines.join("\r\n")],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download='laporan-pendapatan-'+panel.getAttribute('data-report-panel')+'-<?= date('Ymd-His'); ?>.csv';document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);}tabs.forEach(function(tab,index){tab.addEventListener('click',function(){openPanel(tab.getAttribute('data-panel'));});tab.addEventListener('keydown',function(event){var next=index;if(event.key==='ArrowRight')next=(index+1)%tabs.length;else if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=tabs.length-1;else return;event.preventDefault();tabs[next].focus();openPanel(tabs[next].getAttribute('data-panel'));});});search.addEventListener('input',filterRows);exportButton.addEventListener('click',exportTable);openPanel(<?= json_encode($initialPanel); ?>);})();</script>
