<?php

date_default_timezone_set('Asia/Jakarta');
require dirname(__DIR__) . '/lib/billing_policy.php';

function billingPolicyTestAssert($condition, $message) {
  if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$beforeDue = strtotime('2026-10-05 10:00:00');
$afterDue = strtotime('2026-10-05 23:59:59') + 1;
billingPolicyTestAssert(mikhmonBillingUpcomingDueTimestamp($beforeDue) === strtotime('2026-10-05 23:59:59'), 'the fixed due date remains open through the fifth');
billingPolicyTestAssert(mikhmonBillingUpcomingDueTimestamp($afterDue) === strtotime('2026-11-05 23:59:59'), 'the next cycle starts after the fifth is complete');
billingPolicyTestAssert(mikhmonBillingInitialProration(310000, '2026-10-08') === 240000.0, 'the first invoice uses remaining calendar days');

$invoice = array('status' => 'issued', 'due_date' => '2026-10-05');
billingPolicyTestAssert(mikhmonInvoiceEffectiveStatus($invoice, strtotime('2026-10-05 20:00:00'), 0) === 'issued', 'invoice is not overdue during the due date');
billingPolicyTestAssert(mikhmonInvoiceEffectiveStatus($invoice, strtotime('2026-10-06 00:00:00'), 0) === 'overdue', 'invoice becomes overdue after the due date');
billingPolicyTestAssert(mikhmonBillingIsolationTimestamp(strtotime('2026-10-05 23:59:59'), 2) === strtotime('2026-10-08 00:00:00'), 'grace period uses complete days');
billingPolicyTestAssert(mikhmonInvoiceEffectiveStatus(array('status' => 'void'), time(), 0) === 'void', 'void status is final');
billingPolicyTestAssert(mikhmonInvoiceIsCollectible(array('status' => 'isolated')), 'an isolated invoice remains collectible');

$invoiceWithCommission = mikhmonInvoiceApplyCollectionFee(array('subtotal' => 100000, 'admin_fee' => 0, 'amount' => 100000), 2500, array('user_id' => 'biller-1', 'partner_id' => 'partner-1', 'name' => 'Biller Satu'));
billingPolicyTestAssert($invoiceWithCommission['admin_fee'] === 0 && $invoiceWithCommission['collection_fee'] === 2500.0 && $invoiceWithCommission['amount'] === 102500.0, 'customer total includes only the collection commission');
billingPolicyTestAssert($invoiceWithCommission['collection_biller_user_id'] === 'biller-1' && $invoiceWithCommission['collection_biller_partner_id'] === 'partner-1', 'invoice remembers the collector assigned to the commission');

echo 'billing-policy-tests: OK' . PHP_EOL;
