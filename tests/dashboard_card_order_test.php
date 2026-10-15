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
$dashboardAjaxSource = file_get_contents(dirname(__DIR__) . '/dashboard/aload.php');
$systemLogSource = file_get_contents(dirname(__DIR__) . '/dashboard/systemlogs.php');

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

dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], 'grid-template-columns: minmax(0, 2fr) minmax(340px, 1fr);') !== false, 'admin dashboard keeps its two-column desktop layout while allowing the log column to grow');
dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], 'grid-template-rows: repeat(2, auto);') !== false, 'admin dashboard gives both desktop log cards their own natural-height row');
dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], '.dashboard-main-right {') !== false && strpos($dashboardSources['admin dashboard'], 'position: static;') !== false, 'admin dashboard log column contributes its full height to the page');
$adminSidebarPosition = strpos($dashboardSources['admin dashboard'], 'class="col-4 dashboard-main-column dashboard-main-right"');
$adminSystemSummaryPosition = strpos($dashboardSources['admin dashboard'], 'id="r_1"');
$adminLogPosition = strpos($dashboardSources['admin dashboard'], 'id="r_3"');
dashboardCardOrderAssert($adminSystemSummaryPosition > $adminSidebarPosition && $adminLogPosition > $adminSystemSummaryPosition, 'admin system summary is grouped above the logs in the right column');
dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], 'overflow dashboard-log-scroll') !== false, 'admin dashboard applies the taller scroll area to the initial Hotspot log');
dashboardCardOrderAssert(strpos($dashboardAjaxSource, 'overflow dashboard-log-scroll') !== false, 'admin dashboard preserves the taller Hotspot log after an AJAX refresh');
dashboardCardOrderAssert(strpos($systemLogSource, 'overflow dashboard-log-scroll') !== false, 'admin dashboard applies the taller scroll area to the application system log');
dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], 'table-hover mobile-readable-table') !== false, 'admin dashboard keeps the initial Hotspot log readable on mobile');
dashboardCardOrderAssert(strpos($dashboardAjaxSource, 'table-hover mobile-readable-table') !== false && strpos($dashboardAjaxSource, 'mobile-table-detail') !== false, 'admin dashboard keeps refreshed Hotspot log columns readable on mobile');
dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], 'height:420px;max-height:420px!important') !== false, 'admin dashboard provides a 420px log viewport outside mobile screens');
dashboardCardOrderAssert(strpos($dashboardSources['admin dashboard'], 'height:360px;max-height:360px!important') !== false, 'admin dashboard keeps a balanced log viewport on mobile screens');
$partnerSidebarPosition = strpos($dashboardSources['partner dashboard'], 'class="col-4 dashboard-main-column dashboard-main-right"');
$partnerSystemSummaryPosition = strpos($dashboardSources['partner dashboard'], 'id="r_1"');
$partnerLogPosition = strpos($dashboardSources['partner dashboard'], 'id="r_3"');
dashboardCardOrderAssert(strpos($dashboardSources['partner dashboard'], 'class="row dashboard-main-row"') !== false, 'partner dashboard uses the same main grid as admin');
dashboardCardOrderAssert($partnerSystemSummaryPosition > $partnerSidebarPosition && $partnerLogPosition > $partnerSystemSummaryPosition, 'partner system summary is grouped above the log in the right column');
dashboardCardOrderAssert(strpos($dashboardSources['partner dashboard'], 'overflow dashboard-log-scroll') !== false, 'partner dashboard applies the taller scroll area to its Hotspot log');
dashboardCardOrderAssert(strpos($dashboardSources['partner dashboard'], 'table-hover mobile-readable-table') !== false && strpos($dashboardSources['partner dashboard'], 'mobile-table-detail') !== false, 'partner dashboard keeps Hotspot log columns readable on mobile');
dashboardCardOrderAssert(strpos($dashboardSources['partner dashboard'], 'height:420px;max-height:420px!important') !== false, 'partner dashboard provides a 420px Hotspot log viewport outside mobile screens');
dashboardCardOrderAssert(strpos($dashboardSources['partner dashboard'], 'height:360px;max-height:360px!important') !== false, 'partner dashboard keeps a balanced Hotspot log viewport on mobile screens');
dashboardCardOrderAssert(strpos($dashboardSources['partner dashboard'], 'position: static;') !== false, 'partner dashboard log column contributes its natural height to the page');
dashboardCardOrderAssert(strpos($dashboardSources['partner dashboard'], 'mitra-dashboard-main-row') === false, 'partner dashboard no longer uses a separate layout system');

echo 'dashboard-card-order-tests: OK' . PHP_EOL;
