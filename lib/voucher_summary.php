<?php

require_once(__DIR__ . '/billing_profile.php');
require_once(dirname(__DIR__) . '/report/reportrecord.php');

function mikhmonVoucherUserIsDisabled($user) {
  $disabled = strtolower(trim((string) ($user['disabled'] ?? '')));
  return $disabled === 'true' || $disabled === 'yes';
}

function mikhmonVoucherUserIsExpired($user) {
  return mikhmonVoucherUserIsDisabled($user) || (string) ($user['limit-uptime'] ?? '') === '1s';
}

function mikhmonVoucherUserIsUnused($user) {
  if (mikhmonVoucherUserIsExpired($user)) return false;
  return preg_match('/^\s*(?:vc|up)-/i', (string) ($user['comment'] ?? '')) === 1;
}

function mikhmonVoucherOwnerSummary($profiles, $users, $ownerUserId) {
  $ownerUserId = trim((string) $ownerUserId);
  if ($ownerUserId === '') return array('total' => 0, 'unused' => 0);
  $voucherProfiles = array();
  foreach ((array) $profiles as $profile) {
    if (!isset($profile['name']) || mikhmonBillingProfileExpiredMode('hotspot', $profile) === 'none') continue;
    $voucherProfiles[(string) $profile['name']] = true;
  }
  $ownerTag = '[mitra:' . $ownerUserId . ']';
  $total = 0;
  $unused = 0;
  foreach ((array) $users as $user) {
    if (!isset($user['profile']) || !isset($voucherProfiles[(string) $user['profile']])) continue;
    if (strpos((string) ($user['comment'] ?? ''), $ownerTag) === false) continue;
    $total++;
    if (mikhmonVoucherUserIsUnused($user)) $unused++;
  }
  return array('total' => $total, 'unused' => $unused);
}

function mikhmonVoucherOwnerRevenue($session, $profiles, $ownerUserId, $month = '') {
  $ownerUserId = trim((string) $ownerUserId);
  $month = preg_match('/^\d{6}$/', (string) $month) ? (string) $month : date('Ym');
  $revenue = array('voucher' => 0.0, 'customer' => 0.0);
  if ($ownerUserId === '') return $revenue;

  $customerIds = array();
  if (function_exists('mikhmonGetCustomers')) {
    foreach ((array) mikhmonGetCustomers($session) as $customer) {
      if ((string) ($customer['mitra_id'] ?? '') === $ownerUserId && isset($customer['id'])) {
        $customerIds[(string) $customer['id']] = true;
      }
    }
  }

  $portalVoucherUsernames = array();
  if (function_exists('mikhmonGetInvoices')) {
    foreach ((array) mikhmonGetInvoices($session) as $invoice) {
      if (($invoice['status'] ?? '') !== 'paid' || !isset($customerIds[(string) ($invoice['customer_id'] ?? '')])) continue;
      $isVoucher = ($invoice['kind'] ?? 'monthly') === 'voucher';
      if ($isVoucher && trim((string) ($invoice['voucher_username'] ?? '')) !== '') {
        $portalVoucherUsernames[strtolower(trim((string) $invoice['voucher_username']))] = true;
      }
      $paidAt = (int) ($invoice['paid_at'] ?? 0);
      if ($paidAt <= 0 || date('Ym', $paidAt) !== $month) continue;
      $key = $isVoucher ? 'voucher' : 'customer';
      $revenue[$key] += (float) ($invoice['amount'] ?? 0);
    }
  }

  $voucherProfiles = array();
  foreach ((array) $profiles as $profile) {
    $name = trim((string) ($profile['name'] ?? ''));
    if ($name !== '' && mikhmonBillingProfileExpiredMode('hotspot', $profile) !== 'none') $voucherProfiles[$name] = true;
  }
  $sellingPrices = mikhmonReportProfileSellingPrices($profiles, array());
  $ownerTag = '[mitra:' . $ownerUserId . ']';
  $records = function_exists('mikhmonGetReportRecords') ? mikhmonGetReportRecords($session) : array();
  foreach (mikhmonFilterReportRecords((array) $records) as $record) {
    $parts = mikhmonReportParts($record);
    $startedAt = mikhmonReportRowTimestamp($record);
    $username = strtolower(trim((string) ($parts[2] ?? '')));
    $service = strtolower(trim((string) ($parts[9] ?? 'hotspot')));
    $profile = trim((string) ($parts[7] ?? ''));
    $comment = (string) ($parts[8] ?? '');
    if ($startedAt <= 0 || date('Ym', $startedAt) !== $month || $service !== 'hotspot') continue;
    if (!isset($voucherProfiles[$profile]) || strpos($comment, $ownerTag) === false) continue;
    if ($username !== '' && isset($portalVoucherUsernames[$username])) continue;
    $revenue['voucher'] += mikhmonReportSellingPrice($record, $sellingPrices);
  }

  return $revenue;
}

function mikhmonVoucherValiditySeconds($value) {
  $seconds = 0;
  $multipliers = array('w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1);
  if (preg_match_all('/(\d+)([wdhms])/i', (string) $value, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) $seconds += (int) $match[1] * $multipliers[strtolower($match[2])];
  }
  return $seconds;
}

function mikhmonVoucherProfileModeLabel($mode) {
  $labels = array('rem' => 'Remove', 'remc' => 'Remove & Record', 'ntf' => 'Notice', 'ntfc' => 'Notice & Record');
  return isset($labels[$mode]) ? $labels[$mode] : strtoupper((string) $mode);
}

function mikhmonVoucherSummary($profiles, $users, $records = array(), $now = null) {
  $now = $now === null ? time() : (int) $now;
  $stats = array();
  foreach ((array) $profiles as $profile) {
    if (!isset($profile['name'])) continue;
    $mode = mikhmonBillingProfileExpiredMode('hotspot', $profile);
    if ($mode === 'none') continue;
    $stats[(string) $profile['name']] = array(
      'profile' => $profile, 'mode' => $mode, 'expired_known' => $mode !== 'rem',
      '_total' => array(), '_unused' => array(), '_used' => array(), '_expired' => array(),
    );
  }

  foreach ((array) $users as $user) {
    $profileName = isset($user['profile']) ? (string) $user['profile'] : '';
    if ($profileName === '' || !isset($stats[$profileName])) continue;
    $username = trim((string) ($user['name'] ?? $user['.id'] ?? ''));
    if ($username === '') continue;
    $stats[$profileName]['_total'][$username] = true;
    if (mikhmonVoucherUserIsExpired($user)) $stats[$profileName]['_expired'][$username] = true;
    elseif (mikhmonVoucherUserIsUnused($user)) $stats[$profileName]['_unused'][$username] = true;
    else $stats[$profileName]['_used'][$username] = true;
  }

  foreach (mikhmonFilterReportRecords((array) $records) as $record) {
    $parts = mikhmonReportParts($record);
    $service = isset($parts[9]) ? strtolower(trim((string) $parts[9])) : 'hotspot';
    $profileName = isset($parts[7]) ? trim((string) $parts[7]) : '';
    $username = isset($parts[2]) ? trim((string) $parts[2]) : '';
    if ($service !== 'hotspot' || $username === '' || !isset($stats[$profileName])) continue;
    if (!in_array($stats[$profileName]['mode'], array('remc', 'ntfc'), true)) continue;
    $startedAt = mikhmonReportRowTimestamp($record);
    $validity = isset($parts[6]) ? mikhmonVoucherValiditySeconds($parts[6]) : 0;
    $dueAt = $startedAt > 0 && $validity > 0 ? $startedAt + $validity : 0;
    $stats[$profileName]['_total'][$username] = true;
    unset($stats[$profileName]['_unused'][$username], $stats[$profileName]['_used'][$username], $stats[$profileName]['_expired'][$username]);
    if ($dueAt > 0 && $dueAt <= $now) $stats[$profileName]['_expired'][$username] = true;
    else $stats[$profileName]['_used'][$username] = true;
  }

  foreach ($stats as $profileName => $row) {
    $stats[$profileName]['total'] = count($row['_total']);
    $stats[$profileName]['unused'] = count($row['_unused']);
    $stats[$profileName]['used'] = count($row['_used']);
    $stats[$profileName]['expired'] = count($row['_expired']);
    unset($stats[$profileName]['_total'], $stats[$profileName]['_unused'], $stats[$profileName]['_used'], $stats[$profileName]['_expired']);
  }
  return $stats;
}
