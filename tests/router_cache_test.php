<?php

require dirname(__DIR__) . '/lib/router_cache.php';

function routerCacheTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

class RouterCacheFakeApi {
  public $calls = 0;
  public function comm($command, $arguments = array()) {
    $this->calls++;
    return array(array('name' => 'router-' . $this->calls));
  }
}

$cachePath = tempnam(sys_get_temp_dir(), 'mikhmon-router-cache-');
putenv('MIKHMON_ROUTER_CACHE_PATH=' . $cachePath);
$api = new RouterCacheFakeApi();

$first = mikhmonRouterCachedComm($api, 'router-a', '/system/identity/print', array('.proplist' => 'name'), 30);
$second = mikhmonRouterCachedComm($api, 'router-a', '/system/identity/print', array('.proplist' => 'name'), 30);
routerCacheTestAssert($api->calls === 1 && $first === $second, 'identical safe reads reuse the short-lived cache');

mikhmonRouterCachedComm($api, 'router-b', '/system/identity/print', array('.proplist' => 'name'), 30);
routerCacheTestAssert($api->calls === 2, 'cache entries are isolated by router session');

mikhmonRouterCachedComm($api, 'router-a', '/system/resource/print', array(), 30);
routerCacheTestAssert($api->calls === 3, 'cache entries are isolated by command');

@unlink($cachePath);
putenv('MIKHMON_ROUTER_CACHE_PATH');
echo 'router-cache-tests: OK' . PHP_EOL;
