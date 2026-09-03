<?php

include_once(__DIR__ . '/database.php');

function mikhmonCsrfToken() {
  if (empty($_SESSION['mikhmon_csrf'])) $_SESSION['mikhmon_csrf'] = bin2hex(random_bytes(32));
  return (string) $_SESSION['mikhmon_csrf'];
}

function mikhmonValidCsrf($token) {
  return isset($_SESSION['mikhmon_csrf']) && is_string($token)
    && hash_equals((string) $_SESSION['mikhmon_csrf'], $token);
}

function mikhmonCsrfField() {
  return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(mikhmonCsrfToken(), ENT_QUOTES) . '">';
}

function mikhmonRole() {
  return isset($_SESSION['mikhmon_role']) ? $_SESSION['mikhmon_role'] : 'admin';
}

function mikhmonUserId() {
  return isset($_SESSION['mikhmon_user_id']) ? (string) $_SESSION['mikhmon_user_id'] : '';
}

function mikhmonUserName() {
  return isset($_SESSION['mikhmon_name']) ? (string) $_SESSION['mikhmon_name'] : (isset($_SESSION['mikhmon']) ? (string) $_SESSION['mikhmon'] : '');
}

function mikhmonIsAdmin() {
  return mikhmonRole() === 'admin';
}

function mikhmonIsMitra() {
  return mikhmonRole() === 'mitra';
}

function mikhmonIsBiller() {
  return mikhmonRole() === 'biller';
}

function mikhmonIsCustomer() {
  return mikhmonRole() === 'pelanggan';
}

function mikhmonCustomerSessionTtl() {
  return 86400;
}

function mikhmonSetCustomerSession($customer, $session = '') {
  $_SESSION['mikhmon'] = 'pelanggan:' . (string) ($customer['id'] ?? '');
  $_SESSION['mikhmon_role'] = 'pelanggan';
  $_SESSION['mikhmon_user_id'] = (string) ($customer['id'] ?? '');
  $_SESSION['mikhmon_name'] = (string) ($customer['name'] ?? 'Pelanggan');
  $_SESSION['mikhmon_customer_id'] = (string) ($customer['id'] ?? '');
  $_SESSION['mikhmon_customer_session'] = (string) ($session !== '' ? $session : ($customer['_session'] ?? ''));
  $_SESSION['mikhmon_customer_expires_at'] = time() + mikhmonCustomerSessionTtl();
}

function mikhmonRefreshCustomerSession() {
  if (!mikhmonIsCustomer()) return false;
  $session = (string) ($_SESSION['mikhmon_customer_session'] ?? '');
  $id = (string) ($_SESSION['mikhmon_customer_id'] ?? $_SESSION['mikhmon_user_id'] ?? '');
  if ($session === '' || $id === '') return false;
  if (!empty($_SESSION['mikhmon_customer_expires_at']) && (int) $_SESSION['mikhmon_customer_expires_at'] < time()) return false;
  $customer = mikhmonFindCustomer($session, $id);
  if (!$customer) return false;
  mikhmonSetCustomerSession($customer, $session);
  return true;
}

function mikhmonAssignedSession() {
  return isset($_SESSION['mikhmon_router_session']) ? (string) $_SESSION['mikhmon_router_session'] : '';
}

function mikhmonDefaultRouterSession($config = null) {
  if ($config === null) {
    global $data;
    $config = isset($data) ? $data : array();
  }

  foreach ((array) $config as $session => $settings) {
    if ($session !== 'mikhmon' && $session !== '' && is_array($settings)) return (string) $session;
  }
  return '';
}

function mikhmonAdminLandingUrl($config = null) {
  $session = mikhmonDefaultRouterSession($config);
  return $session !== '' ? './?session=' . rawurlencode($session) : './admin.php?id=sessions';
}

function mikhmonBuildRouterConfigLine($session, $settings) {
  $configValues = array();
  foreach ((array) $settings as $configKey => $configValue) {
    $configValues[] = var_export((string) $configKey, true) . '=>' . var_export((string) $configValue, true);
  }
  return "\n\$data[" . var_export((string) $session, true) . "] = array (" . implode(',', $configValues) . ");\n";
}

function mikhmonLoginStaff($username, $password) {
  $user = false;
  foreach (mikhmonGetUsers() as $candidate) {
    if (isset($candidate['username']) && strtolower($candidate['username']) === strtolower(trim((string) $username))) {
      $user = $candidate;
      break;
    }
  }
  if (!$user || empty($user['active']) || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) return false;
  return $user;
}

function mikhmonSetLoginSession($user, $role = '') {
  $resolvedRole = $role !== '' ? $role : (isset($user['role']) ? $user['role'] : 'admin');
  $_SESSION['mikhmon'] = isset($user['username']) ? $user['username'] : '';
  $_SESSION['mikhmon_role'] = $resolvedRole;
  $_SESSION['mikhmon_user_id'] = isset($user['id']) ? $user['id'] : '';
  $_SESSION['mikhmon_name'] = isset($user['name']) ? $user['name'] : $_SESSION['mikhmon'];
  $_SESSION['mikhmon_router_session'] = isset($user['session']) ? $user['session'] : '';
}

function mikhmonRefreshStaffSession() {
  // The built-in administrator is stored in config.php; database-backed admins
  // must still be checked so deactivated accounts lose access immediately.
  if (mikhmonIsAdmin()) {
    $userId = mikhmonUserId();
    if ($userId === '') return true;
    $admin = mikhmonFindUser($userId);
    if (!$admin || empty($admin['active']) || ($admin['role'] ?? '') !== 'admin') return false;
    mikhmonSetLoginSession($admin);
    return true;
  }
  $user = mikhmonFindUser(mikhmonUserId());
  if (!$user || empty($user['active']) || !in_array($user['role'], array('mitra', 'biller'), true)) return false;
  mikhmonSetLoginSession($user);
  return true;
}

function mikhmonVisibleCustomers($session, $customers = null) {
  $customers = $customers === null ? mikhmonGetCustomers($session) : (array) $customers;
  if (!mikhmonIsMitra()) return array_values($customers);
  $userId = mikhmonUserId();
  return array_values(array_filter($customers, function ($customer) use ($userId) {
    return isset($customer['mitra_id']) && (string) $customer['mitra_id'] === $userId;
  }));
}

function mikhmonVisibleServiceCustomers($session, $customers = null) {
  return array_values(array_filter(mikhmonVisibleCustomers($session, $customers), function ($customer) {
    return count(mikhmonCustomerServices($customer)) > 0;
  }));
}

function mikhmonVisibleInvoices($session, $invoices = null) {
  $invoices = $invoices === null ? mikhmonGetInvoices($session) : (array) $invoices;
  if (!mikhmonIsMitra()) return array_values($invoices);
  $customerIds = array();
  foreach (mikhmonVisibleCustomers($session) as $customer) {
    if (isset($customer['id'])) $customerIds[(string) $customer['id']] = true;
  }
  return array_values(array_filter($invoices, function ($invoice) use ($customerIds) {
    return isset($invoice['customer_id']) && isset($customerIds[(string) $invoice['customer_id']]);
  }));
}

function mikhmonCanManageCustomer($customer) {
  if (mikhmonIsAdmin()) return true;
  return mikhmonIsMitra() && isset($customer['mitra_id']) && (string) $customer['mitra_id'] === mikhmonUserId();
}

function mikhmonMitraUsernames($session) {
  $usernames = array();
  foreach (mikhmonVisibleCustomers($session) as $customer) {
    foreach (mikhmonCustomerServices($customer) as $service) {
      if (!empty($service['username'])) $usernames[(string) $service['username']] = true;
    }
  }
  return $usernames;
}

function mikhmonMitraUsernamesByService($session, $service) {
  $usernames = array();
  foreach (mikhmonVisibleCustomers($session) as $customer) {
    foreach (mikhmonCustomerServices($customer) as $customerService) {
      if ($customerService['service'] === $service && !empty($customerService['username'])) $usernames[(string) $customerService['username']] = true;
    }
  }
  return $usernames;
}

function mikhmonOwnerTag() {
  return mikhmonIsMitra() ? '[mitra:' . mikhmonUserId() . ']' : '';
}

function mikhmonRowBelongsToCurrentMitra($row) {
  if (!mikhmonIsMitra()) return true;
  $text = is_array($row) ? implode(' ', array_map('strval', $row)) : (string) $row;
  return strpos($text, mikhmonOwnerTag()) !== false;
}

function mikhmonCanManageHotspotUser($session, $row) {
  if (!mikhmonIsMitra()) return true;
  if (mikhmonRowBelongsToCurrentMitra($row)) return true;
  $name = is_array($row) && isset($row['name']) ? (string) $row['name'] : '';
  if ($name === '') return false;
  $assignedUsernames = mikhmonMitraUsernames($session);
  return isset($assignedUsernames[$name]);
}

function mikhmonCanOpenMainRoute($route) {
  if (mikhmonIsAdmin()) return true;
  if (mikhmonIsCustomer()) return in_array($route, array('customer-portal', 'logout'), true);
  if (mikhmonIsBiller()) return in_array($route, array('billing', 'commission', 'logout'), true);
  if (mikhmonIsMitra()) return in_array($route, array('home', 'billing', 'customer-list', 'customer-identity-list', 'customer-identity-add', 'customer-identity-edit', 'customer-service-add', 'customer-service-edit', 'report-selling', 'report-resume', 'hotspot-generate', 'hotspot-active', 'hotspot-vouchers', 'hotspot-users', 'hotspot-print-center', 'hotspot-user-edit', 'hotspot-user-mutate', 'pppoe-users', 'pppoe-active', 'logout'), true);
  return false;
}

function mikhmonBillerCommissionAmount() {
  return 2500;
}

function mikhmonBillerCommissionStats($session, $userId = '') {
  $count = 0;
  $monthCount = 0;
  $month = date('Ym');
  foreach (mikhmonGetInvoices($session) as $invoice) {
    if (!isset($invoice['status']) || $invoice['status'] !== 'paid' || empty($invoice['paid_by_user_id'])) continue;
    if ($userId !== '' && (string) $invoice['paid_by_user_id'] !== (string) $userId) continue;
    $count++;
    if (!empty($invoice['paid_at']) && date('Ym', (int) $invoice['paid_at']) === $month) $monthCount++;
  }
  return array(
    'count' => $count,
    'amount' => $count * mikhmonBillerCommissionAmount(),
    'month_count' => $monthCount,
    'month_amount' => $monthCount * mikhmonBillerCommissionAmount(),
  );
}
