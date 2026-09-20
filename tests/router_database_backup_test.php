<?php

$testFiles = array();
register_shutdown_function(function () use (&$testFiles) {
  foreach ($testFiles as $file) if (is_file($file)) @unlink($file);
});

$sourceDatabase = sys_get_temp_dir() . '/mikhmon-router-db-source-' . bin2hex(random_bytes(6)) . '.json';
$targetDatabase = sys_get_temp_dir() . '/mikhmon-router-db-target-' . bin2hex(random_bytes(6)) . '.json';
$testFiles[] = $sourceDatabase;
$testFiles[] = $sourceDatabase . '.router-script-backup.json';
$testFiles[] = $targetDatabase;
$testFiles[] = $targetDatabase . '.router-script-backup.json';
putenv('MIKHMON_DATABASE_PATH=' . $sourceDatabase);

require dirname(__DIR__) . '/include/database.php';

function routerDatabaseBackupTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

class RouterDatabaseBackupFakeApi {
  public $scripts = array();
  private $nextId = 1;

  public function comm($command, $arguments = array()) {
    if ($command === '/system/script/print') return array_values($this->scripts);
    if ($command === '/system/script/add') {
      foreach ($this->scripts as $script) if (($script['name'] ?? '') === ($arguments['name'] ?? '')) return array('!trap' => array(array('message' => 'duplicate name')));
      $id = '*' . $this->nextId++;
      $arguments['.id'] = $id;
      $this->scripts[$id] = $arguments;
      return $id;
    }
    if ($command === '/system/script/set') {
      $id = (string) ($arguments['.id'] ?? '');
      if (!isset($this->scripts[$id])) return array('!trap' => array(array('message' => 'script not found')));
      unset($arguments['.id']);
      $this->scripts[$id] = array_merge($this->scripts[$id], $arguments);
      return true;
    }
    if ($command === '/system/script/remove') {
      $id = (string) ($arguments['.id'] ?? '');
      if (!isset($this->scripts[$id])) return array('!trap' => array(array('message' => 'script not found')));
      unset($this->scripts[$id]);
      return true;
    }
    return array();
  }
}

$mitraId = mikhmonSaveUser('', 'Mitra Backup', 'mitra-backup', 'mitra', 'router-lama', 'rahasia-mitra', true);
$longAddress = 'Alamat pelanggan ' . bin2hex(random_bytes(12000));
$customerId = mikhmonSaveCustomer(
  'router-lama',
  '',
  'Pelanggan Backup',
  '081234567890',
  $longAddress,
  'hotspot',
  'pelanggan-backup',
  'bulanan',
  $mitraId
);
routerDatabaseBackupTestAssert($mitraId !== false && $customerId !== false, 'source customer and mitra are created');
routerDatabaseBackupTestAssert(mikhmonSaveInvoice('router-lama', array(
  'id' => 'invoice-backup',
  'customer_id' => $customerId,
  'customer_name' => 'Pelanggan Backup',
  'amount' => 150000,
  'status' => 'paid',
  'paid_at' => 1789850000,
)) !== false, 'source invoice is created');

$api = new RouterDatabaseBackupFakeApi();
$stored = mikhmonStoreRouterDatabaseBackup($api, 'router-lama', 'password-pemulihan');
routerDatabaseBackupTestAssert(!empty($stored['status']), 'encrypted database is stored in RouterOS scripts');
routerDatabaseBackupTestAssert($stored['chunks'] > 1, 'large backups are split into multiple chunks');
routerDatabaseBackupTestAssert(mikhmonRouterDatabaseManifest($api)['session'] === 'router-lama', 'manifest identifies the source session');
$firstGeneration = mikhmonRouterDatabaseManifest($api)['generation'];
foreach ($api->scripts as $script) {
  routerDatabaseBackupTestAssert(strpos((string) ($script['source'] ?? ''), '081234567890') === false, 'customer phone is not stored as plaintext');
  routerDatabaseBackupTestAssert(strpos((string) ($script['source'] ?? ''), 'Pelanggan Backup') === false, 'customer name is not stored as plaintext');
}
routerDatabaseBackupTestAssert(empty(mikhmonReadRouterDatabaseBackup($api, 'password-salah')['status']), 'an incorrect recovery password is rejected');
routerDatabaseBackupTestAssert(mikhmonSaveInvoice('router-lama', array(
  'id' => 'invoice-backup-2',
  'customer_id' => $customerId,
  'customer_name' => 'Pelanggan Backup',
  'amount' => 175000,
  'status' => 'unpaid',
)) !== false, 'database can change after the first router backup');
$updated = mikhmonStoreRouterDatabaseBackup($api, 'router-lama', 'password-pemulihan');
routerDatabaseBackupTestAssert(!empty($updated['status']) && $updated['manifest']['generation'] !== $firstGeneration, 'a changed database creates a new backup generation');
foreach ($api->scripts as $script) routerDatabaseBackupTestAssert(strpos((string) ($script['name'] ?? ''), 'mikhmon-db-' . $firstGeneration . '-') !== 0, 'old chunks are removed after the new manifest is active');

putenv('MIKHMON_DATABASE_PATH=' . $targetDatabase);
$existingMitraId = mikhmonSaveUser('', 'Mitra Existing', 'mitra-backup', 'mitra', 'router-baru', 'password-existing', true);
$existingCustomerId = mikhmonSaveCustomer('router-baru', '', 'Identitas Existing', '', '', 'hotspot', 'pelanggan-backup', 'lama', $existingMitraId);
routerDatabaseBackupTestAssert($existingMitraId !== false && $existingMitraId !== $mitraId && $existingCustomerId !== false && $existingCustomerId !== $customerId, 'target database starts with matching records under different IDs');
$restored = mikhmonRestoreRouterDatabaseBackup($api, 'router-baru', 'password-pemulihan');
routerDatabaseBackupTestAssert(!empty($restored['status']), 'backup is restored into an empty database');
$customers = mikhmonGetCustomers('router-baru');
routerDatabaseBackupTestAssert(count($customers) === 1, 'customer is restored to the target session');
routerDatabaseBackupTestAssert($customers[0]['phone'] === '081234567890', 'customer phone is preserved');
routerDatabaseBackupTestAssert($customers[0]['address'] === $longAddress, 'customer address is preserved');
routerDatabaseBackupTestAssert(count(mikhmonGetInvoices('router-baru')) === 2, 'invoice history is preserved');
$restoredMitra = mikhmonFindUser('mitra-backup', 'username');
routerDatabaseBackupTestAssert($restoredMitra && $restoredMitra['session'] === 'router-baru', 'mitra is restored and remapped to the target session');
routerDatabaseBackupTestAssert($customers[0]['mitra_id'] === $restoredMitra['id'], 'customer assignment to mitra is preserved');
routerDatabaseBackupTestAssert($customers[0]['id'] === $existingCustomerId && mikhmonGetInvoices('router-baru')[0]['customer_id'] === $existingCustomerId, 'invoice customer references follow an existing target identity');
$restoredAgain = mikhmonRestoreRouterDatabaseBackup($api, 'router-baru', 'password-pemulihan');
routerDatabaseBackupTestAssert(!empty($restoredAgain['status']) && count(mikhmonGetCustomers('router-baru')) === 1 && count(mikhmonGetInvoices('router-baru')) === 2, 'restoring the same backup does not create duplicates');

echo 'router-database-backup-tests: OK' . PHP_EOL;
