<?php

session_save_path('/tmp');
session_start();
date_default_timezone_set('Asia/Jakarta');
require dirname(__DIR__) . '/lib/customer_portal.php';

function customerPortalTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$invoice = array(
  'id' => 'voucher-invoice-a',
  'number' => 'VCR-20260901140905-2F0BAEE1',
  'kind' => 'voucher',
  'amount' => 1000,
  'voucher_validity' => '4h',
  'paid_at' => strtotime('2026-09-01 21:09:24'),
  'voucher_username' => 'ABC123XYZ',
  'voucher_password' => 'ABC123XYZ',
);
$message = mikhmonCustomerPortalVoucherPaymentMessage($invoice, array('name' => 'Apri'));
customerPortalTestAssert(strpos($message, 'Pembayaran invoice VCR-20260901140905-2F0BAEE1 telah diterima.') !== false, 'voucher message identifies the paid invoice');
customerPortalTestAssert(strpos($message, "Nama: Apri\nTotal Dibayar: Rp 1.000\nTanggal Bayar: 2026-09-01 21:09:24") !== false, 'voucher message contains payment details');
customerPortalTestAssert(strpos($message, 'Kode Voucher: ABC123XYZ') !== false, 'voucher code is included in the direct message');
customerPortalTestAssert(strpos($message, 'Masa Berlaku: 4 jam') !== false, 'voucher validity is included in the direct message');
customerPortalTestAssert(strpos($message, 'Username:') === false && strpos($message, 'Password:') === false, 'duplicate username and password labels are omitted');
customerPortalTestAssert(strpos($message, 'Jatuh tempo berikutnya') === false && strpos($message, 'Layanan Anda telah aktif kembali') === false, 'monthly service wording is excluded from voucher messages');
customerPortalTestAssert(mikhmonCustomerPortalVoucherValidityLabel('4h') === '4 jam', 'hour voucher validity is formatted for customers');

echo 'customer-portal-tests: OK' . PHP_EOL;
