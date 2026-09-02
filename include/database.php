<?php
// Versioned local snapshots sync automatically from MikroTik; restore is manual.

function mikhmonBackupPath() {
  $override = getenv('MIKHMON_DATABASE_PATH');
  if ($override !== false && trim($override) !== '') {
    $overrideDirectory = dirname($override);
    if (!is_dir($overrideDirectory)) @mkdir($overrideDirectory, 0700, true);
    return $override;
  }
  $directory = dirname(__DIR__) . '/data';
  if (!is_dir($directory)) {
    @mkdir($directory, 0700, true);
  }
  @chmod($directory, 0700);
  return $directory . '/mikhmon-backup.json';
}

// Router snapshots are large and change independently from transactional data.
// Keep them in a separate file so normal customer/invoice requests do not parse
// the complete router history.
function mikhmonRouterBackupPath() {
  $override = getenv('MIKHMON_DATABASE_PATH');
  if ($override !== false && trim($override) !== '') {
    return dirname($override) . '/' . basename($override) . '.routers';
  }
  $directory = dirname(__DIR__) . '/data';
  if (!is_dir($directory)) @mkdir($directory, 0700, true);
  @chmod($directory, 0700);
  return $directory . '/mikhmon-router-backups.json';
}

function mikhmonRouterBackupIndexPath() {
  return mikhmonRouterBackupPath() . '.index';
}

function mikhmonLegacyDatabasePath() {
  $override = getenv('MIKHMON_DATABASE_PATH');
  if ($override !== false && trim($override) !== '') {
    return dirname($override) . '/' . basename($override) . '.legacy';
  }
  return dirname(__DIR__) . '/data/mikhmon-legacy-data.json';
}

function mikhmonDefaultSyncSettings() {
  return array(
    'interval' => 86400,
  );
}

function mikhmonReadDatabase() {
  $path = mikhmonBackupPath();
  if (isset($GLOBALS['_mikhmon_database_cache'], $GLOBALS['_mikhmon_database_cache_path'])
    && is_array($GLOBALS['_mikhmon_database_cache']) && $GLOBALS['_mikhmon_database_cache_path'] === $path) {
    return $GLOBALS['_mikhmon_database_cache'];
  }
  if (!is_file($path)) {
    $empty = array('version' => 5, 'customers' => array(), 'invoices' => array(), 'users' => array(), 'customer_auth' => array());
    $GLOBALS['_mikhmon_database_cache'] = $empty;
    $GLOBALS['_mikhmon_database_cache_path'] = $path;
    return $empty;
  }
  $data = json_decode((string) @file_get_contents($path), true);
  if (!is_array($data)) {
    $empty = array('version' => 5, 'customers' => array(), 'invoices' => array(), 'users' => array(), 'customer_auth' => array());
    $GLOBALS['_mikhmon_database_cache'] = $empty;
    $GLOBALS['_mikhmon_database_cache_path'] = $path;
    return $empty;
  }
  // Migrate the legacy embedded snapshots once, then keep the hot path small.
  if (isset($data['routers']) && is_array($data['routers']) && $data['routers']) {
    $routerData = array('version' => 5, 'routers' => $data['routers']);
    if (mikhmonWriteRouterDatabase($routerData)) {
      unset($data['routers']);
      mikhmonWriteDatabase($data);
    }
  }
  if (isset($data['sales']) && is_array($data['sales']) && $data['sales']) {
    if (mikhmonWriteLegacyDatabase(array('version' => 1, 'sales' => $data['sales']))) {
      unset($data['sales']);
      mikhmonWriteDatabase($data);
    }
  }
  if (!isset($data['customers']) || !is_array($data['customers'])) {
    $data['customers'] = array();
  }
  if (!isset($data['invoices']) || !is_array($data['invoices'])) {
    $data['invoices'] = array();
  }
  if (!isset($data['users']) || !is_array($data['users'])) {
    $data['users'] = array();
  }
  if (!isset($data['customer_auth']) || !is_array($data['customer_auth'])) {
    $data['customer_auth'] = array();
  }
  $changed = false;
  foreach ($data['customers'] as $session => $customers) {
    if (!is_array($customers)) continue;
    foreach ($customers as $index => $customer) {
      $normalized = mikhmonNormalizeCustomer($customer);
      if ($normalized !== $customer) {
        $data['customers'][$session][$index] = $normalized;
        $changed = true;
      }
    }
    $mergedCustomers = mikhmonMergeCustomersByName($data['customers'][$session], $data['invoices'][$session] ?? array());
    if ($mergedCustomers['customers'] !== array_values($data['customers'][$session])) {
      $data['customers'][$session] = $mergedCustomers['customers'];
      $data['invoices'][$session] = $mergedCustomers['invoices'];
      $changed = true;
    }
  }
  if ($changed) {
    $data['version'] = 5;
    mikhmonWriteDatabase($data);
  }
  $GLOBALS['_mikhmon_database_cache'] = $data;
  $GLOBALS['_mikhmon_database_cache_path'] = $path;
  return $data;
}

function mikhmonReadRouterDatabase() {
  $path = mikhmonRouterBackupPath();
  if (isset($GLOBALS['_mikhmon_router_database_cache'], $GLOBALS['_mikhmon_router_database_cache_path'])
    && is_array($GLOBALS['_mikhmon_router_database_cache']) && $GLOBALS['_mikhmon_router_database_cache_path'] === $path) {
    return $GLOBALS['_mikhmon_router_database_cache'];
  }
  $data = is_file($path) ? json_decode((string) @file_get_contents($path), true) : array();
  if (!is_array($data)) $data = array();
  if (!isset($data['routers']) || !is_array($data['routers'])) $data['routers'] = array();
  $GLOBALS['_mikhmon_router_database_cache'] = $data;
  $GLOBALS['_mikhmon_router_database_cache_path'] = $path;
  return $data;
}

function mikhmonCustomerNameKey($name) {
  $name = trim(preg_replace('/\s+/', ' ', (string) $name));
  return function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
}

function mikhmonCustomerValueIsEmpty($value) {
  $value = trim((string) $value);
  return $value === '' || $value === '-';
}

function mikhmonMergeCustomersByName($customers, $invoices = array()) {
  $merged = array();
  $indexesByName = array();
  $replacedIds = array();
  foreach ((array) $customers as $customer) {
    $customer = mikhmonNormalizeCustomer($customer);
    $nameKey = mikhmonCustomerNameKey($customer['name'] ?? '');
    if ($nameKey === '' || !isset($indexesByName[$nameKey])) {
      $indexesByName[$nameKey] = count($merged);
      $merged[] = $customer;
      continue;
    }
    $targetIndex = $indexesByName[$nameKey];
    $target = $merged[$targetIndex];
    if (!empty($customer['id']) && !empty($target['id'])) $replacedIds[(string) $customer['id']] = (string) $target['id'];
    $serviceKeys = array();
    foreach (mikhmonCustomerServices($target) as $service) $serviceKeys[$service['service'] . '|' . strtolower($service['username'])] = true;
    foreach (mikhmonCustomerServices($customer) as $service) {
      $serviceKey = $service['service'] . '|' . strtolower($service['username']);
      if (!isset($serviceKeys[$serviceKey])) { $target['services'][] = $service; $serviceKeys[$serviceKey] = true; }
    }
    foreach (array('phone', 'address', 'mitra_id') as $field) {
      if (mikhmonCustomerValueIsEmpty($target[$field] ?? '') && !mikhmonCustomerValueIsEmpty($customer[$field] ?? '')) $target[$field] = $customer[$field];
    }
    $target['updated_at'] = max((int) ($target['updated_at'] ?? 0), (int) ($customer['updated_at'] ?? 0));
    $merged[$targetIndex] = mikhmonNormalizeCustomer($target);
  }
  $updatedInvoices = array_values((array) $invoices);
  foreach ($updatedInvoices as $index => $invoice) {
    $customerId = isset($invoice['customer_id']) ? (string) $invoice['customer_id'] : '';
    if (isset($replacedIds[$customerId])) $updatedInvoices[$index]['customer_id'] = $replacedIds[$customerId];
  }
  return array('customers' => array_values($merged), 'invoices' => $updatedInvoices);
}

function mikhmonNormalizeCustomer($customer) {
  if (!is_array($customer)) return array();
  $services = isset($customer['services']) && is_array($customer['services']) ? $customer['services'] : array();
  if (!$services && !empty($customer['username'])) {
    $services[] = array(
      'id' => 'service-' . (isset($customer['id']) ? $customer['id'] : uniqid()),
      'service' => isset($customer['service']) && $customer['service'] === 'pppoe' ? 'pppoe' : 'hotspot',
      'username' => (string) $customer['username'],
      'profile' => isset($customer['profile']) ? (string) $customer['profile'] : '',
      'server' => isset($customer['server']) ? (string) $customer['server'] : 'all',
    );
  }
  $normalizedServices = array();
  foreach ($services as $service) {
    if (!is_array($service)) continue;
    $normalizedServices[] = array(
      'id' => !empty($service['id']) ? (string) $service['id'] : 'service-' . md5((string) ($service['service'] ?? 'hotspot') . '|' . (string) ($service['username'] ?? '')),
      'service' => isset($service['service']) && $service['service'] === 'pppoe' ? 'pppoe' : 'hotspot',
      'username' => isset($service['username']) ? trim(strip_tags((string) $service['username'])) : '',
      'profile' => isset($service['profile']) ? trim(strip_tags((string) $service['profile'])) : '',
      'server' => isset($service['server']) ? trim(strip_tags((string) $service['server'])) : 'all',
    );
  }
  $customer['services'] = array_values($normalizedServices);
  $first = isset($customer['services'][0]) ? $customer['services'][0] : array('service' => 'hotspot', 'username' => '', 'profile' => '', 'server' => 'all');
  // Keep legacy fields populated for older integrations and reports.
  $customer['service'] = $first['service'];
  $customer['username'] = $first['username'];
  $customer['profile'] = $first['profile'];
  $customer['server'] = $first['server'];
  return $customer;
}

function mikhmonCustomerServices($customer) {
  $normalized = mikhmonNormalizeCustomer($customer);
  return isset($normalized['services']) ? $normalized['services'] : array();
}

function mikhmonGetUsers($role = '', $session = '') {
  $database = mikhmonReadDatabase();
  $users = array_values($database['users']);
  return array_values(array_filter($users, function ($user) use ($role, $session) {
    if (!is_array($user)) return false;
    if ($role !== '' && (!isset($user['role']) || $user['role'] !== $role)) return false;
    if ($session !== '' && (!isset($user['session']) || $user['session'] !== $session)) return false;
    return true;
  }));
}

function mikhmonFindUser($value, $field = 'id') {
  foreach (mikhmonGetUsers() as $user) {
    if (isset($user[$field]) && (string) $user[$field] === (string) $value) return $user;
  }
  return false;
}

function mikhmonSaveUser($id, $name, $username, $role, $session, $password = '', $active = true) {
  $database = mikhmonReadDatabase();
  $role = in_array($role, array('admin', 'mitra', 'biller'), true) ? $role : '';
  $username = trim(strip_tags($username));
  $name = trim(strip_tags($name));
  $session = trim(strip_tags($session));
  // Admin accounts are global and do not need to be tied to a router.
  if ($role === 'admin') $session = 'mikhmon';
  if ($role === '' || $username === '' || $name === '' || ($role !== 'admin' && $session === '')) return false;

  foreach ($database['users'] as $existing) {
    if (isset($existing['username']) && strtolower($existing['username']) === strtolower($username)
      && (!isset($existing['id']) || (string) $existing['id'] !== (string) $id)) return false;
  }

  $existingUser = $id !== '' ? mikhmonFindUser($id) : false;
  if ($id === '' && $password === '') return false;
  $user = array(
    'id' => $id !== '' ? (string) $id : 'user-' . bin2hex(random_bytes(8)),
    'name' => $name,
    'username' => $username,
    'role' => $role,
    'session' => $session,
    'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : (isset($existingUser['password_hash']) ? $existingUser['password_hash'] : ''),
    'active' => (bool) $active,
    'updated_at' => time(),
  );
  if ($user['password_hash'] === '') return false;

  $found = false;
  foreach ($database['users'] as $index => $existing) {
    if (isset($existing['id']) && (string) $existing['id'] === $user['id']) {
      $database['users'][$index] = $user;
      $found = true;
      break;
    }
  }
  if (!$found) $database['users'][] = $user;
  return mikhmonWriteDatabase($database) ? $user['id'] : false;
}

function mikhmonDeleteUser($id) {
  $database = mikhmonReadDatabase();
  $removed = false;
  foreach ($database['users'] as $index => $user) {
    if (isset($user['id']) && (string) $user['id'] === (string) $id) {
      unset($database['users'][$index]);
      $removed = true;
    }
  }
  if (!$removed) return false;
  $database['users'] = array_values($database['users']);
  return mikhmonWriteDatabase($database);
}

function mikhmonAssignedCustomerCount($userId) {
  $count = 0;
  $database = mikhmonReadDatabase();
  foreach ($database['customers'] as $routerCustomers) {
    foreach ((array) $routerCustomers as $customer) {
      if (isset($customer['mitra_id']) && (string) $customer['mitra_id'] === (string) $userId) $count++;
    }
  }
  return $count;
}

function mikhmonGetCustomers($session) {
  $database = mikhmonReadDatabase();
  $customers = isset($database['customers'][$session]) && is_array($database['customers'][$session]) ? $database['customers'][$session] : array();
  return array_values(array_map('mikhmonNormalizeCustomer', $customers));
}

function mikhmonFindCustomer($session, $id) {
  foreach (mikhmonGetCustomers($session) as $customer) {
    if (isset($customer['id']) && (string) $customer['id'] === (string) $id) return $customer;
  }
  return false;
}

function mikhmonCustomerPhone($phone) {
  $phone = preg_replace('/[^0-9]/', '', (string) $phone);
  if (substr($phone, 0, 1) === '0') $phone = '62' . substr($phone, 1);
  return $phone;
}

function mikhmonFindCustomerByPhone($phone) {
  $phone = mikhmonCustomerPhone($phone);
  if ($phone === '') return array();
  $database = mikhmonReadDatabase();
  foreach ((array) ($database['customers'] ?? array()) as $session => $customers) {
    foreach ((array) $customers as $customer) {
      if (mikhmonCustomerPhone($customer['phone'] ?? '') === $phone) {
        $customer = mikhmonNormalizeCustomer($customer);
        $customer['_session'] = (string) $session;
        return $customer;
      }
    }
  }
  return array();
}

function mikhmonSaveCustomerPortalPassword($session, $customerId, $password) {
  $password = (string) $password;
  if (strlen($password) < 6) return false;
  $database = mikhmonReadDatabase();
  foreach ((array) ($database['customers'][$session] ?? array()) as $index => $customer) {
    if ((string) ($customer['id'] ?? '') !== (string) $customerId) continue;
    $database['customers'][$session][$index]['portal_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    $database['customers'][$session][$index]['portal_enabled'] = true;
    $database['customers'][$session][$index]['updated_at'] = time();
    return mikhmonWriteDatabase($database);
  }
  return false;
}

function mikhmonCustomerPortalPasswordValid($customer, $password) {
  return is_array($customer) && !empty($customer['portal_password_hash']) && password_verify((string) $password, (string) $customer['portal_password_hash']);
}

function mikhmonSaveCustomerOtp($phone, $otp, $expiresAt) {
  $phone = mikhmonCustomerPhone($phone);
  if ($phone === '' || !preg_match('/^[0-9]{6}$/', (string) $otp)) return false;
  $database = mikhmonReadDatabase();
  if (!empty($database['customer_auth'][$phone]['created_at']) && (int) $database['customer_auth'][$phone]['created_at'] > time() - 60) return false;
  $database['customer_auth'][$phone] = array(
    'otp_hash' => password_hash((string) $otp, PASSWORD_DEFAULT),
    'expires_at' => (int) $expiresAt,
    'attempts' => 0,
    'created_at' => time(),
  );
  return mikhmonWriteDatabase($database);
}

function mikhmonVerifyCustomerOtp($phone, $otp) {
  $phone = mikhmonCustomerPhone($phone);
  $database = mikhmonReadDatabase();
  $auth = $database['customer_auth'][$phone] ?? array();
  if (!$auth || empty($auth['otp_hash']) || (int) ($auth['expires_at'] ?? 0) < time() || (int) ($auth['attempts'] ?? 0) >= 5) return false;
  if (!password_verify((string) $otp, (string) $auth['otp_hash'])) {
    $database['customer_auth'][$phone]['attempts'] = (int) ($auth['attempts'] ?? 0) + 1;
    mikhmonWriteDatabase($database);
    return false;
  }
  unset($database['customer_auth'][$phone]);
  mikhmonWriteDatabase($database);
  return true;
}

function mikhmonAssignCustomer($session, $customerId, $mitraId) {
  $database = mikhmonReadDatabase();
  if (!isset($database['customers'][$session]) || !is_array($database['customers'][$session])) return false;
  foreach ($database['customers'][$session] as $index => $customer) {
    if (isset($customer['id']) && (string) $customer['id'] === (string) $customerId) {
      $database['customers'][$session][$index]['mitra_id'] = trim(strip_tags($mitraId));
      $database['customers'][$session][$index]['updated_at'] = time();
      return mikhmonWriteDatabase($database);
    }
  }
  return false;
}

function mikhmonSetCustomerDueDate($session, $customerId, $dueDate) {
  $database = mikhmonReadDatabase();
  if (!isset($database['customers'][$session]) || !is_array($database['customers'][$session])) return false;
  $dueDate = trim(strip_tags((string) $dueDate));
  foreach ($database['customers'][$session] as $index => $customer) {
    if (isset($customer['id']) && (string) $customer['id'] === (string) $customerId) {
      // Avoid rewriting the complete JSON database when the value is unchanged.
      if ((string) ($customer['due_date'] ?? '') === $dueDate) return true;
      $database['customers'][$session][$index]['due_date'] = $dueDate;
      $database['customers'][$session][$index]['updated_at'] = time();
      return mikhmonWriteDatabase($database);
    }
  }
  return false;
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

function mikhmonSaveCustomer($session, $id, $name, $phone, $address, $service, $username = '', $profile = '', $mitraId = null) {
  $serviceId = '';
  if ($id !== '') {
    $existing = mikhmonFindCustomer($session, $id);
    $existingServices = $existing ? mikhmonCustomerServices($existing) : array();
    if (isset($existingServices[0])) {
      $serviceId = $existingServices[0]['id'];
      if ($username === '') $username = $existingServices[0]['username'];
      if ($profile === '') $profile = $existingServices[0]['profile'];
    }
  }
  return mikhmonSaveCustomerWithServices($session, $id, $name, $phone, $address, array(array(
    'id' => $serviceId,
    'service' => $service,
    'username' => $username,
    'profile' => $profile,
  )), $mitraId);
}

function mikhmonSaveCustomerIdentity($session, $id, $name, $phone, $address, $mitraId = null) {
  $database = mikhmonReadDatabase();
  if (!isset($database['customers'][$session]) || !is_array($database['customers'][$session])) $database['customers'][$session] = array();
  $name = trim(strip_tags((string) $name));
  if ($name === '') return false;

  $existingCustomer = array();
  $nameKey = mikhmonCustomerNameKey($name);
  foreach ($database['customers'][$session] as $existing) {
    if ($nameKey === '' || mikhmonCustomerNameKey($existing['name'] ?? '') !== $nameKey) continue;
    if ($id !== '' && (string) ($existing['id'] ?? '') !== (string) $id) return false;
  }
  if ($id === '') {
    foreach ($database['customers'][$session] as $existing) {
      if ($nameKey !== '' && mikhmonCustomerNameKey($existing['name'] ?? '') === $nameKey) {
        $id = (string) ($existing['id'] ?? '');
        $existingCustomer = mikhmonNormalizeCustomer($existing);
        break;
      }
    }
  }
  foreach ($database['customers'][$session] as $existing) {
    if ($id !== '' && isset($existing['id']) && (string) $existing['id'] === (string) $id) {
      $existingCustomer = mikhmonNormalizeCustomer($existing);
      break;
    }
  }

  $customer = mikhmonNormalizeCustomer(array(
    'id' => $id !== '' ? (string) $id : 'customer-' . uniqid(),
    'name' => $name,
    'phone' => trim(strip_tags((string) $phone)),
    'address' => trim(strip_tags((string) $address)),
    'services' => mikhmonCustomerServices($existingCustomer),
    'mitra_id' => $mitraId !== null ? trim(strip_tags((string) $mitraId)) : ($existingCustomer['mitra_id'] ?? ''),
    'due_date' => $existingCustomer['due_date'] ?? '',
    'updated_at' => time(),
  ));
  foreach ($database['customers'][$session] as $index => $existing) {
    if (isset($existing['id']) && (string) $existing['id'] === (string) $customer['id']) {
      $database['customers'][$session][$index] = $customer;
      return mikhmonWriteDatabase($database) ? $customer['id'] : false;
    }
  }
  $database['customers'][$session][] = $customer;
  return mikhmonWriteDatabase($database) ? $customer['id'] : false;
}

function mikhmonAddCustomerService($session, $customerId, $service) {
  $customer = mikhmonFindCustomer($session, $customerId);
  if (!$customer || !is_array($service)) return false;
  $serviceType = ($service['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
  $username = trim(strip_tags((string) ($service['username'] ?? '')));
  if ($username === '') return false;
  foreach (mikhmonGetCustomers($session) as $candidate) {
    foreach (mikhmonCustomerServices($candidate) as $existingService) {
      if ($existingService['service'] === $serviceType && strtolower($existingService['username']) === strtolower($username)) return false;
    }
  }
  $services = mikhmonCustomerServices($customer);
  $services[] = array(
    'id' => 'service-' . uniqid(),
    'service' => $serviceType,
    'username' => $username,
    'profile' => trim(strip_tags((string) ($service['profile'] ?? ''))),
    'server' => trim(strip_tags((string) ($service['server'] ?? 'all'))),
  );
  return mikhmonSaveCustomerWithServices($session, $customer['id'], $customer['name'], $customer['phone'] ?? '', $customer['address'] ?? '', $services, $customer['mitra_id'] ?? '');
}

function mikhmonUpdateCustomerService($session, $customerId, $serviceId, $service) {
  $customer = mikhmonFindCustomer($session, $customerId);
  if (!$customer || !is_array($service)) return false;
  $serviceId = (string) $serviceId;
  $serviceType = ($service['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
  $username = trim(strip_tags((string) ($service['username'] ?? '')));
  if ($serviceId === '' || $username === '') return false;

  foreach (mikhmonGetCustomers($session) as $candidate) {
    foreach (mikhmonCustomerServices($candidate) as $existingService) {
      if ((string) ($existingService['id'] ?? '') === $serviceId && (string) ($candidate['id'] ?? '') === (string) $customerId) continue;
      if (($existingService['service'] ?? '') === $serviceType && strtolower((string) ($existingService['username'] ?? '')) === strtolower($username)) return false;
    }
  }

  $services = mikhmonCustomerServices($customer);
  $found = false;
  foreach ($services as $index => $existingService) {
    if ((string) ($existingService['id'] ?? '') !== $serviceId) continue;
    $services[$index] = array(
      'id' => $serviceId,
      'service' => $serviceType,
      'username' => $username,
      'profile' => trim(strip_tags((string) ($service['profile'] ?? ''))),
      'server' => trim(strip_tags((string) ($service['server'] ?? 'all'))),
    );
    $found = true;
    break;
  }
  if (!$found) return false;
  return mikhmonSaveCustomerWithServices($session, $customer['id'], $customer['name'], $customer['phone'] ?? '', $customer['address'] ?? '', $services, $customer['mitra_id'] ?? '');
}

function mikhmonDeleteCustomerService($session, $customerId, $serviceId) {
  $customer = mikhmonFindCustomer($session, $customerId);
  if (!$customer || (string) $serviceId === '') return false;
  $services = mikhmonCustomerServices($customer);
  $remaining = array();
  $removed = false;
  foreach ($services as $service) {
    if (!$removed && (string) ($service['id'] ?? '') === (string) $serviceId) {
      $removed = true;
      continue;
    }
    $remaining[] = $service;
  }
  if (!$removed) return false;
  if (!$remaining) {
    $database = mikhmonReadDatabase();
    foreach ((array) ($database['customers'][$session] ?? array()) as $index => $storedCustomer) {
      if ((string) ($storedCustomer['id'] ?? '') !== (string) $customerId) continue;
      $storedCustomer = mikhmonNormalizeCustomer($storedCustomer);
      $storedCustomer['services'] = array();
      $storedCustomer['service'] = 'hotspot';
      $storedCustomer['username'] = '';
      $storedCustomer['profile'] = '';
      $storedCustomer['server'] = 'all';
      $storedCustomer['updated_at'] = time();
      $database['customers'][$session][$index] = $storedCustomer;
      return mikhmonWriteDatabase($database);
    }
    return false;
  }
  return mikhmonSaveCustomerWithServices($session, $customer['id'], $customer['name'], $customer['phone'] ?? '', $customer['address'] ?? '', $remaining, $customer['mitra_id'] ?? '');
}

function mikhmonSaveCustomerWithServices($session, $id, $name, $phone, $address, $services, $mitraId = null) {
  $database = mikhmonReadDatabase();
  if (!isset($database['customers'][$session]) || !is_array($database['customers'][$session])) {
    $database['customers'][$session] = array();
  }
  $existingCustomer = array();
  $matchedByName = false;
  if ($id === '') {
    $nameKey = mikhmonCustomerNameKey($name);
    foreach ($database['customers'][$session] as $existing) {
      if ($nameKey !== '' && mikhmonCustomerNameKey($existing['name'] ?? '') === $nameKey) {
        $id = (string) $existing['id'];
        $existingCustomer = mikhmonNormalizeCustomer($existing);
        $matchedByName = true;
        break;
      }
    }
  }
  foreach ($database['customers'][$session] as $existing) {
    if ($id !== '' && isset($existing['id']) && (string) $existing['id'] === (string) $id) {
      $existingCustomer = $existing;
      break;
    }
  }
  $normalizedServices = array();
  $servicesToSave = $matchedByName ? array_merge(mikhmonCustomerServices($existingCustomer), (array) $services) : (array) $services;
  $serviceKeys = array();
  foreach ($servicesToSave as $serviceRow) {
    if (!is_array($serviceRow)) continue;
    $serviceType = strtolower(isset($serviceRow['service']) ? $serviceRow['service'] : '') === 'pppoe' ? 'pppoe' : 'hotspot';
    $serviceUsername = trim(strip_tags(isset($serviceRow['username']) ? (string) $serviceRow['username'] : ''));
    if ($serviceUsername === '') continue;
    $serviceKey = $serviceType . '|' . strtolower($serviceUsername);
    if (isset($serviceKeys[$serviceKey])) continue;
    $serviceKeys[$serviceKey] = true;
    $normalizedServices[] = array(
      'id' => !empty($serviceRow['id']) ? (string) $serviceRow['id'] : 'service-' . uniqid(),
      'service' => $serviceType,
      'username' => $serviceUsername,
      'profile' => trim(strip_tags(isset($serviceRow['profile']) ? (string) $serviceRow['profile'] : '')),
      'server' => trim(strip_tags(isset($serviceRow['server']) ? (string) $serviceRow['server'] : 'all')),
    );
  }
  if (!$normalizedServices) return false;
  $customer = array(
    'id' => $id !== '' ? (string) $id : 'customer-' . uniqid(),
    'name' => trim(strip_tags($name)),
    'phone' => $matchedByName && mikhmonCustomerValueIsEmpty($phone) ? ($existingCustomer['phone'] ?? '') : trim(strip_tags($phone)),
    'address' => $matchedByName && mikhmonCustomerValueIsEmpty($address) ? ($existingCustomer['address'] ?? '') : trim(strip_tags($address)),
    'services' => $normalizedServices,
    'mitra_id' => $matchedByName && mikhmonCustomerValueIsEmpty($mitraId) ? ($existingCustomer['mitra_id'] ?? '') : ($mitraId !== null ? trim(strip_tags($mitraId)) : (isset($existingCustomer['mitra_id']) ? $existingCustomer['mitra_id'] : '')),
    'due_date' => $existingCustomer['due_date'] ?? '',
    'updated_at' => time(),
  );
  $customer = mikhmonNormalizeCustomer($customer);
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
  $data['version'] = 5;
  $json = json_encode($data, JSON_UNESCAPED_SLASHES);
  if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
    return false;
  }
  @chmod($tmp, 0600);
  $written = @rename($tmp, $path);
  if ($written) {
    $GLOBALS['_mikhmon_database_cache'] = $data;
    $GLOBALS['_mikhmon_database_cache_path'] = $path;
  }
  return $written;
}

function mikhmonWriteRouterDatabase($data) {
  $path = mikhmonRouterBackupPath();
  $tmp = $path . '.tmp.' . getmypid();
  $data['version'] = 5;
  $json = json_encode($data, JSON_UNESCAPED_SLASHES);
  if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  @chmod($tmp, 0600);
  $written = @rename($tmp, $path);
  if ($written) {
    $GLOBALS['_mikhmon_router_database_cache'] = $data;
    $GLOBALS['_mikhmon_router_database_cache_path'] = $path;
    mikhmonWriteRouterIndex($data);
  }
  return $written;
}

function mikhmonWriteRouterIndex($data) {
  $index = array('version' => 1, 'routers' => array());
  foreach ((array) ($data['routers'] ?? array()) as $session => $record) {
    $normalized = mikhmonNormalizeRouterRecord($record, $session);
    $index['routers'][$session] = array(
      'last_checked_at' => (int) $normalized['last_checked_at'],
      'interval' => (int) $normalized['settings']['interval'],
    );
  }
  $indexPath = mikhmonRouterBackupIndexPath();
  $indexTmp = $indexPath . '.tmp.' . getmypid();
  $indexJson = json_encode($index, JSON_UNESCAPED_SLASHES);
  if ($indexJson === false || @file_put_contents($indexTmp, $indexJson, LOCK_EX) === false) return false;
  @chmod($indexTmp, 0600);
  return @rename($indexTmp, $indexPath);
}

function mikhmonWriteLegacyDatabase($data) {
  $path = mikhmonLegacyDatabasePath();
  $tmp = $path . '.tmp.' . getmypid();
  $json = json_encode($data, JSON_UNESCAPED_SLASHES);
  if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  @chmod($tmp, 0600);
  return @rename($tmp, $path);
}

function mikhmonRouterSyncDue($session, $force = false) {
  if ($force) return true;
  $indexPath = mikhmonRouterBackupIndexPath();
  if (!is_file($indexPath)) {
    $routerDatabase = mikhmonReadRouterDatabase();
    mikhmonWriteRouterIndex($routerDatabase);
  }
  $index = is_file($indexPath) ? json_decode((string) @file_get_contents($indexPath), true) : array();
  $row = isset($index['routers'][$session]) && is_array($index['routers'][$session]) ? $index['routers'][$session] : array();
  $lastCheckedAt = (int) ($row['last_checked_at'] ?? 0);
  $interval = max(30, (int) ($row['interval'] ?? mikhmonDefaultSyncSettings()['interval']));
  return $lastCheckedAt <= 0 || (time() - $lastCheckedAt) >= $interval;
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
  $routerDatabase = mikhmonReadRouterDatabase();
  $record = isset($routerDatabase['routers'][$session]) ? $routerDatabase['routers'][$session] : array();
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
  $record = mikhmonGetRouterRecord(array(), $session);
  $interval = max(30, (int) $record['settings']['interval']);
  if (!$force && $record['last_checked_at'] > 0 && (time() - $record['last_checked_at']) < $interval) {
    return $record['latest'];
  }
  $snapshot = mikhmonCaptureRouterData($API, $session);
  if ($snapshot === false) {
    return $record['latest'];
  }
  mikhmonStoreSnapshot($record, $snapshot);
  $routerDatabase = mikhmonReadRouterDatabase();
  $routerDatabase['routers'][$session] = $record;
  mikhmonWriteRouterDatabase($routerDatabase);
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
  if (!mikhmonRouterSyncDue($session, $force)) {
    return array('status' => 'throttled', 'record' => array());
  }
  $record = mikhmonGetRouterRecord(array(), $session);
  $interval = max(30, (int) $record['settings']['interval']);
  if (!$force && $record['last_checked_at'] > 0 && (time() - $record['last_checked_at']) < $interval) {
    return array('status' => 'throttled', 'record' => $record);
  }
  $current = mikhmonCaptureRouterData($API, $session);
  if ($current === false) {
    return array('status' => 'router-error', 'record' => $record);
  }
  mikhmonStoreSnapshot($record, $current);
  $routerDatabase = mikhmonReadRouterDatabase();
  $routerDatabase['routers'][$session] = $record;
  mikhmonWriteRouterDatabase($routerDatabase);
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
