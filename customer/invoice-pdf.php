<?php
session_start();
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
if (!empty($_SESSION['timezone'])) @date_default_timezone_set((string) $_SESSION['timezone']);

include_once(dirname(__DIR__) . '/include/config.php');
include_once(dirname(__DIR__) . '/include/brand.php');
include_once(dirname(__DIR__) . '/include/access.php');
include_once(dirname(__DIR__) . '/lib/invoice_pdf.php');

if (!in_array(mikhmonRole(), array('admin', 'biller', 'mitra'), true) || !mikhmonRefreshStaffSession()) {
  http_response_code(403);
  exit('Akses ditolak.');
}
$session = (string) ($_GET['session'] ?? '');
$invoiceId = (string) ($_GET['invoice_id'] ?? '');
if ($session === '' || $invoiceId === '' || (!mikhmonIsAdmin() && mikhmonAssignedSession() !== '' && $session !== mikhmonAssignedSession())) {
  http_response_code(400);
  exit('Parameter invoice tidak valid.');
}

$invoice = array();
foreach (mikhmonVisibleInvoices($session) as $candidate) {
  if ((string) ($candidate['id'] ?? '') === $invoiceId) { $invoice = $candidate; break; }
}
if (!$invoice) { http_response_code(404); exit('Invoice tidak ditemukan.'); }
$customer = mikhmonFindCustomer($session, $invoice['customer_id'] ?? '');
if (!$customer) $customer = array('name' => $invoice['customer_name'] ?? '-', 'phone' => '-');
$brand = isset($brandname) && trim((string) $brandname) !== '' ? trim((string) $brandname) : 'MIKHMON';
$currency = 'Rp';
if (isset($data[$session][6])) {
  $currencyParts = explode('&', (string) $data[$session][6], 2);
  if (isset($currencyParts[1]) && trim($currencyParts[1]) !== '') $currency = trim($currencyParts[1]);
}
$pdf = mikhmonInvoicePdf($invoice, $customer, $currency, $brand);
$filename = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) ($invoice['number'] ?? 'invoice')) . '.pdf';
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $pdf;
