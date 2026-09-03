<?php
include_once(__DIR__ . '/../lib/fonnte.php');

$redirect = './?hotspot=users&profile=all&session=' . rawurlencode($session);
$fail = function ($message) use ($redirect) {
  header('Location: ' . $redirect . '&fonnte=error&message=' . rawurlencode((string) $message));
  exit;
};

$config = mikhmonFonnteReadConfig();
if (empty($config['enabled']) || empty($config['token'])) $fail('Gateway Fonnte belum diaktifkan.');

$rows = $API->comm('/ip/hotspot/user/print', array('?.id' => (string) $sendfonntevoucher));
$user = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : array();
if (empty($user['name'])) $fail('Voucher tidak ditemukan.');
if (function_exists('mikhmonCanManageHotspotUser') && !mikhmonCanManageHotspotUser($session, $user)) $fail('Akses voucher ditolak.');

$target = '';
$customers = function_exists('mikhmonVisibleCustomers') ? mikhmonVisibleCustomers($session) : mikhmonGetCustomers($session);
foreach ($customers as $customer) {
  foreach (mikhmonCustomerServices($customer) as $service) {
    if (($service['service'] ?? '') === 'hotspot' && (string) ($service['username'] ?? '') === (string) $user['name']) {
      $target = mikhmonCustomerPhone($customer['phone'] ?? '');
      break 2;
    }
  }
}
if ($target === '') $fail('Nomor WhatsApp pelanggan untuk voucher ini belum tersedia.');

$profileRows = $API->comm('/ip/hotspot/user/profile/print', array('?name' => (string) ($user['profile'] ?? '')));
$profile = isset($profileRows[0]) && is_array($profileRows[0]) ? $profileRows[0] : array();
$profileParts = explode(',', (string) ($profile['on-login'] ?? ''));
$lines = array();
$name = (string) ($user['name'] ?? '');
$password = (string) ($user['password'] ?? '');
$lines[] = $name === $password ? 'Voucher: *' . $name . '*' : 'Username: *' . $name . '*';
$lines[] = 'Password: *' . $password . '*';
if (!empty($profileParts[3])) $lines[] = 'Validity: *' . trim((string) $profileParts[3]) . '*';
if (!empty($user['limit-uptime']) && (string) $user['limit-uptime'] !== '0') $lines[] = 'Time Limit: *' . $user['limit-uptime'] . '*';
if (!empty($user['limit-bytes-total']) && (string) $user['limit-bytes-total'] !== '0') $lines[] = 'Data Limit: *' . formatBytes($user['limit-bytes-total'], 2) . '*';
$lines[] = 'Login: *http://' . ($dnsname ?? '') . '*';
$message = '*' . ($hotspotname ?? 'Mikhmon') . "*\n\n" . implode("\n", $lines);

$result = mikhmonFonnteSend($target, $message, $config);
if (empty($result['status'])) $fail($result['reason'] ?? 'Pesan gagal dikirim melalui Fonnte.');
header('Location: ' . $redirect . '&fonnte=success&message=' . rawurlencode('Voucher berhasil dikirim melalui Fonnte.'));
exit;
