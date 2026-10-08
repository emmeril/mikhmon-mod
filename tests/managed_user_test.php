<?php

require dirname(__DIR__) . '/include/database.php';

function managedUserTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

managedUserTestAssert(getenv('MIKHMON_DATABASE_PATH') !== false, 'isolated database path is required');

$created = mikhmonSaveManagedUser(array(
  'type' => 'reseller',
  'name' => 'Mitra Satu',
  'session' => 'router-a',
  'login_enabled' => true,
  'username' => 'mitra1',
  'password' => 'secret',
  'active' => true,
  'commission' => 1000,
));
managedUserTestAssert(!empty($created['status']), 'reseller profile and login are created together');
$partner = mikhmonFindPartner($created['partner_id']);
$account = mikhmonFindUser($created['user_id']);
managedUserTestAssert($partner && $partner['user_id'] === $account['id'], 'partner is linked to the generated login');
managedUserTestAssert($account['role'] === 'mitra', 'reseller role is assigned automatically');

$updated = mikhmonSaveManagedUser(array(
  'partner_id' => $partner['id'],
  'user_id' => $account['id'],
  'type' => 'biller',
  'name' => 'Biller Satu',
  'session' => 'router-a',
  'login_enabled' => true,
  'username' => 'mitra1',
  'password' => '',
  'active' => true,
  'commission' => 2500,
));
managedUserTestAssert(!empty($updated['status']), 'partner category can be updated in the unified workflow');
managedUserTestAssert(mikhmonFindUser($account['id'])['role'] === 'biller', 'biller role follows the selected category');
managedUserTestAssert(mikhmonFindPartner($partner['id'])['category'] === 'biller', 'partner category and login role stay synchronized');

$withoutLogin = mikhmonSaveManagedUser(array(
  'type' => 'sales',
  'name' => 'Sales Tanpa Login',
  'session' => 'router-a',
  'active' => true,
));
managedUserTestAssert(!empty($withoutLogin['status']) && $withoutLogin['user_id'] === '', 'sales profile can exist without login access');

$duplicate = mikhmonSaveManagedUser(array(
  'type' => 'reseller',
  'name' => 'Username Ganda',
  'session' => 'router-a',
  'login_enabled' => true,
  'username' => 'MITRA1',
  'password' => 'secret',
  'active' => true,
));
managedUserTestAssert(empty($duplicate['status']), 'duplicate usernames are rejected case-insensitively');

$finance = mikhmonSaveManagedUser(array('type' => 'finance', 'name' => 'Keuangan Satu', 'username' => 'finance1', 'password' => 'secret', 'active' => true));
managedUserTestAssert(!empty($finance['status']) && mikhmonFindUser($finance['user_id'])['role'] === 'finance', 'finance role is stored without a partner profile');
managedUserTestAssert(mikhmonFindUser($finance['user_id'])['session'] === 'mikhmon', 'finance role receives global router scope');
$operator = mikhmonSaveManagedUser(array('type' => 'operator', 'name' => 'Operator Satu', 'session' => 'router-a', 'username' => 'operator1', 'password' => 'secret', 'active' => true));
managedUserTestAssert(!empty($operator['status']) && mikhmonFindUser($operator['user_id'])['role'] === 'operator', 'operator role is stored with one router scope');

managedUserTestAssert(mikhmonSetManagedUserActive($partner['id'], $account['id'], false), 'managed user can be deactivated');
managedUserTestAssert(empty(mikhmonFindPartner($partner['id'])['active']) && empty(mikhmonFindUser($account['id'])['active']), 'profile and login status change together');

mikhmonSaveCustomer('router-a', '', 'Pelanggan Mitra', '', '', 'hotspot', 'cust-managed', 'basic', $account['id']);
$detach = mikhmonSaveManagedUser(array(
  'partner_id' => $partner['id'],
  'user_id' => $account['id'],
  'type' => 'biller',
  'name' => 'Biller Satu',
  'session' => 'router-a',
  'active' => false,
));
managedUserTestAssert(empty($detach['status']), 'login cannot be removed while customers are assigned');
managedUserTestAssert(mikhmonFindPartner($partner['id'])['user_id'] === $account['id'], 'failed detach keeps the existing account link');

echo 'managed-user-tests: OK' . PHP_EOL;
