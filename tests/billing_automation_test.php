<?php

session_save_path('/tmp');
session_start();
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
mikhmonFonnteWriteConfig(array('enabled' => false, 'automation_enabled' => true, 'reminder_enabled' => false, 'isolation_enabled' => true));

$novemberDue = strtotime('2026-11-05 00:00:00');
$latePayment = strtotime('2027-02-01 12:00:00');
billingAutomationTestAssert(date('Y-m-d H:i:s', mikhmonBillingAutomationNextDueTimestamp($novemberDue, $latePayment)) === '2026-12-05 00:00:00', 'late payment advances exactly one monthly billing cycle');
$futureDue = strtotime('2026-10-05 00:00:00');
billingAutomationTestAssert(!mikhmonBillingAutomationPaymentWindowOpen($futureDue, 7, strtotime('2026-09-20 12:00:00')), 'future invoice stays outside the payment window');
billingAutomationTestAssert(mikhmonBillingAutomationPaymentWindowOpen($futureDue, 7, strtotime('2026-09-28 00:00:00')), 'payment window opens on the reminder date');

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

$rendered = mikhmonBillingAutomationMessage('Halo {{nama_pelanggan}} {{total_tagihan}} {{detail_layanan}}', mikhmonFindCustomer('router-a', $customerId), $savedInvoice, 'Rp', 'Mikhmon', $savedInvoice['due_date']);
billingAutomationTestAssert(strpos($rendered, 'Pelanggan A') !== false && strpos($rendered, 'Rp 10.000') !== false, 'template variables are rendered');
$savedInvoice['payment_url'] = 'https://app.midtrans.com/snap/v2/vtweb/test-token';
$renderedWithLink = mikhmonBillingAutomationMessage('Tagihan {{nomor_invoice}}', mikhmonFindCustomer('router-a', $customerId), $savedInvoice, 'Rp', 'Mikhmon', $savedInvoice['due_date']);
billingAutomationTestAssert(strpos($renderedWithLink, $savedInvoice['payment_url']) !== false, 'payment link is included in WhatsApp message');

@unlink($databasePath);
@unlink($fonntePath);
echo 'billing-automation-tests: OK' . PHP_EOL;
