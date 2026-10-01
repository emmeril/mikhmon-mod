<?php

function dashboardCardOrderAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$dashboardSources = array(
  'admin dashboard' => file_get_contents(dirname(__DIR__) . '/dashboard/home.php'),
  'partner dashboard' => file_get_contents(dirname(__DIR__) . '/dashboard/rolehome.php'),
);

foreach ($dashboardSources as $dashboardName => $source) {
  $pppoePosition = strpos($source, 'id="r_ppp"');
  $customerPosition = strpos($source, 'fa fa-address-card');
  $incomePosition = strpos($source, 'id="r_income"');
  $trafficPosition = strpos($source, 'fa fa-area-chart');

  dashboardCardOrderAssert($pppoePosition !== false, $dashboardName . ' contains the PPPoE card');
  dashboardCardOrderAssert($customerPosition > $pppoePosition, $dashboardName . ' places the customer card after PPPoE');
  dashboardCardOrderAssert($incomePosition > $customerPosition, $dashboardName . ' places the income card after customers');
  dashboardCardOrderAssert($trafficPosition > $incomePosition, $dashboardName . ' places the income card before traffic');
  dashboardCardOrderAssert(strpos($source, 'Voucher Hari Ini') !== false, $dashboardName . ' shows today voucher revenue');
  dashboardCardOrderAssert(strpos($source, 'Voucher Bulan Ini') !== false, $dashboardName . ' shows monthly voucher revenue');
  dashboardCardOrderAssert(strpos($source, 'Pelanggan Bulan Ini') !== false, $dashboardName . ' shows monthly customer revenue');
}

dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], 'grid-template-rows: repeat(2, minmax(0, 1fr));') !== false, 'admin dashboard divides the desktop log column into two stable rows');
dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], '.dashboard-main-right {') !== false && strpos($dashboardSources['admin dashboard'], 'position: absolute;') !== false && strpos($dashboardSources['admin dashboard'], 'bottom: 0;') !== false, 'admin dashboard log column ends at the bottom of the traffic column');
$adminSidebarPosition = strpos($dashboardSources['admin dashboard'], 'class="col-4 dashboard-main-column dashboard-main-right"');
$adminSystemSummaryPosition = strpos($dashboardSources['admin dashboard'], 'id="r_1"');
$adminLogPosition = strpos($dashboardSources['admin dashboard'], 'id="r_3"');
dashboardCardOrderAssert($adminSystemSummaryPosition > $adminSidebarPosition && $adminLogPosition > $adminSystemSummaryPosition, 'admin system summary is grouped above the logs in the right column');
dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], 'max-height: none !important;') !== false, 'admin dashboard logs fill their assigned row');
dashboardCardOrderAssert(strpos($dashboardSources['partner dashboard'], 'max-height: 320px !important;') !== false, 'partner dashboard keeps its single log card compact');

echo 'dashboard-card-order-tests: OK' . PHP_EOL;
