<?php

session_save_path('/tmp');
session_start();
require dirname(__DIR__) . '/lib/fonnte.php';

function fonnteTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$configPath = tempnam(sys_get_temp_dir(), 'mikhmon-fonnte-');
putenv('MIKHMON_FONNTE_CONFIG=' . $configPath);

fonnteTestAssert(mikhmonFonnteWriteConfig(array(
  'enabled' => true,
  'token' => " demo-token\r\n",
  'country_code' => '62-id',
)), 'configuration can be stored');

$config = mikhmonFonnteReadConfig();
fonnteTestAssert($config['enabled'] === true, 'enabled state is preserved');
fonnteTestAssert($config['token'] === 'demo-token', 'token is normalized safely');
fonnteTestAssert($config['country_code'] === '62', 'country code contains digits only');

$csrfToken = mikhmonFonnteCsrfToken();
fonnteTestAssert(mikhmonFonnteValidCsrf($csrfToken), 'generated CSRF token is accepted');
fonnteTestAssert(!mikhmonFonnteValidCsrf('invalid'), 'invalid CSRF token is rejected');

$disabledConfig = $config;
$disabledConfig['enabled'] = false;
$sendResult = mikhmonFonnteSend('08123456789', 'Test', $disabledConfig);
fonnteTestAssert($sendResult['status'] === false, 'disabled gateway does not send requests');

@unlink($configPath);
echo 'fonnte-tests: OK' . PHP_EOL;
