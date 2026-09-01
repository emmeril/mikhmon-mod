<?php

function mikhmonSystemLogPath() {
  $override = getenv('MIKHMON_SYSTEM_LOG_PATH');
  if ($override !== false && trim($override) !== '') return $override;
  return dirname(__DIR__) . '/data/system.log';
}

function mikhmonSystemLogNormalizeLevel($level) {
  $level = strtolower(trim((string) $level));
  return in_array($level, array('info', 'success', 'warning', 'error'), true) ? $level : 'info';
}

function mikhmonSystemLog($level, $category, $message, $context = array()) {
  $path = mikhmonSystemLogPath();
  $directory = dirname($path);
  if (!is_dir($directory) && !@mkdir($directory, 0700, true)) return false;

  // Keep the active audit file small enough to read on every dashboard refresh.
  if (is_file($path) && @filesize($path) > 1048576) {
    $archive = $path . '.1';
    if (is_file($archive)) @unlink($archive);
    @rename($path, $archive);
  }

  $record = array(
    'timestamp' => time(),
    'level' => mikhmonSystemLogNormalizeLevel($level),
    'category' => substr(trim(strip_tags((string) $category)), 0, 60),
    'message' => substr(trim(strip_tags((string) $message)), 0, 500),
    'user' => substr(trim(strip_tags((string) ($context['user'] ?? 'System'))), 0, 100),
    'role' => substr(trim(strip_tags((string) ($context['role'] ?? 'system'))), 0, 30),
    'session' => substr(trim(strip_tags((string) ($context['session'] ?? ''))), 0, 100),
    'ip' => substr(trim(strip_tags((string) ($context['ip'] ?? ''))), 0, 64),
  );
  if ($record['category'] === '') $record['category'] = 'Aplikasi';
  if ($record['message'] === '') return false;

  $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($encoded === false || @file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) === false) return false;
  @chmod($path, 0600);
  return true;
}

function mikhmonSystemLogCurrentUser($extra = array()) {
  return array_merge(array(
    'user' => function_exists('mikhmonUserName') ? mikhmonUserName() : ($_SESSION['mikhmon'] ?? 'System'),
    'role' => function_exists('mikhmonRole') ? mikhmonRole() : ($_SESSION['mikhmon_role'] ?? 'system'),
    'session' => isset($_GET['session']) ? (string) $_GET['session'] : '',
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
  ), (array) $extra);
}

function mikhmonReadSystemLogs($limit = 20) {
  $path = mikhmonSystemLogPath();
  $limit = max(1, min(200, (int) $limit));
  if (!is_file($path) || !is_readable($path)) return array();

  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($lines)) return array();
  $records = array();
  $start = count($lines) - 1;
  for ($index = $start; $index >= 0; $index--) {
    $line = $lines[$index];
    $record = json_decode($line, true);
    if (!is_array($record) || empty($record['message'])) continue;
    $records[] = array(
      'timestamp' => (int) ($record['timestamp'] ?? 0),
      'level' => mikhmonSystemLogNormalizeLevel($record['level'] ?? 'info'),
      'category' => (string) ($record['category'] ?? 'Aplikasi'),
      'message' => (string) $record['message'],
      'user' => (string) ($record['user'] ?? 'System'),
      'role' => (string) ($record['role'] ?? 'system'),
      'session' => (string) ($record['session'] ?? ''),
      'ip' => (string) ($record['ip'] ?? ''),
    );
    if (count($records) >= $limit) break;
  }
  return $records;
}
