<?php

date_default_timezone_set('Asia/Jakarta');
$databasePath = tempnam(sys_get_temp_dir(), 'mikhmon-partner-workflow-');
putenv('MIKHMON_DATABASE_PATH=' . $databasePath);
require dirname(__DIR__) . '/lib/partner_workflow.php';
require dirname(__DIR__) . '/lib/revenue_report.php';

function partnerWorkflowTestAssert($condition, $message) {
  if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

function partnerWorkflowTestCreate($name, $username, $commission) {
  $result = mikhmonSaveManagedUser(array(
    'type' => 'reseller', 'name' => $name, 'session' => 'router-a',
    'commission' => $commission, 'login_enabled' => true,
    'username' => $username, 'password' => 'rahasia123', 'active' => true,
  ));
  partnerWorkflowTestAssert(!empty($result['status']), 'partner account can be created');
  return (string) $result['user_id'];
}

$ownerA = partnerWorkflowTestCreate('Mitra A', 'mitra-a', 5000);
$ownerB = partnerWorkflowTestCreate('Mitra B', 'mitra-b', 7000);

$adminOwner = mikhmonPartnerWorkflowVoucherOwner('router-a', $ownerA, 'admin', '');
partnerWorkflowTestAssert(!empty($adminOwner['valid']) && $adminOwner['tag'] === '[mitra:' . $ownerA . ']', 'admin can assign generated vouchers to an active partner');
$selfOwner = mikhmonPartnerWorkflowVoucherOwner('router-a', $ownerB, 'mitra', $ownerA);
partnerWorkflowTestAssert(!empty($selfOwner['valid']) && $selfOwner['user_id'] === $ownerA, 'partner-generated vouchers are always assigned to the logged-in partner');
$invalidOwner = mikhmonPartnerWorkflowVoucherOwner('router-a', 'missing', 'admin', '');
partnerWorkflowTestAssert(empty($invalidOwner['valid']), 'unknown voucher owner is rejected');

$customerId = mikhmonSaveCustomer('router-a', '', 'Pelanggan A', '', '', 'hotspot', 'cust-a', 'billing', $ownerA);
$customer = mikhmonFindCustomer('router-a', $customerId);
$invoice = mikhmonPartnerWorkflowApplyInvoiceSnapshot('router-a', array(
  'id' => 'invoice-a', 'number' => 'INV-A', 'kind' => 'monthly', 'customer_id' => $customerId,
  'customer_name' => 'Pelanggan A', 'status' => 'paid', 'paid_at' => strtotime('2026-10-10 10:00:00'),
  'subtotal' => 100000, 'amount' => 100000,
), $customer);
partnerWorkflowTestAssert($invoice['mitra_user_id'] === $ownerA && (float) $invoice['mitra_commission'] === 5000.0, 'paid invoice snapshots assigned partner and configured commission');
mikhmonSaveInvoice('router-a', $invoice);
mikhmonAssignCustomer('router-a', $customerId, $ownerB);

$transactions = mikhmonRevenueTransactions('router-a', array());
partnerWorkflowTestAssert(count($transactions) === 1 && $transactions[0]['owner_user_id'] === $ownerA, 'historical invoice keeps its original partner after customer reassignment');
$rows = mikhmonRevenuePaidInvoiceReportRows($transactions, array($ownerA => 'Mitra A', $ownerB => 'Mitra B'));
partnerWorkflowTestAssert(count($rows) === 1 && (float) $rows[0]['commission'] === 5000.0, 'paid invoice recap uses partner commission instead of collector commission');

$voucherInvoice = mikhmonPartnerWorkflowApplyInvoiceSnapshot('router-a', array('kind' => 'voucher'), $customer);
partnerWorkflowTestAssert((float) $voucherInvoice['mitra_commission'] === 0.0, 'voucher invoices use HPP margin and do not receive recurring invoice commission');

@unlink($databasePath);
echo 'partner-workflow-tests: OK' . PHP_EOL;
