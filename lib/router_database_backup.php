<?php

// Encrypted application database snapshots stored as inert RouterOS scripts.
// The recovery password is never stored on the router.

function mikhmonRouterDatabaseBackupSettingsPath() {
  $databasePath = mikhmonBackupPath();
  $override = getenv('MIKHMON_DATABASE_PATH');
  if ($override !== false && trim((string) $override) !== '') return $databasePath . '.router-script-backup.json';
  return dirname($databasePath) . '/router-database-backup.json';
}

function mikhmonReadRouterDatabaseBackupSettings() {
  $path = mikhmonRouterDatabaseBackupSettingsPath();
  $settings = is_file($path) ? json_decode((string) @file_get_contents($path), true) : array();
  if (!is_array($settings)) $settings = array();
  if (!isset($settings['sessions']) || !is_array($settings['sessions'])) $settings['sessions'] = array();
  return $settings;
}

function mikhmonWriteRouterDatabaseBackupSettings($settings) {
  $path = mikhmonRouterDatabaseBackupSettingsPath();
  $tmp = $path . '.tmp.' . getmypid();
  $json = json_encode($settings, JSON_UNESCAPED_SLASHES);
  if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  @chmod($tmp, 0600);
  return @rename($tmp, $path);
}

function mikhmonConfigureRouterDatabaseBackup($session, $password, $enabled = true, $payloadHash = '') {
  $session = trim((string) $session);
  $password = (string) $password;
  if ($session === '' || ($enabled && strlen($password) < 8)) return false;
  $settings = mikhmonReadRouterDatabaseBackupSettings();
  $current = isset($settings['sessions'][$session]) && is_array($settings['sessions'][$session]) ? $settings['sessions'][$session] : array();
  if ($enabled) {
    $encryptedPassword = mikhmonEncryptSecret($password);
    if ($encryptedPassword === false) return false;
    $current['password'] = $encryptedPassword;
  }
  $current['enabled'] = (bool) $enabled;
  if ($payloadHash !== '') $current['last_payload_hash'] = (string) $payloadHash;
  $current['updated_at'] = time();
  $settings['sessions'][$session] = $current;
  return mikhmonWriteRouterDatabaseBackupSettings($settings);
}

function mikhmonRouterDatabaseBackupPayload($session) {
  $database = mikhmonReadDatabase();
  $users = array();
  foreach ((array) ($database['users'] ?? array()) as $user) {
    if (!is_array($user)) continue;
    if (($user['role'] ?? '') === 'admin' || (string) ($user['session'] ?? '') === (string) $session) $users[] = $user;
  }
  return array(
    'format' => 'mikhmon-router-database',
    'version' => 1,
    'session' => (string) $session,
    'created_at' => time(),
    'customers' => array_values((array) ($database['customers'][$session] ?? array())),
    'invoices' => array_values((array) ($database['invoices'][$session] ?? array())),
    'report_records' => array_values((array) ($database['report_records'][$session] ?? array())),
    'users' => array_values($users),
  );
}

function mikhmonRouterDatabasePayloadHash($payload) {
  $copy = (array) $payload;
  unset($copy['created_at']);
  return hash('sha256', json_encode($copy, JSON_UNESCAPED_SLASHES));
}

function mikhmonEncryptRouterDatabasePayload($payload, $password) {
  if (!function_exists('openssl_encrypt') || strlen((string) $password) < 8) return false;
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  if ($json === false) return false;
  $compressed = function_exists('gzencode') ? gzencode($json, 6) : false;
  $plaintext = $compressed !== false ? 'G' . $compressed : 'J' . $json;
  $salt = random_bytes(16);
  $iv = random_bytes(12);
  $key = hash_pbkdf2('sha256', (string) $password, $salt, 120000, 32, true);
  $tag = '';
  $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($ciphertext === false) return false;
  return 'v1:' . base64_encode($salt . $iv . $tag . $ciphertext);
}

function mikhmonDecryptRouterDatabasePayload($encrypted, $password) {
  if (strpos((string) $encrypted, 'v1:') !== 0 || !function_exists('openssl_decrypt')) return false;
  $binary = base64_decode(substr((string) $encrypted, 3), true);
  if ($binary === false || strlen($binary) < 45) return false;
  $salt = substr($binary, 0, 16);
  $iv = substr($binary, 16, 12);
  $tag = substr($binary, 28, 16);
  $ciphertext = substr($binary, 44);
  $key = hash_pbkdf2('sha256', (string) $password, $salt, 120000, 32, true);
  $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($plaintext === false || $plaintext === '') return false;
  if ($plaintext[0] === 'G' && function_exists('gzdecode')) $json = gzdecode(substr($plaintext, 1));
  elseif ($plaintext[0] === 'J') $json = substr($plaintext, 1);
  else return false;
  if ($json === false) return false;
  $payload = json_decode($json, true);
  return is_array($payload) ? $payload : false;
}

function mikhmonRouterScriptApiError($response) {
  if (!is_array($response)) return '';
  foreach (array('!trap', '!fatal') as $type) {
    if (isset($response[$type][0]['message'])) return (string) $response[$type][0]['message'];
  }
  return '';
}

function mikhmonRouterScriptSource($value) {
  return ':local mikhmonBackup "' . (string) $value . '"';
}

function mikhmonRouterScriptSourceValue($source) {
  if (!preg_match('~^:local mikhmonBackup "([A-Za-z0-9+/=:_-]*)"$~', trim((string) $source), $match)) return false;
  return $match[1];
}

function mikhmonRouterDatabaseScriptRows($API) {
  if (!is_object($API) || !method_exists($API, 'comm')) return false;
  $rows = $API->comm('/system/script/print');
  if (!is_array($rows) || mikhmonRouterScriptApiError($rows) !== '') return false;
  return $rows;
}

function mikhmonRouterDatabaseManifestFromRows($rows) {
  foreach ((array) $rows as $row) {
    if (!is_array($row) || (string) ($row['name'] ?? '') !== 'mikhmon-db-manifest') continue;
    $encoded = mikhmonRouterScriptSourceValue($row['source'] ?? '');
    $json = $encoded !== false ? base64_decode($encoded, true) : false;
    $manifest = $json !== false ? json_decode($json, true) : false;
    if (is_array($manifest) && ($manifest['format'] ?? '') === 'mikhmon-router-database') {
      $manifest['_script_id'] = (string) ($row['.id'] ?? '');
      return $manifest;
    }
  }
  return false;
}

function mikhmonRouterDatabaseManifest($API) {
  $rows = mikhmonRouterDatabaseScriptRows($API);
  return $rows === false ? false : mikhmonRouterDatabaseManifestFromRows($rows);
}

function mikhmonRemoveRouterDatabaseGeneration($API, $rows, $generation) {
  $prefix = 'mikhmon-db-' . (string) $generation . '-';
  foreach ((array) $rows as $row) {
    if (strpos((string) ($row['name'] ?? ''), $prefix) !== 0 || empty($row['.id'])) continue;
    $API->comm('/system/script/remove', array('.id' => $row['.id']));
  }
}

function mikhmonStoreRouterDatabaseBackup($API, $session, $password) {
  if (strlen((string) $password) < 8) return array('status' => false, 'error' => 'Password pemulihan minimal 8 karakter.');
  $existingRows = mikhmonRouterDatabaseScriptRows($API);
  if ($existingRows === false) return array('status' => false, 'error' => 'Daftar System Script MikroTik tidak dapat dibaca.');
  $payload = mikhmonRouterDatabaseBackupPayload($session);
  $payloadHash = mikhmonRouterDatabasePayloadHash($payload);
  $encrypted = mikhmonEncryptRouterDatabasePayload($payload, $password);
  if ($encrypted === false) return array('status' => false, 'error' => 'Database gagal dienkripsi.');

  $generation = date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
  $chunks = str_split($encrypted, 6000);
  foreach ($chunks as $index => $chunk) {
    $name = 'mikhmon-db-' . $generation . '-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
    $response = $API->comm('/system/script/add', array(
      'name' => $name,
      'source' => mikhmonRouterScriptSource($chunk),
      'comment' => 'MIKHMON encrypted database backup',
      'policy' => 'read',
    ));
    $error = mikhmonRouterScriptApiError($response);
    if ($error !== '') {
      mikhmonRemoveRouterDatabaseGeneration($API, mikhmonRouterDatabaseScriptRows($API), $generation);
      return array('status' => false, 'error' => 'Chunk backup gagal disimpan: ' . $error);
    }
  }

  $writtenRows = mikhmonRouterDatabaseScriptRows($API);
  $written = '';
  $chunkRows = array();
  foreach ((array) $writtenRows as $row) {
    $name = (string) ($row['name'] ?? '');
    $prefix = 'mikhmon-db-' . $generation . '-';
    if (strpos($name, $prefix) !== 0) continue;
    $chunkRows[$name] = $row;
  }
  ksort($chunkRows, SORT_STRING);
  foreach ($chunkRows as $row) {
    $value = mikhmonRouterScriptSourceValue($row['source'] ?? '');
    if ($value !== false) $written .= $value;
  }
  if (count($chunkRows) !== count($chunks) || !hash_equals(hash('sha256', $encrypted), hash('sha256', $written))) {
    mikhmonRemoveRouterDatabaseGeneration($API, $writtenRows, $generation);
    return array('status' => false, 'error' => 'Verifikasi chunk backup MikroTik gagal. Backup lama dipertahankan.');
  }

  $manifest = array(
    'format' => 'mikhmon-router-database',
    'version' => 1,
    'generation' => $generation,
    'chunks' => count($chunks),
    'sha256' => hash('sha256', $encrypted),
    'payload_hash' => $payloadHash,
    'session' => (string) $session,
    'created_at' => time(),
  );
  $manifestSource = mikhmonRouterScriptSource(base64_encode(json_encode($manifest, JSON_UNESCAPED_SLASHES)));
  $manifestScriptId = '';
  foreach ($existingRows as $existingRow) {
    if ((string) ($existingRow['name'] ?? '') === 'mikhmon-db-manifest' && !empty($existingRow['.id'])) { $manifestScriptId = (string) $existingRow['.id']; break; }
  }
  if ($manifestScriptId !== '') {
    $response = $API->comm('/system/script/set', array('.id' => $manifestScriptId, 'source' => $manifestSource, 'comment' => 'MIKHMON database backup manifest'));
  } else {
    $response = $API->comm('/system/script/add', array('name' => 'mikhmon-db-manifest', 'source' => $manifestSource, 'comment' => 'MIKHMON database backup manifest', 'policy' => 'read'));
  }
  $error = mikhmonRouterScriptApiError($response);
  if ($error !== '') {
    mikhmonRemoveRouterDatabaseGeneration($API, $writtenRows, $generation);
    return array('status' => false, 'error' => 'Manifest backup gagal disimpan: ' . $error);
  }

  foreach ((array) $existingRows as $row) {
    $name = (string) ($row['name'] ?? '');
    if ($name === 'mikhmon-db-manifest' || strpos($name, 'mikhmon-db-') !== 0 || strpos($name, 'mikhmon-db-' . $generation . '-') === 0 || empty($row['.id'])) continue;
    $API->comm('/system/script/remove', array('.id' => $row['.id']));
  }
  return array('status' => true, 'manifest' => $manifest, 'payload_hash' => $payloadHash, 'customers' => count($payload['customers']), 'invoices' => count($payload['invoices']), 'chunks' => count($chunks));
}

function mikhmonReadRouterDatabaseBackup($API, $password) {
  $rows = mikhmonRouterDatabaseScriptRows($API);
  if ($rows === false) return array('status' => false, 'error' => 'Daftar System Script MikroTik tidak dapat dibaca.');
  $manifest = mikhmonRouterDatabaseManifestFromRows($rows);
  if (!$manifest) return array('status' => false, 'error' => 'Backup database Mikhmon belum ditemukan di MikroTik.');
  $prefix = 'mikhmon-db-' . (string) ($manifest['generation'] ?? '') . '-';
  $chunks = array();
  foreach ($rows as $row) {
    $name = (string) ($row['name'] ?? '');
    if (strpos($name, $prefix) !== 0) continue;
    $chunks[$name] = mikhmonRouterScriptSourceValue($row['source'] ?? '');
  }
  ksort($chunks, SORT_STRING);
  if (count($chunks) !== (int) ($manifest['chunks'] ?? 0) || in_array(false, $chunks, true)) return array('status' => false, 'error' => 'Chunk backup MikroTik tidak lengkap.');
  $encrypted = implode('', $chunks);
  if (!isset($manifest['sha256']) || !hash_equals((string) $manifest['sha256'], hash('sha256', $encrypted))) return array('status' => false, 'error' => 'Checksum backup MikroTik tidak cocok.');
  $payload = mikhmonDecryptRouterDatabasePayload($encrypted, $password);
  if (!$payload) return array('status' => false, 'error' => 'Password pemulihan salah atau backup rusak.');
  if (($payload['format'] ?? '') !== 'mikhmon-router-database' || (int) ($payload['version'] ?? 0) !== 1) return array('status' => false, 'error' => 'Format backup tidak didukung.');
  return array('status' => true, 'manifest' => $manifest, 'payload' => $payload);
}

function mikhmonMergeRowsByField($current, $backup, $field) {
  $rows = array_values((array) $current);
  $indexes = array();
  foreach ($rows as $index => $row) if (is_array($row) && isset($row[$field])) $indexes[(string) $row[$field]] = $index;
  $added = 0;
  foreach ((array) $backup as $row) {
    if (!is_array($row) || !isset($row[$field]) || (string) $row[$field] === '') continue;
    $key = (string) $row[$field];
    if (isset($indexes[$key])) $rows[$indexes[$key]] = array_merge($rows[$indexes[$key]], $row);
    else { $indexes[$key] = count($rows); $rows[] = $row; $added++; }
  }
  return array('rows' => array_values($rows), 'added' => $added);
}

function mikhmonRestoreRouterDatabaseBackup($API, $targetSession, $password) {
  $read = mikhmonReadRouterDatabaseBackup($API, $password);
  if (empty($read['status'])) return $read;
  $payload = $read['payload'];
  $database = mikhmonReadDatabase();
  foreach (array('customers', 'invoices', 'report_records', 'users') as $key) if (!isset($database[$key]) || !is_array($database[$key])) $database[$key] = array();

  $userIdMap = array();
  $usersAdded = 0;
  foreach ((array) ($payload['users'] ?? array()) as $backupUser) {
    if (!is_array($backupUser) || empty($backupUser['id']) || empty($backupUser['username'])) continue;
    $oldId = (string) $backupUser['id'];
    $matchIndex = null;
    foreach ($database['users'] as $index => $currentUser) {
      if ((string) ($currentUser['id'] ?? '') === $oldId || strcasecmp((string) ($currentUser['username'] ?? ''), (string) $backupUser['username']) === 0) { $matchIndex = $index; break; }
    }
    if ($matchIndex !== null) {
      $userIdMap[$oldId] = (string) ($database['users'][$matchIndex]['id'] ?? $oldId);
      continue;
    }
    if (($backupUser['role'] ?? '') !== 'admin') $backupUser['session'] = (string) $targetSession;
    $database['users'][] = $backupUser;
    $userIdMap[$oldId] = $oldId;
    $usersAdded++;
  }

  if (!isset($database['customers'][$targetSession]) || !is_array($database['customers'][$targetSession])) $database['customers'][$targetSession] = array();
  $customersAdded = 0;
  $customerIdMap = array();
  foreach ((array) ($payload['customers'] ?? array()) as $backupCustomer) {
    if (!is_array($backupCustomer) || empty($backupCustomer['id'])) continue;
    $oldCustomerId = (string) $backupCustomer['id'];
    $oldMitraId = (string) ($backupCustomer['mitra_id'] ?? '');
    if ($oldMitraId !== '' && isset($userIdMap[$oldMitraId])) $backupCustomer['mitra_id'] = $userIdMap[$oldMitraId];
    $backupCustomer = mikhmonNormalizeCustomer($backupCustomer);
    $matchIndex = null;
    $backupServiceKeys = array();
    foreach (mikhmonCustomerServices($backupCustomer) as $service) $backupServiceKeys[($service['service'] ?? 'hotspot') . '|' . strtolower((string) ($service['username'] ?? ''))] = true;
    foreach ($database['customers'][$targetSession] as $index => $currentCustomer) {
      if ((string) ($currentCustomer['id'] ?? '') === (string) $backupCustomer['id']) { $matchIndex = $index; break; }
      foreach (mikhmonCustomerServices($currentCustomer) as $service) {
        $key = ($service['service'] ?? 'hotspot') . '|' . strtolower((string) ($service['username'] ?? ''));
        if (isset($backupServiceKeys[$key])) { $matchIndex = $index; break 2; }
      }
    }
    if ($matchIndex === null) {
      $database['customers'][$targetSession][] = $backupCustomer;
      $customerIdMap[$oldCustomerId] = (string) $backupCustomer['id'];
      $customersAdded++;
      continue;
    }
    $current = mikhmonNormalizeCustomer($database['customers'][$targetSession][$matchIndex]);
    $services = mikhmonCustomerServices($current);
    $serviceKeys = array();
    foreach ($services as $service) $serviceKeys[($service['service'] ?? 'hotspot') . '|' . strtolower((string) ($service['username'] ?? ''))] = true;
    foreach (mikhmonCustomerServices($backupCustomer) as $service) {
      $key = ($service['service'] ?? 'hotspot') . '|' . strtolower((string) ($service['username'] ?? ''));
      if (!isset($serviceKeys[$key])) { $services[] = $service; $serviceKeys[$key] = true; }
    }
    foreach ($backupCustomer as $field => $value) if ($field !== 'id' && $field !== 'services' && $value !== '' && $value !== null) $current[$field] = $value;
    $current['services'] = $services;
    $database['customers'][$targetSession][$matchIndex] = mikhmonNormalizeCustomer($current);
    $customerIdMap[$oldCustomerId] = (string) ($database['customers'][$targetSession][$matchIndex]['id'] ?? $oldCustomerId);
  }

  if (!isset($database['invoices'][$targetSession]) || !is_array($database['invoices'][$targetSession])) $database['invoices'][$targetSession] = array();
  $backupInvoices = array_values((array) ($payload['invoices'] ?? array()));
  foreach ($backupInvoices as $invoiceIndex => $invoice) {
    if (!is_array($invoice)) continue;
    $oldCustomerId = (string) ($invoice['customer_id'] ?? '');
    $oldPaidBy = (string) ($invoice['paid_by_user_id'] ?? '');
    if ($oldCustomerId !== '' && isset($customerIdMap[$oldCustomerId])) $backupInvoices[$invoiceIndex]['customer_id'] = $customerIdMap[$oldCustomerId];
    if ($oldPaidBy !== '' && isset($userIdMap[$oldPaidBy])) $backupInvoices[$invoiceIndex]['paid_by_user_id'] = $userIdMap[$oldPaidBy];
  }
  $invoiceMerge = mikhmonMergeRowsByField($database['invoices'][$targetSession], $backupInvoices, 'id');
  $database['invoices'][$targetSession] = $invoiceMerge['rows'];
  if (!isset($database['report_records'][$targetSession]) || !is_array($database['report_records'][$targetSession])) $database['report_records'][$targetSession] = array();
  $reportMerge = mikhmonMergeRowsByField($database['report_records'][$targetSession], $payload['report_records'] ?? array(), 'name');
  $database['report_records'][$targetSession] = $reportMerge['rows'];

  if (!mikhmonWriteDatabase($database)) return array('status' => false, 'error' => 'Database lokal gagal ditulis.');
  return array('status' => true, 'customers' => count((array) ($payload['customers'] ?? array())), 'customers_added' => $customersAdded, 'invoices' => count((array) ($payload['invoices'] ?? array())), 'invoices_added' => $invoiceMerge['added'], 'users_added' => $usersAdded, 'source_session' => (string) ($payload['session'] ?? ''));
}

function mikhmonAutoStoreRouterDatabaseBackup($API, $session) {
  $settings = mikhmonReadRouterDatabaseBackupSettings();
  $sessionSettings = isset($settings['sessions'][$session]) && is_array($settings['sessions'][$session]) ? $settings['sessions'][$session] : array();
  if (empty($sessionSettings['enabled']) || empty($sessionSettings['password'])) return array('status' => false, 'skipped' => true);
  $password = mikhmonDecryptSecret($sessionSettings['password']);
  if ($password === false || $password === '') return array('status' => false, 'skipped' => true);
  $payload = mikhmonRouterDatabaseBackupPayload($session);
  $payloadHash = mikhmonRouterDatabasePayloadHash($payload);
  if (($sessionSettings['last_payload_hash'] ?? '') === $payloadHash) return array('status' => true, 'skipped' => true);
  $result = mikhmonStoreRouterDatabaseBackup($API, $session, $password);
  if (!empty($result['status'])) mikhmonConfigureRouterDatabaseBackup($session, $password, true, $payloadHash);
  return $result;
}
