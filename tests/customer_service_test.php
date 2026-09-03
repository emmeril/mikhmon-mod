<?php

require dirname(__DIR__) . '/lib/customer_service.php';

function customerServiceTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$customer = array('name' => 'Pelanggan Satu');

customerServiceTestAssert(
  mikhmonServiceRouterRowBelongsToCustomer(array('comment' => 'up-Pelanggan Satu'), 'hotspot', $customer),
  'a delayed hotspot user created for the same customer can be linked'
);
customerServiceTestAssert(
  mikhmonServiceRouterRowBelongsToCustomer(array('comment' => 'Pelanggan Satu'), 'pppoe', $customer),
  'a delayed PPPoE user created for the same customer can be linked'
);
customerServiceTestAssert(
  mikhmonServiceRouterRowBelongsToCustomer(array('comment' => 'up-Pelanggan Satu [mitra:abc]'), 'hotspot', $customer, '[mitra:abc]'),
  'a mitra can recover a router user with its ownership tag'
);
customerServiceTestAssert(
  mikhmonServiceRouterRowBelongsToCustomer(array('comment' => 'up-Pelanggan Satu [mitra:abc]'), 'hotspot', $customer),
  'an admin can recover a tagged router user after a delayed sync'
);
customerServiceTestAssert(
  !mikhmonServiceRouterRowBelongsToCustomer(array('comment' => 'up-Pelanggan Lain'), 'hotspot', $customer),
  'a router user belonging to another customer is rejected'
);
customerServiceTestAssert(
  !mikhmonServiceRouterRowBelongsToCustomer(array('comment' => 'up-Pelanggan Satu [mitra:lain]'), 'hotspot', $customer, '[mitra:abc]'),
  'a router user belonging to another mitra is rejected'
);

echo 'customer-service-tests: OK' . PHP_EOL;
