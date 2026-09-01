<?php
session_save_path('/tmp');
session_start();
require dirname(__DIR__) . '/lib/payment_activation.php';

function paymentActivationTestAssert($condition, $message) {
  if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

class PaymentActivationFakeApi {
  public $commands = array();
  public $failOnSet = '';
  public function comm($command, $args = array()) {
    $this->commands[] = array($command, $args);
    if ($command === '/ip/hotspot/user/profile/print') return array(array('name' => 'basic', 'on-login' => ':put (",none,100,30d,100,")'));
    if ($command === '/ppp/profile/print') return array();
    if ($command === '/ip/hotspot/user/print') {
      $name = (string) ($args['?name'] ?? 'cust-a');
      $id = $name === 'cust-b2' ? '*2' : '*1';
      return array(array('.id' => $id, 'name' => $name, 'disabled' => 'yes', 'limit-uptime' => '1s', 'comment' => 'old'));
    }
    if ($command === '/ip/hotspot/user/set' && $this->failOnSet !== '' && ($args['.id'] ?? '') === $this->failOnSet) return array('!trap' => array(array('message' => 'set failed')));
    if ($command === '/system/scheduler/print') return array();
    return array();
  }
}

$databasePath = tempnam(sys_get_temp_dir(), 'mikhmon-activation-');
putenv('MIKHMON_DATABASE_PATH=' . $databasePath);
$fonntePath = tempnam(sys_get_temp_dir(), 'mikhmon-activation-fonnte-');
putenv('MIKHMON_FONNTE_CONFIG=' . $fonntePath);
mikhmonFonnteWriteConfig(array('enabled' => false));

$customerId = mikhmonSaveCustomer('router-a', '', 'Pelanggan A', '', '', 'hotspot', 'cust-a', 'basic');
$invoiceId = mikhmonSaveInvoice('router-a', array(
  'id' => 'invoice-paid-gateway', 'number' => 'INV-ACTIVATE', 'customer_id' => $customerId,
  'customer_name' => 'Pelanggan A', 'services' => array(array('id'=>'service-a','service'=>'hotspot','username'=>'cust-a','profile'=>'basic','amount'=>100)),
  'service_count' => 1, 'amount' => 100, 'due_date' => date('Y-m-d H:i:s', time() - 3600), 'status' => 'unpaid',
  'gateway_payment_received' => true, 'payment_gateway' => 'midtrans', 'gateway_paid_at' => time(), 'created_at' => time() - 10,
));
$api = new PaymentActivationFakeApi();
$result = mikhmonPaymentActivationProcess('router-a', $invoiceId, $api, array('actor_name' => 'Otomatis MIDTRANS'));
paymentActivationTestAssert(!empty($result['success']), 'gateway payment can activate services');
$saved = mikhmonGetInvoices('router-a');
paymentActivationTestAssert($saved[0]['status'] === 'paid' && !empty($saved[0]['next_invoice_id']), 'paid invoice and next invoice are saved');
$setSeen = false;
foreach ($api->commands as $command) if ($command[0] === '/ip/hotspot/user/set' && ($command[1]['disabled'] ?? '') === 'no') $setSeen = true;
paymentActivationTestAssert($setSeen, 'hotspot user is enabled');
$duplicateResult = mikhmonPaymentActivationProcess('router-a', $invoiceId, $api, array('actor_name' => 'Otomatis MIDTRANS'));
paymentActivationTestAssert(!empty($duplicateResult['success']) && !empty($duplicateResult['already_paid']), 'duplicate callback is idempotent');
paymentActivationTestAssert(count(mikhmonGetInvoices('router-a')) === 2, 'duplicate callback does not create another billing cycle');

$customerFail = mikhmonSaveCustomer('router-b', '', 'Pelanggan B', '', '', 'hotspot', 'cust-b1', 'basic');
mikhmonAddCustomerService('router-b', $customerFail, array('service'=>'hotspot','username'=>'cust-b2','profile'=>'basic'));
$invoiceFail = mikhmonSaveInvoice('router-b', array(
  'id' => 'invoice-failed-gateway', 'number' => 'INV-FAIL', 'customer_id' => $customerFail,
  'services' => mikhmonCustomerServices(mikhmonFindCustomer('router-b', $customerFail)),
  'amount' => 200, 'status' => 'unpaid', 'gateway_payment_received' => true,
));
$failed = new PaymentActivationFakeApi();
$failed->failOnSet = '*2';
$failureResult = mikhmonPaymentActivationProcess('router-b', $invoiceFail, $failed, array('actor_name' => 'Otomatis MIDTRANS'));
paymentActivationTestAssert(empty($failureResult['success']) && ($failureResult['invoice']['activation_status'] ?? '') === 'failed', 'failed activation remains retryable');
$rollbackSeen = false;
foreach ($failed->commands as $command) if ($command[0] === '/ip/hotspot/user/set' && ($command[1]['.id'] ?? '') === '*1' && ($command[1]['disabled'] ?? '') === 'yes') $rollbackSeen = true;
paymentActivationTestAssert($rollbackSeen, 'partial activation is rolled back');

@unlink($databasePath);
@unlink($fonntePath);
echo 'payment-activation-tests: OK' . PHP_EOL;
