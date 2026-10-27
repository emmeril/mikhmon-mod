<?php

require dirname(__DIR__) . '/include/database.php';

function databaseCliGuardTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$originalPath = getenv('MIKHMON_DATABASE_PATH');
putenv('MIKHMON_DATABASE_PATH');
databaseCliGuardTestAssert(!mikhmonDatabaseWriteAllowed(), 'unapproved CLI writes to the production database are blocked');
$productionPath = mikhmonBackupPath();
$productionHash = is_file($productionPath) ? hash_file('sha256', $productionPath) : null;
databaseCliGuardTestAssert(mikhmonWriteDatabase(array('users' => array())) === false, 'the production database writer rejects an unapproved CLI call');
$productionHashAfter = is_file($productionPath) ? hash_file('sha256', $productionPath) : null;
databaseCliGuardTestAssert($productionHashAfter === $productionHash, 'a rejected CLI write leaves the production database unchanged');
putenv('MIKHMON_DATABASE_PATH=' . $productionPath);
databaseCliGuardTestAssert(!mikhmonDatabaseWriteAllowed(), 'using the production filename as an override does not bypass the guard');

$testDirectory = sys_get_temp_dir() . '/mikhmon-database-cli-guard-' . bin2hex(random_bytes(6));
$testPath = $testDirectory . '/database.json';
putenv('MIKHMON_DATABASE_PATH=' . $testPath);
databaseCliGuardTestAssert(mikhmonDatabaseWriteAllowed(), 'CLI writes to an isolated database are allowed');
databaseCliGuardTestAssert(mikhmonWriteDatabase(array('users' => array())), 'an isolated database can be written');
databaseCliGuardTestAssert(is_file($testPath), 'the isolated database file is created');

@unlink($testPath);
@rmdir($testDirectory);
if ($originalPath === false) putenv('MIKHMON_DATABASE_PATH');
else putenv('MIKHMON_DATABASE_PATH=' . $originalPath);

foreach (array('backup.php', 'billing.php', 'maintenance.php') as $cronFile) {
  $source = (string) file_get_contents(dirname(__DIR__) . '/cron/' . $cronFile);
  databaseCliGuardTestAssert(strpos($source, "define('MIKHMON_ALLOW_PRODUCTION_CLI_WRITE', true);") !== false, $cronFile . ' explicitly allows production database writes');
}

echo 'database-cli-guard-tests: OK' . PHP_EOL;
