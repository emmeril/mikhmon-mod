<?php
/**
 * Small Fonnte API client and configuration store for Mikhmon.
 */

function mikhmonFonnteConfigPath() {
  $override = getenv('MIKHMON_FONNTE_CONFIG');
  if ($override !== false && trim($override) !== '') return $override;
  $directory = dirname(__DIR__) . '/data';
  if (!is_dir($directory)) @mkdir($directory, 0700, true);
  @chmod($directory, 0700);
  return $directory . '/fonnte.json';
}

function mikhmonFonnteDefaults() {
  return array('enabled' => false, 'token' => '', 'country_code' => '62');
}

function mikhmonFonnteNormalizeToken($token) {
  return trim(str_replace(array("\r", "\n"), '', (string) $token));
}

function mikhmonFonnteCsrfToken() {
  if (empty($_SESSION['fonnte_csrf'])) $_SESSION['fonnte_csrf'] = bin2hex(random_bytes(24));
  return (string) $_SESSION['fonnte_csrf'];
}

function mikhmonFonnteValidCsrf($token) {
  return isset($_SESSION['fonnte_csrf']) && is_string($token) && hash_equals((string) $_SESSION['fonnte_csrf'], $token);
}

function mikhmonFonnteReadStoredConfig() {
  $config = mikhmonFonnteDefaults();
  $path = mikhmonFonnteConfigPath();
  if (is_file($path)) {
    $saved = json_decode((string) @file_get_contents($path), true);
    if (is_array($saved)) $config = array_merge($config, $saved);
  }
  $config['enabled'] = !empty($config['enabled']);
  $config['token'] = mikhmonFonnteNormalizeToken($config['token'] ?? '');
  $config['country_code'] = preg_replace('/[^0-9]/', '', (string) ($config['country_code'] ?? '62'));
  if ($config['country_code'] === '') $config['country_code'] = '62';
  return $config;
}

function mikhmonFonnteReadConfig() {
  $config = mikhmonFonnteReadStoredConfig();
  $environmentToken = getenv('MIKHMON_FONNTE_TOKEN');
  if ($environmentToken !== false && trim($environmentToken) !== '') $config['token'] = mikhmonFonnteNormalizeToken($environmentToken);
  return $config;
}

function mikhmonFonnteWriteConfig($config) {
  $defaults = mikhmonFonnteDefaults();
  $config = array_merge($defaults, is_array($config) ? $config : array());
  $config['enabled'] = !empty($config['enabled']);
  $config['token'] = mikhmonFonnteNormalizeToken($config['token']);
  $config['country_code'] = preg_replace('/[^0-9]/', '', (string) $config['country_code']);
  if ($config['country_code'] === '') $config['country_code'] = '62';
  $path = mikhmonFonnteConfigPath();
  $directory = dirname($path);
  if (!is_dir($directory)) @mkdir($directory, 0700, true);
  @chmod($directory, 0700);
  $temporary = $path . '.tmp.' . getmypid();
  $written = @file_put_contents($temporary, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  if ($written === false) return false;
  @chmod($temporary, 0600);
  if (!@rename($temporary, $path)) { @unlink($temporary); return false; }
  @chmod($path, 0600);
  return true;
}

function mikhmonFonnteRequest($endpoint, $token, $fields = array()) {
  $token = mikhmonFonnteNormalizeToken($token);
  if ($token === '') return array('status' => false, 'reason' => 'Token Fonnte belum diatur.');
  $url = 'https://api.fonnte.com/' . ltrim((string) $endpoint, '/');
  $body = false;
  $transportError = '';
  $httpCode = 0;
  if (function_exists('curl_init')) {
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $fields,
      CURLOPT_HTTPHEADER => array('Authorization: ' . $token),
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT => 20,
    ));
    $body = curl_exec($curl);
    $transportError = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
  } elseif (ini_get('allow_url_fopen')) {
    $context = stream_context_create(array('http' => array(
      'method' => 'POST',
      'header' => "Authorization: " . $token . "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
      'content' => http_build_query($fields, '', '&'),
      'timeout' => 20,
      'ignore_errors' => true,
    )));
    $body = @file_get_contents($url, false, $context);
    $headers = isset($http_response_header) ? $http_response_header : array();
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) $httpCode = (int) $matches[1];
    if ($body === false) {
      $lastError = error_get_last();
      $transportError = isset($lastError['message']) ? (string) $lastError['message'] : 'transport HTTPS tidak tersedia';
    }
  } else {
    $transportError = 'ekstensi cURL dan allow_url_fopen tidak tersedia';
  }
  if ($body === false || $transportError !== '') return array('status' => false, 'reason' => 'Koneksi ke Fonnte gagal: ' . $transportError, 'http_code' => $httpCode);
  $response = json_decode((string) $body, true);
  if (!is_array($response)) return array('status' => false, 'reason' => 'Respons Fonnte tidak valid.', 'http_code' => $httpCode, 'raw' => $body);
  if (empty($response['status'])) {
    $reason = isset($response['reason']) ? (string) $response['reason'] : (isset($response['detail']) ? (string) $response['detail'] : 'Permintaan Fonnte ditolak.');
    $response['reason'] = $reason;
  }
  $response['http_code'] = $httpCode;
  return $response;
}

function mikhmonFonnteSend($target, $message, $config = null) {
  $config = $config === null ? mikhmonFonnteReadConfig() : $config;
  if (empty($config['enabled'])) return array('status' => false, 'reason' => 'Gateway Fonnte belum diaktifkan.');
  $target = preg_replace('/[^0-9,]/', '', (string) $target);
  $message = trim((string) $message);
  if ($target === '') return array('status' => false, 'reason' => 'Nomor WhatsApp pelanggan kosong atau tidak valid.');
  if ($message === '') return array('status' => false, 'reason' => 'Pesan WhatsApp kosong.');
  return mikhmonFonnteRequest('send', $config['token'], array(
    'target' => $target,
    'message' => $message,
    'countryCode' => (string) $config['country_code'],
  ));
}

function mikhmonFonnteDevice($config = null) {
  $config = $config === null ? mikhmonFonnteReadConfig() : $config;
  return mikhmonFonnteRequest('device', $config['token'], array());
}
