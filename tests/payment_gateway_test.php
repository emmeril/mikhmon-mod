<?php
session_save_path('/tmp');
session_start();
require dirname(__DIR__) . '/lib/payment_gateway.php';

function paymentGatewayTestAssert($condition, $message) {
  if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$configPath = tempnam(sys_get_temp_dir(), 'mikhmon-payment-');
putenv('MIKHMON_PAYMENT_GATEWAY_CONFIG=' . $configPath);

$config = mikhmonPaymentGatewayNormalizeConfig(array(
  'enabled' => true,
  'default_gateway' => 'xendit',
  'currency' => 'idr',
  'invoice_duration' => 5,
  'midtrans' => array('enabled' => true, 'environment' => 'production', 'server_key' => "server\nkey"),
  'xendit' => array('enabled' => true, 'secret_key' => 'secret-key'),
));
paymentGatewayTestAssert($config['enabled'] === true && !isset($config['default_gateway']) && !isset($config['xendit']), 'legacy providers are removed during normalization');
paymentGatewayTestAssert($config['invoice_duration'] === 900, 'invoice duration is bounded');
paymentGatewayTestAssert($config['midtrans']['server_key'] === 'serverkey', 'secrets remove line breaks');
paymentGatewayTestAssert(mikhmonPaymentGatewayWriteConfig($config), 'configuration can be written atomically');
$read = mikhmonPaymentGatewayReadStoredConfig();
paymentGatewayTestAssert($read['midtrans']['server_key'] === 'serverkey' && !isset($read['xendit']), 'Midtrans-only configuration can be read back');

$csrf = mikhmonPaymentGatewayCsrfToken();
paymentGatewayTestAssert(mikhmonPaymentGatewayValidCsrf($csrf), 'generated csrf is accepted');
paymentGatewayTestAssert(!mikhmonPaymentGatewayValidCsrf('invalid'), 'invalid csrf is rejected');
paymentGatewayTestAssert(mikhmonPaymentGatewayMask('abcdefghijkl') === 'abcd****ijkl', 'secrets are masked for display');
$midtransPayload = mikhmonPaymentGatewayMidtransPayload(array(
  'order_id' => 'INV-1', 'amount' => 1000, 'customer_name' => 'Pelanggan', 'email' => '', 'phone' => '08123456789',
), $read);
paymentGatewayTestAssert(!isset($midtransPayload['customer_details']['email']), 'empty Midtrans email is omitted');
paymentGatewayTestAssert($midtransPayload['customer_details']['phone'] === '08123456789', 'available Midtrans phone is included');
$midtransError = mikhmonPaymentGatewayErrorMessage(array('data' => array('error_messages' => array('invalid email'))), 'fallback');
paymentGatewayTestAssert($midtransError === 'invalid email', 'Midtrans error messages are exposed');
paymentGatewayTestAssert(mikhmonPaymentGatewayMidtransUrlEnvironment('https://app.sandbox.midtrans.com/snap/v4/redirection/token') === 'sandbox', 'sandbox payment URL is detected');
paymentGatewayTestAssert(mikhmonPaymentGatewayMidtransUrlEnvironment('https://app.midtrans.com/snap/v4/redirection/token') === 'production', 'production payment URL is detected');
paymentGatewayTestAssert(!mikhmonPaymentGatewayCreatePayment('midtrans', array('order_id' => 'INV-1', 'amount' => 1000), array('enabled' => false))['success'], 'disabled gateway does not create payments');
paymentGatewayTestAssert(!mikhmonPaymentGatewayCreatePayment('xendit', array('order_id' => 'INV-1', 'amount' => 1000), $read)['success'], 'unsupported providers cannot create payments');
paymentGatewayTestAssert(!mikhmonPaymentGatewayTestConnection('xendit', $read)['success'], 'unsupported providers cannot be tested');
paymentGatewayTestAssert(mikhmonPaymentGatewayValidMidtransNotification(array(
  'order_id' => 'INV-1', 'status_code' => '200', 'gross_amount' => '1000',
  'signature_key' => hash('sha512', 'INV-12001000server'),
), 'server'), 'Midtrans signatures validate');
paymentGatewayTestAssert(mikhmonPaymentGatewayMidtransPaid(array('transaction_status' => 'settlement')), 'Midtrans settlement is paid');

@unlink($configPath);
echo 'payment-gateway-tests: OK' . PHP_EOL;
