<?php

date_default_timezone_set('Asia/Jakarta');
require dirname(__DIR__) . '/include/database.php';
require dirname(__DIR__) . '/report/reportrecord.php';

function reportBillingTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$databasePath = tempnam(sys_get_temp_dir(), 'mikhmon-report-');
putenv('MIKHMON_DATABASE_PATH=' . $databasePath);
$paidAt = strtotime('2026-08-15 10:20:30');

mikhmonSaveInvoice('router-a', array(
  'id' => 'invoice-paid', 'number' => 'INV-PAID', 'customer_id' => 'customer-a',
  'status' => 'paid', 'paid_at' => $paidAt, 'amount' => 300000,
  'payment_gateway' => 'midtrans',
  'services' => array(
    array('service' => 'hotspot', 'username' => 'alice', 'profile' => 'monthly-hotspot', 'amount' => 100000),
    array('service' => 'pppoe', 'username' => 'bob', 'profile' => 'monthly-pppoe', 'amount' => 200000),
  ),
));
mikhmonSaveInvoice('router-a', array(
  'id' => 'invoice-unpaid', 'number' => 'INV-UNPAID', 'customer_id' => 'customer-b',
  'status' => 'unpaid', 'created_at' => $paidAt, 'amount' => 50000,
  'services' => array(array('service' => 'hotspot', 'username' => 'unpaid', 'profile' => 'basic', 'amount' => 50000)),
));
mikhmonSaveCustomer('router-a', 'customer-not-created', 'Customer Not Invoiced', '08123456789', '', 'pppoe', 'not-invoiced', 'monthly-pppoe');

$billingRows = mikhmonReportBillingRows('router-a', '', 'aug2026');
reportBillingTestAssert(count($billingRows) === 2, 'one paid invoice creates one report row per service');
reportBillingTestAssert(!in_array('unpaid', array_map(function ($row) { return mikhmonReportParts($row)[2]; }, $billingRows), true), 'unpaid invoices never create report rows');
reportBillingTestAssert(mikhmonReportSellingPrice($billingRows[0]) + mikhmonReportSellingPrice($billingRows[1]) === 300000.0, 'billing service amounts form the invoice income');
reportBillingTestAssert(strpos(mikhmonReportParts($billingRows[0])[8], 'INV-PAID') !== false, 'billing row identifies its invoice');
reportBillingTestAssert(strpos(mikhmonReportParts($billingRows[0])[8], 'MIDTRANS') !== false, 'gateway payment is identified in the report row');
reportBillingTestAssert(($billingRows[0]['billing_payment_gateway'] ?? '') === 'midtrans', 'gateway metadata is preserved for reporting');
reportBillingTestAssert(count(mikhmonReportBillingRows('router-a', 'aug/15/2026', '')) === 2, 'paid invoices can be filtered by day');
reportBillingTestAssert(count(mikhmonReportBillingRows('router-a', 'aug/16/2026', '')) === 0, 'other days do not include the invoice');
reportBillingTestAssert(count(mikhmonReportBillingRows('router-a', null, 'aug2026')) === 2, 'monthly report treats a missing day filter as empty');

$routerRows = array(
  array('name' => 'aug/15/2026-|-11:00:00-|-alice-|-100000-|-10.0.0.2-|-AA:BB-|-30d-|-monthly-hotspot-|-login-|-hotspot-|-50000'),
  array('name' => 'aug/15/2026-|-09:05:00-|-alice-|-75000-|-10.0.0.3-|-PPPoE-|-30d-|-other-|-login-|-pppoe-|-40000'),
  array('name' => 'aug/15/2026-|-09:10:00-|-voucher-|-20000-|-10.0.0.4-|-CC:DD-|-1d-|-voucher-|-sale-|-hotspot-|-10000'),
  array('name' => 'aug/15/2026-|-09:15:00-|-unpaid-|-50000-|-10.0.0.5-|-EE:FF-|-30d-|-basic-|-login-|-hotspot-|-25000'),
  array('name' => 'aug/15/2026-|-09:20:00-|-not-invoiced-|-75000-|-10.0.0.6-|-PPPoE-|-30d-|-monthly-pppoe-|-login-|-pppoe-|-50000'),
);
$merged = mikhmonReportMergeBillingRows('router-a', $routerRows, '', 'aug2026');
reportBillingTestAssert(count($merged) === 4, 'only paid billing and non-billing records are included');

$income = 0;
foreach ($merged as $row) $income += mikhmonReportSellingPrice($row);
reportBillingTestAssert($income === 395000.0, 'merged income contains billing, other service, and voucher transactions once');

$profit = mikhmonReportNetProfit($billingRows[0], array('hotspot|monthly-hotspot' => 60000));
reportBillingTestAssert($profit === 40000.0, 'billing transactions use profile cost for net profit');

@unlink($databasePath);
echo 'report-billing-tests: OK' . PHP_EOL;
