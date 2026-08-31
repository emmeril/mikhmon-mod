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
