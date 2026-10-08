<?php

session_save_path('/tmp');
session_start();
date_default_timezone_set('Asia/Jakarta');
$databasePath = tempnam(sys_get_temp_dir(), 'mikhmon-report-page-');
putenv('MIKHMON_DATABASE_PATH=' . $databasePath);
require dirname(__DIR__) . '/include/access.php';

function billingReportPageTestAssert($condition, $message) {
  if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$_SESSION['mikhmon'] = 'admin';
$_SESSION['mikhmon_role'] = 'admin';
$session = 'router-a';
$currency = 'Rp';
$mitraOne = mikhmonSaveUser('', 'Mitra Satu', 'mitra-satu', 'mitra', $session, 'secret');
$mitraTwo = mikhmonSaveUser('', 'Mitra Dua', 'mitra-dua', 'mitra', $session, 'secret');
$biller = mikhmonSaveUser('', 'Penagih Satu', 'penagih-satu', 'biller', $session, 'secret');
$customerOne = mikhmonSaveCustomer($session, 'customer-one', 'Pelanggan Mitra Satu', '', '', 'hotspot', 'cust-one', 'basic', $mitraOne);
$customerTwo = mikhmonSaveCustomer($session, 'customer-two', 'Pelanggan Mitra Dua', '', '', 'hotspot', 'cust-two', 'basic', $mitraTwo);
$paidAt = strtotime('2026-10-08 10:00:00');
mikhmonSaveInvoice($session, array(
  'id' => 'invoice-one', 'number' => 'INV-MITRA-ONE', 'customer_id' => $customerOne, 'customer_name' => 'Pelanggan Mitra Satu',
  'status' => 'paid', 'paid_at' => $paidAt, 'subtotal' => 100000, 'admin_fee' => 0, 'collection_fee' => 2500,
  'biller_commission' => 2500, 'amount' => 102500, 'paid_by_user_id' => $biller,
  'services' => array(array('service' => 'hotspot', 'username' => 'cust-one', 'profile' => 'basic', 'amount' => 100000)),
));
mikhmonSaveInvoice($session, array(
  'id' => 'invoice-two', 'number' => 'INV-MITRA-TWO', 'customer_id' => $customerTwo, 'customer_name' => 'Pelanggan Mitra Dua',
  'status' => 'paid', 'paid_at' => $paidAt, 'subtotal' => 200000, 'admin_fee' => 0, 'collection_fee' => 2500,
  'biller_commission' => 2500, 'amount' => 202500, 'paid_by_user_id' => $biller,
  'services' => array(array('service' => 'hotspot', 'username' => 'cust-two', 'profile' => 'basic', 'amount' => 200000)),
));

$_GET = array(
  'billing' => 'reports', 'session' => $session, 'report_mitra' => $mitraOne,
  'report_from' => '2026-10-01', 'report_to' => '2026-10-31', 'report_status' => 'paid',
);
ob_start();
include dirname(__DIR__) . '/customer/billingreports.php';
$html = ob_get_clean();

billingReportPageTestAssert(strpos($html, 'INV-MITRA-ONE') !== false && strpos($html, 'INV-MITRA-TWO') === false, 'admin mitra filter only renders matching invoices');
billingReportPageTestAssert(strpos($html, 'Pendapatan bersih ISP') !== false && strpos($html, 'Rp 100.000') !== false, 'admin summary shows filtered net ISP income');
billingReportPageTestAssert(strpos($html, 'Mitra Satu') !== false && strpos($html, 'Penagih Satu') !== false, 'report identifies mitra and collector separately');
billingReportPageTestAssert(strpos($html, 'billingReportExport') !== false && strpos($html, 'Export CSV') !== false, 'filtered report can be exported');
billingReportPageTestAssert(strpos($html, 'Biaya admin') === false && strpos($html, 'Biaya Admin') === false, 'unused admin fee is hidden from the billing report');

$_SESSION['mikhmon_role'] = 'operator';
$_SESSION['mikhmon_user_id'] = 'operator-test';
$_GET = array('billing' => 'reports', 'session' => $session);
ob_start();
include dirname(__DIR__) . '/customer/billingreports.php';
$operatorHtml = ob_get_clean();
billingReportPageTestAssert(strpos($operatorHtml, 'Pendapatan bersih ISP') === false && strpos($operatorHtml, 'name="report_mitra"') === false, 'operator cannot access net revenue or partner financial filters');

@unlink($databasePath);
echo 'billing-report-page-tests: OK' . PHP_EOL;
