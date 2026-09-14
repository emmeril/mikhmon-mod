<?php

require dirname(__DIR__) . '/lib/customer_account.php';

function customerAccountTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$service = array('service' => 'hotspot', 'username' => 'pelanggan-01', 'profile' => 'Paket 10M');
$message = mikhmonCustomerAccountMessage('Pelanggan Satu', $service, 'rahasia', 'Internet Kita');
customerAccountTestAssert(strpos($message, 'Username: pelanggan-01') !== false, 'message contains the Hotspot username');
customerAccountTestAssert(strpos($message, 'Password: rahasia') !== false, 'message contains the Hotspot password');
customerAccountTestAssert(strpos($message, 'Paket/Profile: Paket 10M') !== false, 'message contains the selected profile');
customerAccountTestAssert(mikhmonCustomerAccountMessage('Pelanggan', array('service' => 'pppoe', 'username' => 'user'), 'pass') === '', 'PPPoE credentials are not shared by the Hotspot action');
customerAccountTestAssert(mikhmonCustomerAccountMessage('Pelanggan', $service, '') === '', 'an unavailable password does not produce a message');

$url = mikhmonCustomerAccountWhatsAppUrl('0812-3456-7890', $message, '62');
customerAccountTestAssert(strpos($url, 'https://wa.me/6281234567890?text=') === 0, 'manual WhatsApp URL uses an international phone number');
customerAccountTestAssert(strpos(rawurldecode($url), 'Password: rahasia') !== false, 'manual WhatsApp URL contains the credential message');
customerAccountTestAssert(mikhmonCustomerAccountWhatsAppUrl('', $message) === '', 'manual WhatsApp URL requires a phone number');

echo 'customer-account-tests: OK' . PHP_EOL;
