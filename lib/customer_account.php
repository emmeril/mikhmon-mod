<?php

/**
 * Helpers for sharing a customer's Hotspot credentials over WhatsApp.
 */

function mikhmonCustomerAccountMessage($customerName, $service, $password, $brand = 'MIKHMON') {
  $service = is_array($service) ? $service : array();
  if (($service['service'] ?? '') !== 'hotspot') return '';

  $customerName = trim((string) $customerName);
  $username = trim((string) ($service['username'] ?? ''));
  $password = (string) $password;
  $profile = trim((string) ($service['profile'] ?? ''));
  $brand = trim((string) $brand);
  if ($customerName === '') $customerName = 'Pelanggan';
  if ($brand === '') $brand = 'MIKHMON';
  if ($username === '' || $password === '') return '';

  $lines = array(
    'Halo Bapak/Ibu ' . $customerName . ',',
    '',
    'Berikut akun Hotspot ' . $brand . ':',
    'Username: ' . $username,
    'Password: ' . $password,
  );
  if ($profile !== '') $lines[] = 'Paket/Profile: ' . $profile;
  $lines[] = '';
  $lines[] = 'Silakan simpan informasi akun ini. Terima kasih.';
  return implode("\n", $lines);
}

function mikhmonCustomerAccountPhone($phone, $countryCode = '62') {
  $phone = preg_replace('/[^0-9]/', '', (string) $phone);
  $countryCode = preg_replace('/[^0-9]/', '', (string) $countryCode);
  if ($countryCode === '') $countryCode = '62';
  if ($phone !== '' && substr($phone, 0, 1) === '0') $phone = $countryCode . substr($phone, 1);
  return $phone;
}

function mikhmonCustomerAccountWhatsAppUrl($phone, $message, $countryCode = '62') {
  $phone = mikhmonCustomerAccountPhone($phone, $countryCode);
  $message = trim((string) $message);
  if ($phone === '' || $message === '') return '';
  return 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);
}
