<?php
require dirname(__DIR__) . '/include/database.php';

function retentionAssert($condition, $message) {
  if (!$condition) throw new RuntimeException($message);
}

$now = time();
$cutoff = $now - 7 * 86400;
$data = array('routers' => array(
  'online' => array('latest' => array('updated_at' => $now), 'history' => array(
    array('updated_at' => $cutoff - 1),
    array('updated_at' => $cutoff),
    array('updated_at' => $now - 86400),
  )),
  'offline' => array('latest' => array('updated_at' => $cutoff - 1), 'last_checked_at' => $now),
  'legacy' => array('updated_at' => $cutoff - 1),
));
$pruned = mikhmonPruneExpiredRouterBackups($data, $now);
retentionAssert(count($pruned['routers']['online']['history']) === 2, 'Keep snapshots exactly 7 days old and newer');
retentionAssert($pruned['routers']['online']['history'][0]['updated_at'] === $cutoff, 'Reindex retained history');
retentionAssert($pruned['routers']['offline']['latest']['updated_at'] === 0, 'Expire latest snapshot for offline router');
retentionAssert($pruned['routers']['offline']['last_checked_at'] === 0, 'Expired router is due for backup');
retentionAssert($pruned['routers']['legacy']['latest']['updated_at'] === 0, 'Expire legacy snapshots');
retentionAssert(mikhmonPruneExpiredRouterBackups($pruned, $now) === $pruned, 'Cleanup is idempotent');

$path = tempnam(sys_get_temp_dir(), 'mikhmon-retention-');
putenv('MIKHMON_DATABASE_PATH=' . $path);
try {
  file_put_contents($path . '.routers', json_encode($data));
  $loaded = mikhmonReadRouterDatabase();
  $stored = json_decode(file_get_contents($path . '.routers'), true);
  retentionAssert($stored['routers']['offline']['latest']['updated_at'] === $loaded['routers']['offline']['latest']['updated_at'], 'Read cleanup persists to disk');
  retentionAssert($stored['routers']['offline']['latest']['updated_at'] === 0, 'Expired backup removed from disk');
  retentionAssert(mikhmonWriteRouterDatabase($data), 'Write succeeds');
  $stored = json_decode(file_get_contents($path . '.routers'), true);
  retentionAssert($stored['routers']['legacy']['latest']['updated_at'] === 0, 'Write also enforces retention');
  $index = json_decode(file_get_contents($path . '.routers.index'), true);
  retentionAssert($index['routers']['offline']['last_checked_at'] === 0, 'Index updated after cleanup');
} finally {
  foreach (array($path, $path . '.routers', $path . '.routers.index') as $file) {
    if (is_file($file)) unlink($file);
  }
}
echo "Backup retention tests passed\n";
