<?php

function mikhmonEncryptionKey() {
  $environmentKey = getenv('MIKHMON_ENCRYPTION_KEY');
  if ($environmentKey !== false && trim($environmentKey) !== '') {
    return hash('sha256', (string) $environmentKey, true);
  }
  $keyPath = dirname(__DIR__) . '/data/.encryption-key';
  if (is_file($keyPath)) {
    $key = base64_decode(trim((string) @file_get_contents($keyPath)), true);
    if (is_string($key) && strlen($key) === 32) return $key;
  }
  $key = random_bytes(32);
  if (!is_dir(dirname($keyPath))) @mkdir(dirname($keyPath), 0700, true);
  if (@file_put_contents($keyPath, base64_encode($key), LOCK_EX) === false) return false;
  @chmod($keyPath, 0600);
  return $key;
}

function mikhmonEncryptSecret($plaintext) {
  if (!function_exists('openssl_encrypt')) return false;
  $key = mikhmonEncryptionKey();
  if ($key === false) return false;
  $iv = random_bytes(12);
  $tag = '';
  $ciphertext = openssl_encrypt((string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  return $ciphertext === false ? false : 'v2:' . base64_encode($iv . $tag . $ciphertext);
}

function mikhmonDecryptSecret($encrypted) {
  if (strpos((string) $encrypted, 'v2:') !== 0 || !function_exists('openssl_decrypt')) return false;
  $payload = base64_decode(substr((string) $encrypted, 3), true);
  $key = mikhmonEncryptionKey();
  if ($payload === false || strlen($payload) < 29 || $key === false) return false;
  $plaintext = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
  return $plaintext === false ? false : $plaintext;
}
