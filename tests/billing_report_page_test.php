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
$_SERVER['REQUEST_METHOD'] = 'GET';
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
mikhmonSaveReportRecords($session, array(array(
  'name' => 'oct/08/2026-|-11:00:00-|-VOUCHER-ONE-|-25000-|-10.0.0.1-|-AA-|-1d-|-voucher-|-vc-batch [mitra:' . $mitraOne . ']-|-hotspot-|-10000',
)));
$billingReportHotspotProfiles = array(array('name' => 'voucher', 'on-login' => ':put (",remc,10000,1d,25000,,Disable,")'));

$_GET = array(
  'billing' => 'reports', 'session' => $session, 'report_mitra' => $mitraOne,
  'report_from' => '2026-10-01', 'report_to' => '2026-10-31', 'report_status' => 'paid',
);
ob_start();
include dirname(__DIR__) . '/customer/billingreports.php';
$html = ob_get_clean();

billingReportPageTestAssert(strpos($html, 'INV-MITRA-ONE') !== false && strpos($html, 'INV-MITRA-TWO') === false, 'admin mitra filter only renders matching invoices');
billingReportPageTestAssert(strpos($html, 'Laporan Pendapatan') !== false && strpos($html, 'Pendapatan Pelanggan') !== false, 'admin sees the unified revenue report');
billingReportPageTestAssert(strpos($html, 'Pendapatan Voucher') !== false && strpos($html, 'Rp 25.000') !== false, 'admin summary includes partner voucher revenue');
billingReportPageTestAssert(strpos($html, 'Total Pendapatan') !== false && strpos($html, 'Rp 125.000') !== false, 'admin summary combines customer and voucher revenue');
billingReportPageTestAssert(strpos($html, 'Pendapatan per Mitra') !== false && strpos($html, 'Dari Pelanggan') !== false && strpos($html, 'Dari Voucher') !== false, 'admin can compare revenue sources per partner');
billingReportPageTestAssert(strpos($html, 'Mitra Satu') !== false && strpos($html, 'Penagih Satu') !== false, 'report identifies mitra and collector separately');
billingReportPageTestAssert(strpos($html, 'revenueReportExport') !== false && strpos($html, 'Export CSV') !== false, 'filtered report can be exported');
billingReportPageTestAssert(strpos($html, 'Biaya admin') === false && strpos($html, 'Biaya Admin') === false, 'unused admin fee is hidden from the billing report');

$_SESSION['mikhmon_csrf'] = 'report-test-csrf';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
  '_csrf' => 'report-test-csrf', 'revenue_report_action' => 'add_deposit', 'collector_user_id' => $biller,
  'deposit_amount' => 50000, 'deposit_date' => '2026-10-08', 'deposit_method' => 'transfer',
  'deposit_reference' => 'TRX-REPORT-TEST', 'deposit_note' => 'Setoran sebagian',
);
ob_start();
include dirname(__DIR__) . '/customer/billingreports.php';
$depositHtml = ob_get_clean();
billingReportPageTestAssert(strpos($depositHtml, 'Setoran berhasil dicatat.') !== false && count(mikhmonGetRevenueDeposits($session, $biller)) === 1, 'admin deposit form records a collector payment');

$_SESSION['mikhmon_role'] = 'operator';
$_SESSION['mikhmon_user_id'] = 'operator-test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = array();
$_GET = array('billing' => 'reports', 'session' => $session);
ob_start();
include dirname(__DIR__) . '/customer/billingreports.php';
$operatorHtml = ob_get_clean();
billingReportPageTestAssert(strpos($operatorHtml, 'Pendapatan bersih ISP') === false && strpos($operatorHtml, 'name="report_mitra"') === false, 'operator cannot access net revenue or partner financial filters');

$_SESSION['mikhmon_role'] = 'biller';
$_SESSION['mikhmon_user_id'] = $biller;
$_SESSION['mikhmon_name'] = 'Penagih Satu';
$_GET = array('billing' => 'reports', 'session' => $session);
ob_start();
include dirname(__DIR__) . '/customer/billingreports.php';
$billerHtml = ob_get_clean();
billingReportPageTestAssert(strpos($billerHtml, 'Aktivitas Penagihan') !== false && strpos($billerHtml, 'Setoran') !== false && strpos($billerHtml, 'Komisi') !== false, 'biller sees collections, deposits, and commissions');
billingReportPageTestAssert(strpos($billerHtml, 'Rp 305.000') !== false && strpos($billerHtml, 'Rp 5.000') !== false, 'biller summary shows gross cash and unpaid commission');
billingReportPageTestAssert(strpos($billerHtml, 'Catat Setoran') === false && strpos($billerHtml, 'Pendapatan per Mitra') === false, 'biller cannot record deposits or inspect partner revenue');

$menuSource = file_get_contents(dirname(__DIR__) . '/include/menu.php');
billingReportPageTestAssert(strpos($menuSource, 'Laporan Billing') === false && strpos($menuSource, 'report=selling') === false, 'legacy report menus are removed');
billingReportPageTestAssert(substr_count($menuSource, '> Laporan Pendapatan</a>') === 3, 'admin, finance, and partner use one revenue report menu');
billingReportPageTestAssert(strpos($menuSource, 'Setoran &amp; Komisi') !== false && strpos($menuSource, '> Komisi Saya</a>') === false, 'biller uses one combined deposit and commission menu');

@unlink($databasePath);
echo 'billing-report-page-tests: OK' . PHP_EOL;
