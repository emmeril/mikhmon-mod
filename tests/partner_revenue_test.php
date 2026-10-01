<?php

date_default_timezone_set('Asia/Jakarta');
$databasePath = tempnam(sys_get_temp_dir(), 'mikhmon-partner-revenue-');
putenv('MIKHMON_DATABASE_PATH=' . $databasePath);
require dirname(__DIR__) . '/include/database.php';
require dirname(__DIR__) . '/lib/voucher_summary.php';

function partnerRevenueTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$ownerId = 'user-mitra-1';
$customerId = mikhmonSaveCustomer('router-a', '', 'Pelanggan Mitra', '', '', 'hotspot', 'customer-a', 'billing', $ownerId);
$otherCustomerId = mikhmonSaveCustomer('router-a', '', 'Pelanggan Lain', '', '', 'hotspot', 'customer-b', 'billing', 'user-mitra-2');
$paidAt = strtotime('2026-10-01 09:00:00');

mikhmonSaveInvoice('router-a', array('id' => 'monthly-owner', 'customer_id' => $customerId, 'kind' => 'monthly', 'status' => 'paid', 'paid_at' => $paidAt, 'amount' => 150000));
mikhmonSaveInvoice('router-a', array('id' => 'voucher-owner', 'customer_id' => $customerId, 'kind' => 'voucher', 'status' => 'paid', 'paid_at' => $paidAt, 'amount' => 20000, 'voucher_username' => 'PORTAL-1'));
mikhmonSaveInvoice('router-a', array('id' => 'monthly-other', 'customer_id' => $otherCustomerId, 'kind' => 'monthly', 'status' => 'paid', 'paid_at' => $paidAt, 'amount' => 999000));
mikhmonSaveInvoice('router-a', array('id' => 'monthly-old', 'customer_id' => $customerId, 'kind' => 'monthly', 'status' => 'paid', 'paid_at' => strtotime('2026-09-30 09:00:00'), 'amount' => 50000));

$profiles = array(
  array('name' => 'voucher', 'on-login' => ':put (",remc,10000,1d,25000,,Disable,")'),
  array('name' => 'billing', 'on-login' => ':put (",,100000,30d,150000,noexp,Disable,")'),
);
mikhmonSaveReportRecords('router-a', array(
  array('name' => 'oct/01/2026-|-10:00:00-|-MANUAL-1-|-25000-|-10.0.0.1-|-AA-|-1d-|-voucher-|-vc-batch [mitra:user-mitra-1]-|-hotspot-|-10000'),
  array('name' => 'oct/01/2026-|-10:05:00-|-PORTAL-1-|-25000-|-10.0.0.2-|-BB-|-1d-|-voucher-|-vc-portal [mitra:user-mitra-1]-|-hotspot-|-10000'),
  array('name' => 'oct/01/2026-|-10:10:00-|-OTHER-1-|-25000-|-10.0.0.3-|-CC-|-1d-|-voucher-|-vc-batch [mitra:user-mitra-2]-|-hotspot-|-10000'),
  array('name' => 'sep/30/2026-|-10:15:00-|-OLD-1-|-25000-|-10.0.0.4-|-DD-|-1d-|-voucher-|-vc-batch [mitra:user-mitra-1]-|-hotspot-|-10000'),
));

$revenue = mikhmonVoucherOwnerRevenue('router-a', $profiles, $ownerId, '202610');
partnerRevenueTestAssert($revenue['voucher'] === 45000.0, 'voucher revenue combines manual sales and voucher invoices without counting portal vouchers twice');
partnerRevenueTestAssert($revenue['customer'] === 150000.0, 'customer revenue includes only this partner monthly invoices in the selected month');

@unlink($databasePath);
@unlink($databasePath . '.routers');
echo 'partner-revenue-tests: OK' . PHP_EOL;
