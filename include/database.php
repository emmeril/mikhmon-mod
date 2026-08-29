<?php
// Versioned local snapshots sync automatically from MikroTik; restore is manual.

function mikhmonBackupPath() {
  $directory = dirname(__DIR__) . '/data';
  if (!is_dir($directory)) {
    @mkdir($directory, 0700, true);
  }
  @chmod($directory, 0700);
  return $directory . '/mikhmon-backup.json';
}

function mikhmonDefaultSyncSettings() {
  return array(
    'interval' => 86400,
  );
}

function mikhmonReadDatabase() {
  $path = mikhmonBackupPath();
  if (!is_file($path)) {
    return array('version' => 3, 'routers' => array(), 'customers' => array(), 'invoices' => array());
  }
  $data = json_decode((string) @file_get_contents($path), true);
  if (!is_array($data)) {
    return array('version' => 3, 'routers' => array(), 'customers' => array(), 'invoices' => array());
  }
  if (!isset($data['routers']) || !is_array($data['routers'])) {
    $data['routers'] = array();
  }
  if (!isset($data['customers']) || !is_array($data['customers'])) {
    $data['customers'] = array();
  }
  if (!isset($data['invoices']) || !is_array($data['invoices'])) {
    $data['invoices'] = array();
  }
  return $data;
}

function mikhmonGetCustomers($session) {
  $database = mikhmonReadDatabase();
  $customers = isset($database['customers'][$session]) && is_array($database['customers'][$session]) ? $database['customers'][$session] : array();
  return array_values($customers);
}

function mikhmonGetInvoices($session) {
  $database = mikhmonReadDatabase();
  $invoices = isset($database['invoices'][$session]) && is_array($database['invoices'][$session]) ? $database['invoices'][$session] : array();
  return array_values($invoices);
}

function mikhmonSaveInvoice($session, $invoice) {
  $database = mikhmonReadDatabase();
  if (!isset($database['invoices']) || !is_array($database['invoices'])) {
    $database['invoices'] = array();
  }
  if (!isset($database['invoices'][$session]) || !is_array($database['invoices'][$session])) {
    $database['invoices'][$session] = array();
  }
  if (!isset($invoice['id']) || $invoice['id'] === '') {
    $invoice['id'] = 'invoice-' . uniqid();
  }
  $invoice['id'] = (string) $invoice['id'];
  $found = false;
  foreach ($database['invoices'][$session] as $index => $existing) {
    if (isset($existing['id']) && (string) $existing['id'] === $invoice['id']) {
      $database['invoices'][$session][$index] = $invoice;
      $found = true;
      break;
    }
  }
  if (!$found) {
    $database['invoices'][$session][] = $invoice;
  }
  return mikhmonWriteDatabase($database) ? $invoice['id'] : false;
}

function mikhmonSaveCustomer($session, $id, $name, $phone, $address, $service, $username = '', $profile = '') {
  $database = mikhmonReadDatabase();
  if (!isset($database['customers'][$session]) || !is_array($database['customers'][$session])) {
    $database['customers'][$session] = array();
  }
  $service = strtolower($service) === 'pppoe' ? 'pppoe' : 'hotspot';
  $existingCustomer = array();
  foreach ($database['customers'][$session] as $existing) {
    if ($id !== '' && isset($existing['id']) && (string) $existing['id'] === (string) $id) {
      $existingCustomer = $existing;
      break;
    }
  }
  $customer = array(
    'id' => $id !== '' ? (string) $id : 'customer-' . uniqid(),
    'name' => trim(strip_tags($name)),
    'phone' => trim(strip_tags($phone)),
    'address' => trim(strip_tags($address)),
    'service' => $service,
    'username' => $username !== '' ? trim(strip_tags($username)) : (isset($existingCustomer['username']) ? $existingCustomer['username'] : ''),
    'profile' => $profile !== '' ? trim(strip_tags($profile)) : (isset($existingCustomer['profile']) ? $existingCustomer['profile'] : ''),
    'updated_at' => time(),
  );
  if ($customer['name'] === '') {
    return false;
  }
  $found = false;
  foreach ($database['customers'][$session] as $index => $existing) {
    if (isset($existing['id']) && $existing['id'] === $customer['id']) {
      $database['customers'][$session][$index] = $customer;
      $found = true;
      break;
    }
  }
  if (!$found) {
    $database['customers'][$session][] = $customer;
  }
  return mikhmonWriteDatabase($database) ? $customer['id'] : false;
}

function mikhmonDeleteCustomer($session, $id) {
  $database = mikhmonReadDatabase();
  if (!isset($database['customers'][$session]) || !is_array($database['customers'][$session])) {
    return false;
  }
  $removed = false;
  foreach ($database['customers'][$session] as $index => $customer) {
    if (isset($customer['id']) && (string) $customer['id'] === (string) $id) {
      unset($database['customers'][$session][$index]);
      $removed = true;
    }
  }
  if (!$removed) {
    return false;
  }
  $database['customers'][$session] = array_values($database['customers'][$session]);
  return mikhmonWriteDatabase($database);
}

function mikhmonWriteDatabase($data) {
  $path = mikhmonBackupPath();
  $tmp = $path . '.tmp.' . getmypid();
  $data['version'] = 3;
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
    return false;
  }
  @chmod($tmp, 0600);
  return @rename($tmp, $path);
}

function mikhmonSnapshotRows($rows) {
  $result = array();
  foreach ((array) $rows as $row) {
    if (is_array($row) && isset($row['name']) && (!isset($row['dynamic']) || $row['dynamic'] !== 'true')) {
      unset($row['.id'], $row['dynamic']);
      $result[] = $row;
    }
  }
  return $result;
}

function mikhmonSnapshotCore($snapshot) {
  return array(
    'session' => isset($snapshot['session']) ? $snapshot['session'] : '',
    'updated_at' => isset($snapshot['updated_at']) ? (int) $snapshot['updated_at'] : 0,
    'hotspot_profiles' => isset($snapshot['hotspot_profiles']) ? $snapshot['hotspot_profiles'] : array(),
    'hotspot_users' => isset($snapshot['hotspot_users']) ? $snapshot['hotspot_users'] : array(),
    'ppp_profiles' => isset($snapshot['ppp_profiles']) ? $snapshot['ppp_profiles'] : array(),
    'ppp_secrets' => isset($snapshot['ppp_secrets']) ? $snapshot['ppp_secrets'] : array(),
  );
}

function mikhmonNormalizeRouterRecord($record, $session) {
  $settings = mikhmonDefaultSyncSettings();
  if (!is_array($record)) {
    $record = array();
  }
  // Upgrade the first backup format without discarding its snapshot.
  if (!isset($record['latest']) && isset($record['updated_at'])) {
    $record = array(
      'latest' => mikhmonSnapshotCore($record),
      'history' => array(),
      'settings' => $settings,
      'last_checked_at' => isset($record['updated_at']) ? (int) $record['updated_at'] : 0,
    );
  }
  $record['latest'] = isset($record['latest']) && is_array($record['latest']) ? mikhmonSnapshotCore($record['latest']) : mikhmonSnapshotCore(array());
  $record['history'] = isset($record['history']) && is_array($record['history']) ? $record['history'] : array();
  $record['settings'] = array_merge($settings, isset($record['settings']) && is_array($record['settings']) ? $record['settings'] : array());
  // Migrate the previous per-minute default to the daily backup policy.
  if ((int) $record['settings']['interval'] <= 60) {
    $record['settings']['interval'] = 86400;
  }
  $record['last_checked_at'] = isset($record['last_checked_at']) ? (int) $record['last_checked_at'] : 0;
  if (!empty($record['latest'])) {
    $record['latest']['session'] = (string) $session;
  }
  return $record;
}

function mikhmonGetRouterRecord($database, $session) {
  $record = isset($database['routers'][$session]) ? $database['routers'][$session] : array();
  return mikhmonNormalizeRouterRecord($record, $session);
}

function mikhmonCaptureRouterData($API, $session) {
  $commands = array(
    'hotspot_profiles' => '/ip/hotspot/user/profile/print',
    'hotspot_users' => '/ip/hotspot/user/print',
    'ppp_profiles' => '/ppp/profile/print',
    'ppp_secrets' => '/ppp/secret/print',
  );
  $snapshot = array('session' => (string) $session, 'updated_at' => time());
  foreach ($commands as $key => $command) {
    $rows = $API->comm($command);
    if (!is_array($rows) || isset($rows['!trap']) || isset($rows['!fatal'])) {
      return false;
    }
    $snapshot[$key] = mikhmonSnapshotRows($rows);
  }
  return $snapshot;
}

function mikhmonSnapshotFingerprint($snapshot) {
  $copy = mikhmonSnapshotCore($snapshot);
  unset($copy['updated_at']);
  return sha1(json_encode($copy));
}

function mikhmonStoreSnapshot(&$record, $snapshot) {
  $snapshot = mikhmonSnapshotCore($snapshot);
  if (!empty($record['latest']['updated_at']) && mikhmonSnapshotFingerprint($record['latest']) !== mikhmonSnapshotFingerprint($snapshot)) {
    array_unshift($record['history'], mikhmonSnapshotCore($record['latest']));
    $record['history'] = array_slice($record['history'], 0, 10);
  }
  $record['latest'] = $snapshot;
  $record['last_checked_at'] = time();
}

function mikhmonBackupRouterData($API, $session, $force = false) {
  $database = mikhmonReadDatabase();
  $record = mikhmonGetRouterRecord($database, $session);
  $interval = max(30, (int) $record['settings']['interval']);
  if (!$force && $record['last_checked_at'] > 0 && (time() - $record['last_checked_at']) < $interval) {
    return $record['latest'];
  }
  $snapshot = mikhmonCaptureRouterData($API, $session);
  if ($snapshot === false) {
    return $record['latest'];
  }
  mikhmonStoreSnapshot($record, $snapshot);
  $database['routers'][$session] = $record;
  mikhmonWriteDatabase($database);
  return $record['latest'];
}

function mikhmonRowsByName($rows) {
  $named = array();
  foreach ((array) $rows as $row) {
    if (is_array($row) && isset($row['name'])) {
      $named[(string) $row['name']] = $row;
    }
  }
  return $named;
}

function mikhmonRestoreFields($row, $allowed) {
  $fields = array();
  foreach ($allowed as $field) {
    if (isset($row[$field]) && $row[$field] !== '') {
      $fields[$field] = $row[$field];
    }
  }
  return $fields;
}

function mikhmonRestoreRows($API, $command, $rows, $allowed) {
  $existing = mikhmonRowsByName($API->comm($command . '/print'));
  $restored = 0;
  foreach ((array) $rows as $row) {
    if (!is_array($row) || empty($row['name']) || isset($existing[(string) $row['name']])) {
      continue;
    }
    $fields = mikhmonRestoreFields($row, $allowed);
    $response = !empty($fields) ? $API->comm($command . '/add', $fields) : false;
    if ($response !== false && !(is_array($response) && (isset($response['!trap']) || isset($response['!fatal'])))) {
      $restored++;
    }
  }
  return $restored;
}

function mikhmonRestoreSnapshot($API, $snapshot, $type = 'all') {
  $result = array('profiles' => 0, 'users' => 0);
  if ($type === 'all' || $type === 'hotspot') {
    $result['profiles'] += mikhmonRestoreRows($API, '/ip/hotspot/user/profile', $snapshot['hotspot_profiles'], array('name', 'idle-timeout', 'keepalive-timeout', 'status-autorefresh', 'shared-users', 'rate-limit', 'session-timeout', 'address-pool', 'transparent-proxy', 'use-radius', 'on-login', 'on-logout', 'comment'));
    $result['users'] += mikhmonRestoreRows($API, '/ip/hotspot/user', $snapshot['hotspot_users'], array('server', 'name', 'password', 'profile', 'mac-address', 'limit-uptime', 'limit-bytes-total', 'comment', 'disabled', 'email', 'phone', 'rate-limit', 'shared-users'));
  }
  if ($type === 'all' || $type === 'pppoe') {
    $result['profiles'] += mikhmonRestoreRows($API, '/ppp/profile', $snapshot['ppp_profiles'], array('name', 'local-address', 'remote-address', 'rate-limit', 'session-timeout', 'idle-timeout', 'only-one', 'change-tcp-mss', 'use-encryption', 'use-compression', 'on-up', 'on-down', 'comment'));
    $result['users'] += mikhmonRestoreRows($API, '/ppp/secret', $snapshot['ppp_secrets'], array('name', 'password', 'service', 'profile', 'local-address', 'remote-address', 'caller-id', 'comment', 'disabled', 'limit-uptime', 'rate-limit'));
  }
  return $result;
}

function mikhmonSynchronizeRouterData($API, $session, $force = false) {
  $database = mikhmonReadDatabase();
  $record = mikhmonGetRouterRecord($database, $session);
  $interval = max(30, (int) $record['settings']['interval']);
  if (!$force && $record['last_checked_at'] > 0 && (time() - $record['last_checked_at']) < $interval) {
    return array('status' => 'throttled', 'record' => $record);
  }
  $current = mikhmonCaptureRouterData($API, $session);
  if ($current === false) {
    return array('status' => 'router-error', 'record' => $record);
  }
  mikhmonStoreSnapshot($record, $current);
  $database['routers'][$session] = $record;
  mikhmonWriteDatabase($database);
  return array('status' => 'backed-up', 'record' => $record);
}

function mikhmonRestoreRouterData($API, $session, $type = 'all', $version = 'latest') {
  $database = mikhmonReadDatabase();
  $record = mikhmonGetRouterRecord($database, $session);
  $snapshot = $record['latest'];
  if (strpos($version, 'history-') === 0) {
    $index = (int) substr($version, 8);
    if (isset($record['history'][$index])) {
      $snapshot = mikhmonSnapshotCore($record['history'][$index]);
    }
  }
  if (empty($snapshot['updated_at'])) {
    return array('error' => 'No backup found for this router session.');
  }
  return mikhmonRestoreSnapshot($API, $snapshot, $type);
}
