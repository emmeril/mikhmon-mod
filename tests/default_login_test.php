<?php

$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__) . '/include/config.example.php';
require dirname(__DIR__) . '/include/crypto.php';
require dirname(__DIR__) . '/lib/routeros_api.class.php';

function defaultLoginTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$username = explode('<|<', $data['mikhmon']['1'], 2);
$password = explode('>|>', $data['mikhmon']['2'], 2);

defaultLoginTestAssert(isset($username[1]) && $username[1] === 'admin', 'the initial username is admin');
defaultLoginTestAssert(isset($password[1]) && decrypt($password[1]) === 'admin@123', 'the initial password is admin@123');

echo 'default-login-tests: OK' . PHP_EOL;
