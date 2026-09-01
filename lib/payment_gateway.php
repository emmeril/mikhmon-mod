<?php
/**
 * Payment gateway configuration and small API clients for Midtrans and Xendit.
 */

function mikhmonPaymentGatewayConfigPath() {
  $override = getenv('MIKHMON_PAYMENT_GATEWAY_CONFIG');
  if ($override !== false && trim($override) !== '') return $override;
  $directory = dirname(__DIR__) . '/data';
  if (!is_dir($directory)) @mkdir($directory, 0700, true);
  @chmod($directory, 0700);
  return $directory . '/payment-gateway.json';
}

function mikhmonPaymentGatewayDefaults() {
  return array(
    'enabled' => false,
    'default_gateway' => 'midtrans',
    'currency' => 'IDR',
    'invoice_duration' => 86400,
    'midtrans' => array(
      'enabled' => false,
      'environment' => 'sandbox',
      'merchant_id' => '',
      'server_key' => '',
      'client_key' => '',
    ),
    'xendit' => array(
      'enabled' => false,
      'secret_key' => '',
      'public_key' => '',
      'webhook_token' => '',
    ),
  );
}

function mikhmonPaymentGatewayCleanSecret($value) {
  return trim(str_replace(array("\r", "\n"), '', (string) $value));
}

function mikhmonPaymentGatewayNormalizeConfig($config) {
  $defaults = mikhmonPaymentGatewayDefaults();
  $config = array_merge($defaults, is_array($config) ? $config : array());
  $config['midtrans'] = array_merge($defaults['midtrans'], isset($config['midtrans']) && is_array($config['midtrans']) ? $config['midtrans'] : array());
  $config['xendit'] = array_merge($defaults['xendit'], isset($config['xendit']) && is_array($config['xendit']) ? $config['xendit'] : array());

  $config['enabled'] = !empty($config['enabled']);
  $config['default_gateway'] = $config['default_gateway'] === 'xendit' ? 'xendit' : 'midtrans';
  $config['currency'] = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $config['currency']));
  if ($config['currency'] === '') $config['currency'] = 'IDR';
  // Xendit invoices accept a maximum duration of 24 hours.
  $config['invoice_duration'] = max(900, min(86400, (int) $config['invoice_duration']));

  $config['midtrans']['enabled'] = !empty($config['midtrans']['enabled']);
  $config['midtrans']['environment'] = $config['midtrans']['environment'] === 'production' ? 'production' : 'sandbox';
  foreach (array('merchant_id', 'server_key', 'client_key') as $key) {
    $config['midtrans'][$key] = mikhmonPaymentGatewayCleanSecret($config['midtrans'][$key]);
  }

  $config['xendit']['enabled'] = !empty($config['xendit']['enabled']);
  foreach (array('secret_key', 'public_key', 'webhook_token') as $key) {
    $config['xendit'][$key] = mikhmonPaymentGatewayCleanSecret($config['xendit'][$key]);
  }
  return $config;
}

function mikhmonPaymentGatewayReadStoredConfig() {
  $config = mikhmonPaymentGatewayDefaults();
  $path = mikhmonPaymentGatewayConfigPath();
  if (is_file($path)) {
    $saved = json_decode((string) @file_get_contents($path), true);
    if (is_array($saved)) $config = $saved;
  }
  return mikhmonPaymentGatewayNormalizeConfig($config);
}

function mikhmonPaymentGatewayReadConfig() {
  $config = mikhmonPaymentGatewayReadStoredConfig();
  $environment = array(
    'MIKHMON_MIDTRANS_MERCHANT_ID' => array('midtrans', 'merchant_id'),
    'MIKHMON_MIDTRANS_SERVER_KEY' => array('midtrans', 'server_key'),
    'MIKHMON_MIDTRANS_CLIENT_KEY' => array('midtrans', 'client_key'),
    'MIKHMON_XENDIT_SECRET_KEY' => array('xendit', 'secret_key'),
    'MIKHMON_XENDIT_PUBLIC_KEY' => array('xendit', 'public_key'),
    'MIKHMON_XENDIT_WEBHOOK_TOKEN' => array('xendit', 'webhook_token'),
  );
  foreach ($environment as $name => $target) {
    $value = getenv($name);
    if ($value !== false && trim($value) !== '') $config[$target[0]][$target[1]] = mikhmonPaymentGatewayCleanSecret($value);
  }
  return mikhmonPaymentGatewayNormalizeConfig($config);
}

function mikhmonPaymentGatewayWriteConfig($config) {
  $config = mikhmonPaymentGatewayNormalizeConfig($config);
  $path = mikhmonPaymentGatewayConfigPath();
  $directory = dirname($path);
  if (!is_dir($directory)) @mkdir($directory, 0700, true);
  @chmod($directory, 0700);
  $temporary = $path . '.tmp.' . getmypid();
  $written = @file_put_contents($temporary, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  if ($written === false) return false;
  @chmod($temporary, 0600);
  if (!@rename($temporary, $path)) {
    @unlink($temporary);
    return false;
  }
  @chmod($path, 0600);
  return true;
}

function mikhmonPaymentGatewayCsrfToken() {
  if (empty($_SESSION['payment_gateway_csrf'])) $_SESSION['payment_gateway_csrf'] = bin2hex(random_bytes(24));
  return (string) $_SESSION['payment_gateway_csrf'];
}

function mikhmonPaymentGatewayValidCsrf($token) {
  return isset($_SESSION['payment_gateway_csrf']) && is_string($token) && hash_equals((string) $_SESSION['payment_gateway_csrf'], $token);
}

function mikhmonPaymentGatewayMask($value) {
  $value = (string) $value;
  $length = strlen($value);
  if ($length === 0) return '';
  if ($length <= 8) return str_repeat('*', $length);
  return substr($value, 0, 4) . str_repeat('*', min(20, $length - 8)) . substr($value, -4);
}

function mikhmonPaymentGatewayBaseUrl() {
  $configured = getenv('MIKHMON_PUBLIC_URL');
  if ($configured !== false && trim($configured) !== '') return rtrim(trim($configured), '/');
  $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
  $scheme = $https ? 'https' : 'http';
  $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) $_SERVER['HTTP_HOST']) : '';
  if ($host === '') return '';
  $scriptDirectory = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])) : '';
  $scriptDirectory = $scriptDirectory === '/' || $scriptDirectory === '.' ? '' : rtrim($scriptDirectory, '/');
  return $scheme . '://' . $host . $scriptDirectory;
}

function mikhmonPaymentGatewayHttpRequest($method, $url, $headers = array(), $payload = null) {
  $method = strtoupper((string) $method);
  $body = false;
  $transportError = '';
  $httpCode = 0;
  $encodedPayload = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);

  if (function_exists('curl_init')) {
    $curl = curl_init();
    $options = array(
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT => 25,
    );
    if ($encodedPayload !== null) $options[CURLOPT_POSTFIELDS] = $encodedPayload;
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $transportError = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
  } elseif (ini_get('allow_url_fopen')) {
    $headerText = implode("\r\n", $headers);
    $contextOptions = array('http' => array(
      'method' => $method,
      'header' => $headerText . ($headerText !== '' ? "\r\n" : ''),
      'timeout' => 25,
      'ignore_errors' => true,
    ));
    if ($encodedPayload !== null) $contextOptions['http']['content'] = $encodedPayload;
    $context = stream_context_create($contextOptions);
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = isset($http_response_header) ? $http_response_header : array();
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) $httpCode = (int) $matches[1];
    if ($body === false) {
      $lastError = error_get_last();
      $transportError = isset($lastError['message']) ? (string) $lastError['message'] : 'transport HTTPS tidak tersedia';
    }
  } else {
    $transportError = 'ekstensi cURL dan allow_url_fopen tidak tersedia';
  }

  $decoded = is_string($body) ? json_decode($body, true) : null;
  return array(
    'success' => $body !== false && $transportError === '' && $httpCode >= 200 && $httpCode < 300,
    'http_code' => $httpCode,
    'data' => is_array($decoded) ? $decoded : array(),
    'raw' => is_string($body) ? $body : '',
    'error' => $transportError,
  );
}

function mikhmonPaymentGatewayErrorMessage($response, $fallback) {
  if (!empty($response['error'])) return (string) $response['error'];
  $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : array();
  foreach (array('message', 'error_code', 'status_message', 'error') as $key) {
    if (isset($data[$key]) && is_scalar($data[$key])) return (string) $data[$key];
  }
  if (!empty($data['error_messages']) && is_array($data['error_messages'])) return implode(', ', array_map('strval', $data['error_messages']));
  if (isset($data['errors'][0]['message'])) return (string) $data['errors'][0]['message'];
  return $fallback;
}

function mikhmonPaymentGatewayMidtransPayload($payment, $config) {
  $customerDetails = array(
    'first_name' => trim((string) ($payment['customer_name'] ?? '')) ?: 'Pelanggan',
  );
  $email = trim((string) ($payment['email'] ?? ''));
  $phone = trim((string) ($payment['phone'] ?? ''));
  if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $customerDetails['email'] = $email;
  if ($phone !== '') $customerDetails['phone'] = $phone;

  return array(
    'transaction_details' => array(
      'order_id' => trim((string) ($payment['order_id'] ?? '')),
      'gross_amount' => (int) round((float) ($payment['amount'] ?? 0)),
    ),
    'customer_details' => $customerDetails,
    'expiry' => array('unit' => 'minute', 'duration' => max(15, (int) ceil($config['invoice_duration'] / 60))),
  );
}

function mikhmonPaymentGatewayMidtransUrlEnvironment($url) {
  $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
  if ($host === '') return '';
  return strpos($host, 'sandbox.midtrans.com') !== false ? 'sandbox' : 'production';
}

function mikhmonPaymentGatewayTestConnection($provider, $config = null) {
  $config = $config === null ? mikhmonPaymentGatewayReadConfig() : mikhmonPaymentGatewayNormalizeConfig($config);
  if ($provider === 'midtrans') {
    $serverKey = $config['midtrans']['server_key'];
    if ($serverKey === '') return array('success' => false, 'message' => 'Server Key Midtrans belum diatur.');
    $baseUrl = $config['midtrans']['environment'] === 'production' ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    $orderId = 'MIKHMON-CREDENTIAL-CHECK-' . time();
    $response = mikhmonPaymentGatewayHttpRequest('GET', $baseUrl . '/v2/' . rawurlencode($orderId) . '/status', array(
      'Accept: application/json',
      'Authorization: Basic ' . base64_encode($serverKey . ':'),
    ));
    if ($response['success'] || $response['http_code'] === 404) {
      return array('success' => true, 'message' => 'Kredensial Midtrans diterima pada mode ' . $config['midtrans']['environment'] . '.');
    }
    return array('success' => false, 'message' => 'Koneksi Midtrans gagal: ' . mikhmonPaymentGatewayErrorMessage($response, 'HTTP ' . $response['http_code']));
  }

  if ($provider === 'xendit') {
    $secretKey = $config['xendit']['secret_key'];
    if ($secretKey === '') return array('success' => false, 'message' => 'Secret API Key Xendit belum diatur.');
    $response = mikhmonPaymentGatewayHttpRequest('GET', 'https://api.xendit.co/balance?account_type=CASH', array(
      'Accept: application/json',
      'Authorization: Basic ' . base64_encode($secretKey . ':'),
    ));
    if ($response['success']) {
      $balance = isset($response['data']['balance']) ? ' Saldo CASH: ' . number_format((float) $response['data']['balance'], 0, ',', '.') . '.' : '';
      return array('success' => true, 'message' => 'Koneksi Xendit berhasil.' . $balance);
    }
    return array('success' => false, 'message' => 'Koneksi Xendit gagal: ' . mikhmonPaymentGatewayErrorMessage($response, 'HTTP ' . $response['http_code']));
  }

  return array('success' => false, 'message' => 'Payment gateway tidak dikenal.');
}

function mikhmonPaymentGatewayGetMidtransStatus($orderId, $config = null) {
  $config = $config === null ? mikhmonPaymentGatewayReadConfig() : mikhmonPaymentGatewayNormalizeConfig($config);
  $orderId = trim((string) $orderId);
  if ($orderId === '' || $config['midtrans']['server_key'] === '') return array('success' => false, 'message' => 'Order ID atau Server Key Midtrans belum tersedia.');
  $baseUrl = $config['midtrans']['environment'] === 'production' ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
  $response = mikhmonPaymentGatewayHttpRequest('GET', $baseUrl . '/v2/' . rawurlencode($orderId) . '/status', array(
    'Accept: application/json',
    'Authorization: Basic ' . base64_encode($config['midtrans']['server_key'] . ':'),
  ));
  if (!$response['success']) return array('success' => false, 'message' => mikhmonPaymentGatewayErrorMessage($response, 'HTTP ' . $response['http_code']), 'response' => $response);
  $data = $response['data'];
  // Midtrans can return HTTP 200 while reporting an application-level 404
  // (for example when an order belongs to another environment).
  if (isset($data['status_code']) && (string) $data['status_code'] !== '200') {
    return array('success' => false, 'message' => (string) ($data['status_message'] ?? 'Transaksi Midtrans tidak ditemukan.'), 'response' => $data);
  }
  return array(
    'success' => true,
    'paid' => mikhmonPaymentGatewayMidtransPaid($data),
    'amount' => (float) ($data['gross_amount'] ?? 0),
    'status' => (string) ($data['transaction_status'] ?? 'unknown'),
    'reference' => (string) ($data['transaction_id'] ?? ''),
    'response' => $data,
  );
}

function mikhmonPaymentGatewayGetMidtransSnapStatus($token, $config = null) {
  $config = $config === null ? mikhmonPaymentGatewayReadConfig() : mikhmonPaymentGatewayNormalizeConfig($config);
  $token = trim((string) $token);
  if ($token === '' || $config['midtrans']['server_key'] === '') return array('success' => false, 'message' => 'Token Snap atau Server Key Midtrans belum tersedia.');
  $baseUrl = $config['midtrans']['environment'] === 'production' ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
  $response = mikhmonPaymentGatewayHttpRequest('GET', $baseUrl . '/snap/v1/transactions/' . rawurlencode($token) . '/status', array(
    'Accept: application/json',
    'Authorization: Basic ' . base64_encode($config['midtrans']['server_key'] . ':'),
  ));
  if (!$response['success']) return array('success' => false, 'message' => mikhmonPaymentGatewayErrorMessage($response, 'HTTP ' . $response['http_code']), 'response' => $response);
  $data = $response['data'];
  if (isset($data['status_code']) && (string) $data['status_code'] !== '200') return array('success' => false, 'message' => (string) ($data['status_message'] ?? 'Transaksi Snap tidak ditemukan.'), 'response' => $data);
  return array(
    'success' => true,
    'paid' => mikhmonPaymentGatewayMidtransPaid($data),
    'amount' => (float) ($data['gross_amount'] ?? 0),
    'status' => (string) ($data['transaction_status'] ?? 'unknown'),
    'reference' => (string) ($data['transaction_id'] ?? ''),
    'order_id' => (string) ($data['order_id'] ?? ''),
    'response' => $data,
  );
}

function mikhmonPaymentGatewayCreatePayment($provider, $payment, $config = null) {
  $config = $config === null ? mikhmonPaymentGatewayReadConfig() : mikhmonPaymentGatewayNormalizeConfig($config);
  if (empty($config['enabled'])) return array('success' => false, 'message' => 'Payment gateway belum diaktifkan.');
  $provider = $provider === '' ? $config['default_gateway'] : $provider;
  $orderId = trim((string) ($payment['order_id'] ?? ''));
  $amount = (int) round((float) ($payment['amount'] ?? 0));
  if ($orderId === '' || $amount < 1) return array('success' => false, 'message' => 'Order ID dan nominal pembayaran wajib diisi.');

  if ($provider === 'midtrans') {
    if (empty($config['midtrans']['enabled']) || $config['midtrans']['server_key'] === '') return array('success' => false, 'message' => 'Midtrans belum aktif atau Server Key belum diatur.');
    $url = $config['midtrans']['environment'] === 'production'
      ? 'https://app.midtrans.com/snap/v1/transactions'
      : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    $payload = mikhmonPaymentGatewayMidtransPayload($payment, $config);
    $response = mikhmonPaymentGatewayHttpRequest('POST', $url, array(
      'Accept: application/json',
      'Content-Type: application/json',
      'Authorization: Basic ' . base64_encode($config['midtrans']['server_key'] . ':'),
    ), $payload);
    if (!$response['success'] || empty($response['data']['redirect_url'])) {
      return array('success' => false, 'message' => 'Transaksi Midtrans gagal: ' . mikhmonPaymentGatewayErrorMessage($response, 'respons tidak valid'), 'response' => $response);
    }
    return array('success' => true, 'provider' => 'midtrans', 'environment' => $config['midtrans']['environment'], 'payment_url' => $response['data']['redirect_url'], 'reference' => $response['data']['token'] ?? '', 'response' => $response['data']);
  }

  if ($provider === 'xendit') {
    if (empty($config['xendit']['enabled']) || $config['xendit']['secret_key'] === '') return array('success' => false, 'message' => 'Xendit belum aktif atau Secret API Key belum diatur.');
    $payload = array(
      'external_id' => $orderId,
      'amount' => $amount,
      'description' => (string) ($payment['description'] ?? ('Pembayaran ' . $orderId)) . (!empty($payment['customer_name']) ? ' - ' . (string) $payment['customer_name'] : ''),
      'invoice_duration' => $config['invoice_duration'],
      'currency' => $config['currency'],
    );
    foreach (array('success_redirect_url', 'failure_redirect_url') as $key) {
      if (!empty($payment[$key])) $payload[$key] = (string) $payment[$key];
    }
    $response = mikhmonPaymentGatewayHttpRequest('POST', 'https://api.xendit.co/v2/invoices', array(
      'Accept: application/json',
      'Content-Type: application/json',
      'Authorization: Basic ' . base64_encode($config['xendit']['secret_key'] . ':'),
    ), $payload);
    if (!$response['success'] || empty($response['data']['invoice_url'])) {
      return array('success' => false, 'message' => 'Invoice Xendit gagal: ' . mikhmonPaymentGatewayErrorMessage($response, 'respons tidak valid'), 'response' => $response);
    }
    return array('success' => true, 'provider' => 'xendit', 'payment_url' => $response['data']['invoice_url'], 'reference' => $response['data']['id'] ?? '', 'response' => $response['data']);
  }

  return array('success' => false, 'message' => 'Payment gateway tidak dikenal.');
}

function mikhmonPaymentGatewayValidMidtransNotification($notification, $serverKey) {
  if (!is_array($notification) || empty($notification['signature_key'])) return false;
  $signature = hash('sha512', (string) ($notification['order_id'] ?? '') . (string) ($notification['status_code'] ?? '') . (string) ($notification['gross_amount'] ?? '') . mikhmonPaymentGatewayCleanSecret($serverKey));
  return hash_equals($signature, (string) $notification['signature_key']);
}

function mikhmonPaymentGatewayMidtransPaid($notification) {
  $status = strtolower((string) ($notification['transaction_status'] ?? ''));
  if ($status === 'settlement') return true;
  return $status === 'capture' && strtolower((string) ($notification['fraud_status'] ?? 'accept')) === 'accept';
}

function mikhmonPaymentGatewayValidXenditCallback($headerToken, $configuredToken) {
  $configuredToken = mikhmonPaymentGatewayCleanSecret($configuredToken);
  return $configuredToken !== '' && is_string($headerToken) && hash_equals($configuredToken, trim($headerToken));
}

function mikhmonPaymentGatewayXenditPaid($notification) {
  $status = strtoupper((string) ($notification['status'] ?? ''));
  return $status === 'PAID' || $status === 'SETTLED';
}
