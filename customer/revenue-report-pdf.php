<?php
session_start();
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
if (!empty($_SESSION['timezone'])) @date_default_timezone_set((string) $_SESSION['timezone']);

include_once(dirname(__DIR__) . '/include/config.php');
include_once(dirname(__DIR__) . '/include/brand.php');
include_once(dirname(__DIR__) . '/include/access.php');
include_once(dirname(__DIR__) . '/lib/revenue_report.php');
include_once(dirname(__DIR__) . '/lib/revenue_report_pdf.php');

if (!in_array(mikhmonRole(), array('admin', 'finance', 'mitra'), true) || !mikhmonRefreshStaffSession()) {
  http_response_code(403);
  exit('Akses ditolak.');
}

$session = trim((string) ($_GET['session'] ?? ''));
if ($session === '' || (!mikhmonIsAdmin() && !mikhmonIsFinance() && mikhmonAssignedSession() !== '' && $session !== mikhmonAssignedSession())) {
  http_response_code(400);
  exit('Parameter laporan tidak valid.');
}

$dateValue = function ($name) {
  $value = trim((string) ($_GET[$name] ?? ''));
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
};
$dateFromValue = $dateValue('report_from');
$dateToValue = $dateValue('report_to');
$dateFrom = $dateFromValue !== '' ? strtotime($dateFromValue . ' 00:00:00') : 0;
$dateTo = $dateToValue !== '' ? strtotime($dateToValue . ' 23:59:59') : 0;
if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
  http_response_code(400);
  exit('Rentang tanggal laporan tidak valid.');
}

$mitraNames = array();
foreach (mikhmonGetUsers('mitra', $session) as $user) {
  $id = (string) ($user['id'] ?? '');
  if ($id !== '') $mitraNames[$id] = (string) ($user['name'] ?? $user['username'] ?? 'Mitra');
}
$ownerId = mikhmonIsMitra() ? mikhmonUserId() : trim((string) ($_GET['report_mitra'] ?? ''));
if ($ownerId !== '' && $ownerId !== 'unassigned' && !isset($mitraNames[$ownerId])) {
  http_response_code(400);
  exit('Mitra laporan tidak ditemukan.');
}
$collectorId = (mikhmonIsAdmin() || mikhmonIsFinance()) ? trim((string) ($_GET['report_collector'] ?? '')) : '';
$source = in_array($_GET['report_source'] ?? '', array('customer', 'voucher'), true) ? (string) $_GET['report_source'] : '';
$method = in_array($_GET['report_method'] ?? '', array('manual', 'direct'), true) ? (string) $_GET['report_method'] : '';
if ($source === 'voucher' && $dateFrom && $dateTo && strtotime($dateToValue . ' 00:00:00') - strtotime($dateFromValue . ' 00:00:00') > 30 * 86400) {
  http_response_code(400);
  exit('Rekap voucher maksimal 31 hari dalam satu laporan.');
}

$transactions = mikhmonRevenueTransactions($session, array());
$transactions = mikhmonRevenueFilterTransactions($transactions, array(
  'date_from' => $dateFrom,
  'date_to' => $dateTo,
  'owner_user_id' => $ownerId,
  'collector_user_id' => $collectorId,
  'source' => $source,
  'method' => $method,
));
$voucherRows = mikhmonRevenueVoucherReportRows($transactions, $mitraNames);
$invoiceRows = mikhmonRevenuePaidInvoiceReportRows($transactions, $mitraNames);

$currency = 'Rp';
$sessionLabel = $session;
if (isset($data[$session][4])) {
  $parts = explode('%', (string) $data[$session][4], 2);
  if (!empty($parts[1])) $sessionLabel = trim((string) $parts[1]);
}
if (isset($data[$session][6])) {
  $parts = explode('&', (string) $data[$session][6], 2);
  if (!empty($parts[1])) $currency = trim((string) $parts[1]);
}
$ownerName = 'Semua Mitra';
if ($ownerId === 'unassigned') $ownerName = 'Belum ditetapkan';
elseif ($ownerId !== '') $ownerName = $mitraNames[$ownerId];
$periodLabel = 'Semua tanggal';
if ($dateFromValue !== '' || $dateToValue !== '') {
  $periodLabel = ($dateFromValue !== '' ? date('d/m/Y', $dateFrom) : 'Awal') . ' - ' . ($dateToValue !== '' ? date('d/m/Y', $dateTo) : 'Sekarang');
}

$brand = isset($brandname) && trim((string) $brandname) !== '' ? trim((string) $brandname) : 'MIKHMON';
$pdf = mikhmonRevenueReportPdf(array(
  'brand' => $brand,
  'session_label' => $sessionLabel,
  'currency' => $currency,
  'owner_user_id' => $ownerId,
  'owner_name' => $ownerName,
  'period_label' => $periodLabel,
  'printed_at' => date('d/m/Y H:i:s'),
  'voucher_rows' => $voucherRows,
  'invoice_rows' => $invoiceRows,
));

$periodFile = $dateFromValue !== '' ? str_replace('-', '', $dateFromValue) : 'awal';
$periodFile .= '-' . ($dateToValue !== '' ? str_replace('-', '', $dateToValue) : 'sekarang');
$ownerFile = preg_replace('/[^A-Za-z0-9._-]/', '-', strtolower($ownerName));
$filename = 'laporan-pendapatan-' . trim($ownerFile, '-') . '-' . $periodFile . '.pdf';
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $pdf;
