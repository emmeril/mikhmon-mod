<?php

include_once(__DIR__ . '/database.php');

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

function mikhmonAssignedSession() {
  return isset($_SESSION['mikhmon_router_session']) ? (string) $_SESSION['mikhmon_router_session'] : '';
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
  if (mikhmonIsAdmin()) return true;
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

function mikhmonCanManageCustomer($customer) {
  if (mikhmonIsAdmin()) return true;
  return mikhmonIsMitra() && isset($customer['mitra_id']) && (string) $customer['mitra_id'] === mikhmonUserId();
}

function mikhmonMitraUsernames($session) {
  $usernames = array();
  foreach (mikhmonVisibleCustomers($session) as $customer) {
    if (!empty($customer['username'])) $usernames[(string) $customer['username']] = true;
  }
  return $usernames;
}

function mikhmonMitraUsernamesByService($session, $service) {
  $usernames = array();
  foreach (mikhmonVisibleCustomers($session) as $customer) {
    $customerService = isset($customer['service']) && $customer['service'] === 'pppoe' ? 'pppoe' : 'hotspot';
    if ($customerService === $service && !empty($customer['username'])) $usernames[(string) $customer['username']] = true;
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

function mikhmonCanOpenMainRoute($route) {
  if (mikhmonIsAdmin()) return true;
  if (mikhmonIsBiller()) return $route === 'billing' || $route === 'logout';
  if (mikhmonIsMitra()) return in_array($route, array('home', 'customer-list', 'customer-add', 'customer-edit', 'report-selling', 'report-resume', 'hotspot-generate', 'hotspot-active', 'hotspot-vouchers', 'hotspot-users', 'pppoe-users', 'pppoe-active', 'logout'), true);
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
