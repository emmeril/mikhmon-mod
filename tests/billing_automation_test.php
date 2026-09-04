<?php

session_save_path('/tmp');
session_start();
date_default_timezone_set('Asia/Jakarta');
require dirname(__DIR__) . '/include/database.php';
require dirname(__DIR__) . '/lib/fonnte.php';
require dirname(__DIR__) . '/lib/billing_automation.php';

function billingAutomationTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

class BillingAutomationFakeApi {
  public $commands = array();
  public function comm($command, $args = array()) {
    $this->commands[] = array($command, $args);
    if ($command === '/ip/hotspot/user/print') return array(array('.id' => '*1', 'name' => 'cust-a', 'disabled' => 'no'));
    if ($command === '/ip/hotspot/active/print') return array();
    return array();
  }
}

class BillingBootstrapFakeApi {
  public function comm($command, $args = array()) {
    if ($command === '/ip/hotspot/user/profile/print') return array(array('name' => 'basic', 'on-login' => ':put (",none,100,30d,150,")'));
    if ($command === '/ppp/profile/print') return array();
    return array();
  }
}

$databasePath = tempnam(sys_get_temp_dir(), 'mikhmon-billing-');
putenv('MIKHMON_DATABASE_PATH=' . $databasePath);
$fonntePath = tempnam(sys_get_temp_dir(), 'mikhmon-fonnte-');
putenv('MIKHMON_FONNTE_CONFIG=' . $fonntePath);
$queuePath = tempnam(sys_get_temp_dir(), 'mikhmon-fonnte-queue-');
putenv('MIKHMON_FONNTE_QUEUE=' . $queuePath);
mikhmonFonnteWriteConfig(array('enabled' => false, 'automation_enabled' => true, 'reminder_enabled' => false, 'isolation_enabled' => true));

$workStart = strtotime('2026-09-01 07:00:00');
billingAutomationTestAssert(!mikhmonBillingAutomationIsWorkHour(strtotime('2026-09-01 06:59:00')), 'automatic messages wait before 07:00');
billingAutomationTestAssert(mikhmonBillingAutomationIsWorkHour($workStart), 'automatic messages start at 07:00');
billingAutomationTestAssert(!mikhmonBillingAutomationIsWorkHour(strtotime('2026-09-01 17:00:00')), 'automatic messages stop at 17:00');
billingAutomationTestAssert(
  mikhmonBillingAutomationNextQueueAttempt(strtotime('2026-09-01 16:50:00'), 15) === strtotime('2026-09-02 07:00:00'),
  'a random slot past closing moves to the next 07:00 window'
);
$queueConfig = mikhmonFonnteReadConfig();
$queueConfig['queue_min_delay_minutes'] = 15;
$queueConfig['queue_max_delay_minutes'] = 15;
$queuedAttempt = mikhmonBillingAutomationQueuedSend('08123456789', 'Test queue', $queueConfig, $workStart);
billingAutomationTestAssert(!empty($queuedAttempt['attempted']), 'the first eligible customer claims the current queue slot');
$storedQueue = mikhmonBillingAutomationReadQueue();
billingAutomationTestAssert((int) ($storedQueue['next_attempt_at'] ?? 0) === $workStart + 900, 'the following customer is delayed by the configured random interval');
$deferredAttempt = mikhmonBillingAutomationQueuedSend('08123456780', 'Test queue 2', $queueConfig, $workStart + 60);
billingAutomationTestAssert(empty($deferredAttempt['attempted']), 'another customer is not sent in the same queue interval');
$failedInvoice = array('automation' => array('last_error_event' => 'reminder', 'last_error_at' => $workStart));
billingAutomationTestAssert(!mikhmonBillingAutomationRetryReady($failedInvoice, 'reminder', $workStart + 1800), 'a failed recipient waits before retrying so later customers can use the queue');
billingAutomationTestAssert(mikhmonBillingAutomationRetryReady($failedInvoice, 'reminder', $workStart + 3600), 'a failed recipient becomes eligible after the retry delay');

$novemberDue = strtotime('2026-11-05 00:00:00');
$latePayment = strtotime('2027-02-01 12:00:00');
billingAutomationTestAssert(date('Y-m-d H:i:s', mikhmonBillingAutomationNextDueTimestamp($novemberDue, $latePayment)) === '2026-12-05 00:00:00', 'late payment advances exactly one monthly billing cycle');
$futureDue = strtotime('2026-10-05 00:00:00');
billingAutomationTestAssert(!mikhmonBillingAutomationPaymentWindowOpen($futureDue, 7, strtotime('2026-09-20 12:00:00')), 'future invoice stays outside the payment window');
billingAutomationTestAssert(mikhmonBillingAutomationPaymentWindowOpen($futureDue, 7, strtotime('2026-09-28 00:00:00')), 'payment window opens on the reminder date');
billingAutomationTestAssert(!mikhmonBillingAutomationIsMonthlyInvoice(array('kind' => 'voucher', 'status' => 'unpaid')), 'voucher invoices are excluded from monthly automation');
billingAutomationTestAssert(mikhmonBillingAutomationIsMonthlyInvoice(array('kind' => 'monthly', 'status' => 'unpaid')), 'monthly invoices remain eligible for automation');
billingAutomationTestAssert(
  mikhmonBillingAutomationLatestUnpaid(array(array('kind' => 'voucher', 'status' => 'unpaid', 'customer_id' => 'voucher-customer')), 'voucher-customer') === array(),
  'voucher invoices are not selected as monthly cron invoices'
);

$bootstrapCustomerId = mikhmonSaveCustomer('router-a', '', 'Bootstrap Customer', '', '', 'hotspot', 'bootstrap-user', 'basic');
$bootstrapCustomer = mikhmonFindCustomer('router-a', $bootstrapCustomerId);
$bootstrapInvoices = array();
$bootstrapDue = time() + (3 * 86400);
$bootstrapInvoice = mikhmonBillingAutomationEnsureInitialInvoice(new BillingBootstrapFakeApi(), 'router-a', $bootstrapInvoices, $bootstrapCustomer, $bootstrapDue);
billingAutomationTestAssert($bootstrapInvoice && $bootstrapInvoice['status'] === 'unpaid', 'bootstrap invoice is generated from router profile');
billingAutomationTestAssert((float) $bootstrapInvoice['amount'] === 150.0 && count($bootstrapInvoice['services']) === 1, 'bootstrap invoice carries profile price and service');
$duplicateBootstrap = mikhmonBillingAutomationEnsureInitialInvoice(new BillingBootstrapFakeApi(), 'router-a', $bootstrapInvoices, $bootstrapCustomer, $bootstrapDue);
billingAutomationTestAssert(!$duplicateBootstrap, 'bootstrap invoice generation is idempotent');

$customerId = mikhmonSaveCustomer('router-a', '', 'Pelanggan A', '08123456789', '', 'hotspot', 'cust-a', 'basic');
billingAutomationTestAssert($customerId !== false, 'customer can be created');
$invoiceId = mikhmonSaveInvoice('router-a', array(
  'id' => 'invoice-a', 'number' => 'INV-A', 'customer_id' => $customerId,
  'customer_name' => 'Pelanggan A', 'amount' => 10000, 'due_date' => date('Y-m-d H:i:s', time() - 3600),
  'status' => 'unpaid', 'created_at' => time() - 7200,
));
billingAutomationTestAssert($invoiceId !== false, 'invoice can be created');

$api = new BillingAutomationFakeApi();
$routerConfig = array(6 => 'router-a&Rp');
$result = mikhmonBillingAutomationProcessSession($api, 'router-a', $routerConfig, mikhmonFonnteReadConfig());
billingAutomationTestAssert($result['isolated'] === 1, 'overdue customer is isolated once');
$savedInvoice = array();
foreach (mikhmonGetInvoices('router-a') as $candidateInvoice) if (($candidateInvoice['id'] ?? '') === $invoiceId) { $savedInvoice = $candidateInvoice; break; }
billingAutomationTestAssert(!empty($savedInvoice['automation']['isolated_at']), 'isolation event is persisted on invoice');

$setSeen = false;
foreach ($api->commands as $command) if ($command[0] === '/ip/hotspot/user/set' && ($command[1]['disabled'] ?? '') === 'yes') $setSeen = true;
billingAutomationTestAssert($setSeen, 'hotspot user is disabled');

$secondApi = new BillingAutomationFakeApi();
$secondResult = mikhmonBillingAutomationProcessSession($secondApi, 'router-a', $routerConfig, mikhmonFonnteReadConfig());
billingAutomationTestAssert($secondResult['isolated'] === 0, 'persisted event prevents duplicate isolation');
$secondSetSeen = false;
foreach ($secondApi->commands as $command) if ($command[0] === '/ip/hotspot/user/set') $secondSetSeen = true;
billingAutomationTestAssert(!$secondSetSeen, 'duplicate run does not disable the service again');

$paidInvoiceId = mikhmonSaveInvoice('router-a', array(
  'id' => 'invoice-paid', 'number' => 'INV-PAID', 'customer_id' => $customerId,
  'customer_name' => 'Pelanggan A', 'amount' => 10000, 'due_date' => date('Y-m-d H:i:s', time() - 86400),
  'status' => 'paid', 'paid_at' => time(), 'created_at' => time() - 7200,
));
$reconcileInvoices = mikhmonGetInvoices('router-a');
$reconcileConfig = mikhmonFonnteReadConfig();
$reconcileConfig['enabled'] = true;
$reconcileConfig['payment_enabled'] = true;
$reconcileConfig['token'] = 'test-token';
$queuedPayments = mikhmonBillingAutomationReconcilePaymentNotifications(
  'router-a',
  $reconcileInvoices,
  array($customerId => mikhmonFindCustomer('router-a', $customerId)),
  $reconcileConfig
);
billingAutomationTestAssert($queuedPayments === 1, 'recent paid invoice missing its flag is recovered into the payment queue');
$queuedPaymentsAgain = mikhmonBillingAutomationReconcilePaymentNotifications(
  'router-a',
  $reconcileInvoices,
  array($customerId => mikhmonFindCustomer('router-a', $customerId)),
  $reconcileConfig
);
billingAutomationTestAssert($queuedPaymentsAgain === 0, 'payment queue reconciliation is idempotent');
$oldPaidInvoice = array(
  'id' => 'invoice-old-paid', 'number' => 'INV-OLD-PAID', 'customer_id' => $customerId,
  'status' => 'paid', 'paid_at' => time() - (8 * 86400),
);
$oldPaidInvoices = array($oldPaidInvoice);
$oldQueuedPayments = mikhmonBillingAutomationReconcilePaymentNotifications(
  'router-a',
  $oldPaidInvoices,
  array($customerId => mikhmonFindCustomer('router-a', $customerId)),
  $reconcileConfig
);
billingAutomationTestAssert($oldQueuedPayments === 0, 'legacy paid invoices outside the recovery window are not messaged');
$paidApi = new BillingAutomationFakeApi();
$paidOnlyResult = mikhmonBillingAutomationProcessSession($paidApi, 'router-a', $routerConfig, array_merge($reconcileConfig, array('enabled' => false)));
$paidSetSeen = false;
foreach ($paidApi->commands as $command) if ($command[0] === '/ip/hotspot/user/set' && ($command[1]['disabled'] ?? '') === 'yes') $paidSetSeen = true;
billingAutomationTestAssert(!$paidSetSeen, 'paid invoices are never isolated by the automation worker');
billingAutomationTestAssert($paidOnlyResult['reminders'] === 0, 'paid invoices never receive an unpaid reminder');

$rendered = mikhmonBillingAutomationMessage('Halo {{nama_pelanggan}} {{total_tagihan}} {{detail_layanan}}', mikhmonFindCustomer('router-a', $customerId), $savedInvoice, 'Rp', 'Mikhmon', $savedInvoice['due_date']);
billingAutomationTestAssert(strpos($rendered, 'Pelanggan A') !== false && strpos($rendered, 'Rp 10.000') !== false, 'template variables are rendered');
$savedInvoice['payment_url'] = 'https://app.midtrans.com/snap/v2/vtweb/test-token';
$renderedWithLink = mikhmonBillingAutomationMessage('Tagihan {{nomor_invoice}}', mikhmonFindCustomer('router-a', $customerId), $savedInvoice, 'Rp', 'Mikhmon', $savedInvoice['due_date']);
billingAutomationTestAssert(strpos($renderedWithLink, $savedInvoice['payment_url']) !== false, 'payment link is included in WhatsApp message');
$paymentConfirmation = mikhmonBillingAutomationMessage('Pembayaran {{nomor_invoice}} diterima', mikhmonFindCustomer('router-a', $customerId), $savedInvoice, 'Rp', 'Mikhmon', $savedInvoice['due_date'], '2026-10-05 00:00:00', false);
billingAutomationTestAssert(strpos($paymentConfirmation, $savedInvoice['payment_url']) === false, 'payment link is excluded from payment confirmation message');

@unlink($databasePath);
@unlink($fonntePath);
@unlink($queuePath);
echo 'billing-automation-tests: OK' . PHP_EOL;
