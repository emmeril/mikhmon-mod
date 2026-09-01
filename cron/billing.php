<?php
// Run every few minutes to process billing reminders and automatic isolation.
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$_SERVER['REQUEST_URI'] = '/cron/billing.php';
error_reporting(E_ALL);
date_default_timezone_set(getenv('MIKHMON_TIMEZONE') ?: 'Asia/Jakarta');
require_once dirname(__DIR__) . '/include/config.php';
require_once dirname(__DIR__) . '/include/database.php';
require_once dirname(__DIR__) . '/lib/routeros_api.class.php';
require_once dirname(__DIR__) . '/lib/fonnte.php';
require_once dirname(__DIR__) . '/lib/payment_gateway.php';
require_once dirname(__DIR__) . '/lib/billing_automation.php';

$lockPath = dirname(__DIR__) . '/data/billing-cron.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
  echo "Billing automation is already running.\n";
  exit;
}
@chmod($lockPath, 0600);

$fonnteConfig = mikhmonFonnteReadConfig();
if (empty($fonnteConfig['automation_enabled']) && empty($fonnteConfig['payment_enabled']) && empty($fonnteConfig['payment_link_enabled'])) {
  echo "Billing automation and payment notifications are disabled.\n";
  exit;
}

$sessions = 0;
foreach ((array) $data as $session => $routerConfig) {
  if ($session === 'mikhmon' || !is_array($routerConfig) || count($routerConfig) < 4) continue;
  $iphost = explode('!', (string) ($routerConfig[1] ?? ''), 2)[1] ?? '';
  $userhost = explode('@|@', (string) ($routerConfig[2] ?? ''), 2)[1] ?? '';
  $password = explode('#|#', (string) ($routerConfig[3] ?? ''), 2)[1] ?? '';
  if ($iphost === '' || $userhost === '' || $password === '') {
    echo $session . ": invalid configuration\n";
    continue;
  }
  $api = new RouterosAPI();
  $api->debug = false;
  if (!$api->connect($iphost, $userhost, decrypt($password))) {
    $result = mikhmonBillingAutomationProcessSession(null, $session, $routerConfig, $fonnteConfig);
    echo $session . ': router connection failed; ' . $result['invoices'] . ' invoice(s), ' . ($result['payment_links'] ?? 0) . ' payment link(s), ' . $result['reminders'] . ' reminder(s), 0 isolated, ' . $result['payments'] . ' payment notice(s), ' . $result['errors'] . " error(s)\n";
    continue;
  }
  $result = mikhmonBillingAutomationProcessSession($api, $session, $routerConfig, $fonnteConfig);
  echo $session . ': ' . $result['invoices'] . ' invoice(s), ' . ($result['payment_links'] ?? 0) . ' payment link(s), ' . $result['reminders'] . ' reminder(s), ' . $result['isolated'] . ' isolated, ' . $result['payments'] . ' payment notice(s), ' . $result['errors'] . " error(s)\n";
  $api->disconnect();
  $sessions++;
}
echo 'Completed: ' . $sessions . " session(s)\n";
flock($lock, LOCK_UN);
fclose($lock);
