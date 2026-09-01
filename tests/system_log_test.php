<?php

$systemLogPath = sys_get_temp_dir() . '/mikhmon-system-log-' . getmypid() . '.jsonl';
putenv('MIKHMON_SYSTEM_LOG_PATH=' . $systemLogPath);
require dirname(__DIR__) . '/include/systemlog.php';

function systemLogTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

@unlink($systemLogPath);
systemLogTestAssert(mikhmonSystemLog('success', 'Autentikasi', 'Admin berhasil login.', array(
  'user' => 'admin',
  'role' => 'admin',
  'session' => 'router-a',
  'ip' => '127.0.0.1',
  'timezone' => 'Asia/Jakarta',
)), 'system log can be written');
systemLogTestAssert(mikhmonSystemLog('invalid-level', 'Pengaturan', 'Brand diperbarui.', array('user' => 'admin')), 'second system log can be written');

$records = mikhmonReadSystemLogs(20);
systemLogTestAssert(count($records) === 2, 'system logs can be read');
systemLogTestAssert($records[0]['message'] === 'Brand diperbarui.', 'newest system log is returned first');
systemLogTestAssert($records[0]['level'] === 'info', 'unknown levels are normalized');
systemLogTestAssert($records[1]['session'] === 'router-a' && $records[1]['ip'] === '127.0.0.1', 'system log context is preserved');
systemLogTestAssert($records[1]['timezone'] === 'Asia/Jakarta', 'system log timezone is preserved');
systemLogTestAssert(mikhmonSystemLog('info', 'Autentikasi', 'Login admin.', array('session' => 'mikhmon')), 'special router context can be logged');
systemLogTestAssert(mikhmonReadSystemLogs(1)[0]['session'] === '', 'special mikhmon context is hidden from the UI');
systemLogTestAssert(count(mikhmonReadSystemLogs(1)) === 1, 'system log read limit is respected');

@unlink($systemLogPath);
echo 'system-log-tests: OK' . PHP_EOL;
