<?php

date_default_timezone_set('Asia/Jakarta');
require dirname(__DIR__) . '/lib/billing_report.php';

function billingReportFilterTestAssert($condition, $message) {
  if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$invoice = array(
  'status' => 'paid', 'paid_at' => strtotime('2026-10-08 10:00:00'), 'created_at' => strtotime('2026-10-01'),
  'subtotal' => 150000, 'admin_fee' => 1500, 'collection_fee' => 3000, 'biller_commission' => 3000, 'amount' => 154500,
  'paid_by_user_id' => 'biller-1', 'payment_gateway' => 'midtrans',
  'services' => array(
    array('service' => 'hotspot', 'amount' => 100000),
    array('service' => 'pppoe', 'amount' => 50000),
  ),
);
$customer = array('mitra_id' => 'mitra-1', 'grace_days' => 2);
$filters = array(
  'mitra_id' => 'mitra-1', 'biller_id' => 'biller-1', 'service' => '', 'method' => 'midtrans', 'status' => 'paid',
  'date_from' => strtotime('2026-10-01 00:00:00'), 'date_to' => strtotime('2026-10-31 23:59:59'),
);
$prepared = mikhmonBillingReportPrepareInvoice($invoice, $customer, $filters, strtotime('2026-10-09'));
billingReportFilterTestAssert($prepared !== false && $prepared['_report_amount'] === 154500.0, 'matching mitra, collector, period, method, and status keeps the invoice');
billingReportFilterTestAssert($prepared['_report_commission'] === 3000.0 && $prepared['_report_net'] === 151500.0, 'gross payment, commission, and net income remain separate');

$hotspotFilters = $filters;
$hotspotFilters['service'] = 'hotspot';
$hotspot = mikhmonBillingReportPrepareInvoice($invoice, $customer, $hotspotFilters, strtotime('2026-10-09'));
billingReportFilterTestAssert(round($hotspot['_report_amount'], 2) === 103000.0 && round($hotspot['_report_commission'], 2) === 2000.0, 'mixed invoice fees are allocated proportionally to the selected service');

$legacyInvoice = $invoice;
unset($legacyInvoice['collection_fee']);
$legacyInvoice['admin_fee'] = 3000;
$legacyInvoice['amount'] = 153000;
$legacyFinancials = mikhmonBillingReportFinancials($legacyInvoice);
billingReportFilterTestAssert($legacyFinancials['admin_fee'] === 0.0 && $legacyFinancials['collection_fee'] === 3000.0 && $legacyFinancials['amount'] === 153000.0, 'legacy commission stored as admin fee is reported without double counting');

$wrongMitra = $filters;
$wrongMitra['mitra_id'] = 'mitra-2';
billingReportFilterTestAssert(mikhmonBillingReportPrepareInvoice($invoice, $customer, $wrongMitra) === false, 'another mitra invoice is excluded');
$unassignedFilter = $filters;
$unassignedFilter['mitra_id'] = 'unassigned';
billingReportFilterTestAssert(mikhmonBillingReportPrepareInvoice($invoice, array('mitra_id' => ''), $unassignedFilter) !== false, 'unassigned filter keeps customers without a mitra');
billingReportFilterTestAssert(mikhmonBillingReportPrepareInvoice($invoice, $customer, $unassignedFilter) === false, 'unassigned filter excludes customers owned by a mitra');

$wrongPeriod = $filters;
$wrongPeriod['date_from'] = strtotime('2026-11-01 00:00:00');
billingReportFilterTestAssert(mikhmonBillingReportPrepareInvoice($invoice, $customer, $wrongPeriod) === false, 'paid invoice period uses the payment date');

echo 'billing-report-filter-tests: OK' . PHP_EOL;
