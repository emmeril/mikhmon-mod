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
  dashboardCardOrderAssert(strpos($source, 'min-width: 751px) and (max-width: 1100px)') !== false, $dashboardName . ' stacks its main columns at tablet and small-laptop widths');
  dashboardCardOrderAssert(strpos($source, '.dashboard-main-row .box>a{display:block;min-height:44px}') !== false, $dashboardName . ' keeps dashboard links large enough for touch');
  dashboardCardOrderAssert(strpos($source, '.dashboard-main-row a:focus-visible') !== false, $dashboardName . ' shows keyboard focus on dashboard links');
  dashboardCardOrderAssert(strpos($source, '.dashboard-main-row .bg-blue') !== false && strpos($source, 'color:#18222d') !== false, $dashboardName . ' uses readable text contrast on colored cards');
}

dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], 'grid-template-rows: repeat(2, minmax(0, 1fr));') !== false, 'admin dashboard divides the desktop log column into two stable rows');
dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], '.dashboard-main-right {') !== false && strpos($dashboardSources['admin dashboard'], 'position: absolute;') !== false && strpos($dashboardSources['admin dashboard'], 'bottom: 0;') !== false, 'admin dashboard log column ends at the bottom of the traffic column');
$adminSidebarPosition = strpos($dashboardSources['admin dashboard'], 'class="col-4 dashboard-main-column dashboard-main-right"');
$adminSystemSummaryPosition = strpos($dashboardSources['admin dashboard'], 'id="r_1"');
$adminLogPosition = strpos($dashboardSources['admin dashboard'], 'id="r_3"');
dashboardCardOrderAssert($adminSystemSummaryPosition > $adminSidebarPosition && $adminLogPosition > $adminSystemSummaryPosition, 'admin system summary is grouped above the logs in the right column');
dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], 'max-height: none !important;') !== false, 'admin dashboard logs fill their assigned row');
$partnerSidebarPosition = strpos($dashboardSources['partner dashboard'], 'class="col-4 dashboard-main-column dashboard-main-right"');
$partnerSystemSummaryPosition = strpos($dashboardSources['partner dashboard'], 'id="r_1"');
$partnerLogPosition = strpos($dashboardSources['partner dashboard'], 'id="r_3"');
dashboardCardOrderAssert(strpos($dashboardSources['partner dashboard'], 'class="row dashboard-main-row"') !== false, 'partner dashboard uses the same main grid as admin');
dashboardCardOrderAssert($partnerSystemSummaryPosition > $partnerSidebarPosition && $partnerLogPosition > $partnerSystemSummaryPosition, 'partner system summary is grouped above the log in the right column');
dashboardCardOrderAssert(strpos($dashboardSources['partner dashboard'], 'max-height: none !important;') !== false, 'partner dashboard log fills the available right column height');
dashboardCardOrderAssert(strpos($dashboardSources['partner dashboard'], 'mitra-dashboard-main-row') === false, 'partner dashboard no longer uses a separate layout system');

echo 'dashboard-card-order-tests: OK' . PHP_EOL;
