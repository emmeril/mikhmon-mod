<?php

function mikhmonRouterCachePath() {
  $override = getenv('MIKHMON_ROUTER_CACHE_PATH');
  if ($override !== false && trim($override) !== '') return $override;
  return dirname(__DIR__) . '/data/router-live-cache.json';
}

function mikhmonRouterResponseHasError($response) {
  if (!is_array($response)) return true;
  return isset($response['!trap']) || isset($response['!fatal']);
}

function mikhmonRouterCachedComm($api, $session, $command, $arguments = array(), $ttl = 10) {
  if (!is_object($api) || !method_exists($api, 'comm')) return array();
  $ttl = max(1, (int) $ttl);
  $path = mikhmonRouterCachePath();
  $directory = dirname($path);
  if (!is_dir($directory)) @mkdir($directory, 0700, true);
  $handle = @fopen($path, 'c+');
  if (!$handle || !@flock($handle, LOCK_EX)) {
    if ($handle) fclose($handle);
    return $api->comm($command, $arguments);
  }

  rewind($handle);
  $cache = json_decode((string) stream_get_contents($handle), true);
  if (!is_array($cache)) $cache = array();
  $key = hash('sha256', (string) $session . "\n" . $command . "\n" . json_encode($arguments));
  $now = time();
  if (isset($cache[$key]['stored_at'], $cache[$key]['value']) && $now - (int) $cache[$key]['stored_at'] < $ttl) {
    $value = $cache[$key]['value'];
    flock($handle, LOCK_UN);
    fclose($handle);
    return $value;
  }

  flock($handle, LOCK_UN);
  fclose($handle);
  $value = $api->comm($command, $arguments);
  if (mikhmonRouterResponseHasError($value)) return $value;

  $handle = @fopen($path, 'c+');
  if (!$handle || !@flock($handle, LOCK_EX)) {
    if ($handle) fclose($handle);
    return $value;
  }
  rewind($handle);
  $cache = json_decode((string) stream_get_contents($handle), true);
  if (!is_array($cache)) $cache = array();
  foreach ($cache as $cacheKey => $entry) {
    if (!isset($entry['stored_at']) || $now - (int) $entry['stored_at'] > 3600) unset($cache[$cacheKey]);
  }
  $cache[$key] = array('stored_at' => $now, 'value' => $value);
  $encoded = json_encode($cache, JSON_UNESCAPED_SLASHES);
  if ($encoded !== false) {
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, $encoded);
    fflush($handle);
    @chmod($path, 0600);
  }
  flock($handle, LOCK_UN);
  fclose($handle);
  return $value;
}
