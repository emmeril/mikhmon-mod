<?php

session_save_path('/tmp');
session_start();
require dirname(__DIR__) . '/include/access.php';

function roleTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

roleTestAssert(getenv('MIKHMON_DATABASE_PATH') !== false, 'isolated database path is required');
roleTestAssert(mikhmonDefaultRouterSession(array('mikhmon' => array(), 'router-a' => array('configured'))) === 'router-a', 'admin landing selects the first configured router');
roleTestAssert(mikhmonAdminLandingUrl(array('mikhmon' => array(), 'router-a' => array('configured'))) === './?session=router-a', 'admin landing opens the router dashboard');
roleTestAssert(mikhmonAdminLandingUrl(array('mikhmon' => array())) === './admin.php?id=sessions', 'admin landing falls back to settings when no router exists');
$routerConfigLine = mikhmonBuildRouterConfigLine('router-a', array('1' => 'router-a!10.0.0.1', '2' => 'router-a@|@admin'));
roleTestAssert(substr_count(trim($routerConfigLine), "\n") === 0, 'new router config stays on one removable line');
roleTestAssert(strpos($routerConfigLine, "\$data['router-a'] = array (") !== false, 'new router config uses the existing config format');

$mitraId = mikhmonSaveUser('', 'Mitra Satu', 'mitra1', 'mitra', 'router-a', 'secret', true);
$billerId = mikhmonSaveUser('', 'Biller Satu', 'biller1', 'biller', 'router-a', 'secret', true);
roleTestAssert($mitraId !== false && $billerId !== false, 'roles can be saved');
roleTestAssert(mikhmonLoginStaff('MITRA1', 'secret')['role'] === 'mitra', 'staff password login is case-insensitive');
roleTestAssert(mikhmonSaveUser($mitraId, 'Mitra Satu Update', 'mitra1', 'mitra', 'router-a', '', true) === $mitraId, 'editing keeps the existing password');
roleTestAssert(mikhmonSaveUser('', 'Duplikat', 'MITRA1', 'mitra', 'router-a', 'secret', true) === false, 'staff usernames stay unique');

$customerOne = mikhmonSaveCustomer('router-a', '', 'Pelanggan A', '', '', 'hotspot', 'cust-a', 'basic', $mitraId);
$customerTwo = mikhmonSaveCustomer('router-a', '', 'Pelanggan B', '', '', 'pppoe', 'cust-b', 'basic', '');
roleTestAssert($customerOne !== false && $customerTwo !== false, 'customers can be assigned');
roleTestAssert(mikhmonAssignedCustomerCount($mitraId) === 1, 'assigned customer count is tracked');

mikhmonSetLoginSession(mikhmonFindUser($mitraId));
roleTestAssert(mikhmonRefreshStaffSession(), 'active staff session remains valid');
roleTestAssert(count(mikhmonVisibleCustomers('router-a')) === 1, 'mitra only sees assigned customers');
roleTestAssert(isset(mikhmonMitraUsernames('router-a')['cust-a']), 'report scope uses assigned usernames');
roleTestAssert(isset(mikhmonMitraUsernamesByService('router-a', 'hotspot')['cust-a']), 'hotspot customer scope is separated');
roleTestAssert(count(mikhmonMitraUsernamesByService('router-a', 'pppoe')) === 0, 'unassigned PPPoE customers stay hidden');
roleTestAssert(mikhmonCanOpenMainRoute('report-selling'), 'mitra can open scoped reports');
roleTestAssert(mikhmonCanOpenMainRoute('customer-add'), 'mitra can add customers');
roleTestAssert(mikhmonCanOpenMainRoute('hotspot-generate'), 'mitra can generate vouchers');
roleTestAssert(mikhmonCanOpenMainRoute('hotspot-active'), 'mitra can view active users');
roleTestAssert(mikhmonCanOpenMainRoute('hotspot-vouchers'), 'mitra can view own vouchers');
roleTestAssert(mikhmonCanOpenMainRoute('pppoe-users'), 'mitra can view assigned PPPoE users');
roleTestAssert(mikhmonCanOpenMainRoute('pppoe-active'), 'mitra can view assigned PPPoE active sessions');
roleTestAssert(!mikhmonCanOpenMainRoute('billing'), 'mitra cannot open billing');
roleTestAssert(!mikhmonCanOpenMainRoute('admin-settings'), 'mitra cannot open admin settings');
roleTestAssert(!mikhmonCanOpenMainRoute('admin-routers'), 'mitra cannot open router management');
roleTestAssert(!mikhmonCanOpenMainRoute('admin-users'), 'mitra cannot open user role management');
roleTestAssert(strpos(mikhmonOwnerTag(), $mitraId) !== false, 'mitra voucher owner tag is generated');
roleTestAssert(mikhmonRowBelongsToCurrentMitra(array('comment' => mikhmonOwnerTag() . ' promo')), 'owned voucher rows are recognized');

mikhmonSetLoginSession(mikhmonFindUser($billerId));
roleTestAssert(mikhmonCanOpenMainRoute('billing'), 'biller can open billing');
roleTestAssert(!mikhmonCanOpenMainRoute('customer-list'), 'biller cannot open customer management');

$invoice = array(
  'id' => 'invoice-test',
  'customer_id' => $customerOne,
  'status' => 'paid',
  'paid_at' => time(),
  'paid_by_user_id' => $billerId,
  'biller_commission' => 2500,
);
roleTestAssert(mikhmonSaveInvoice('router-a', $invoice) !== false, 'paid invoice can be saved');
$stats = mikhmonBillerCommissionStats('router-a', $billerId);
roleTestAssert($stats['count'] === 1 && $stats['amount'] === 2500, 'biller earns Rp2.500 once per paid invoice');

echo 'role-access-tests: OK' . PHP_EOL;
