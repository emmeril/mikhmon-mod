<?php

if (!function_exists('mikhmonBillingHotspotProfileExpiredMode')) {
  function mikhmonBillingHotspotProfileExpiredMode($profile) {
    $onLogin = trim((string) ($profile['on-login'] ?? ''));
    if ($onLogin === '') return 'none';
    $parts = explode(',', $onLogin);
    $mode = isset($parts[1]) ? strtolower(trim($parts[1])) : '';
    if ($mode === '' || $mode === '0' || $mode === 'none') return 'none';
    return $mode;
  }
}
if (!function_exists('mikhmonBillingPppoeProfileExpiredMode')) {
  function mikhmonBillingPppoeProfileExpiredMode($profile) {
    $comment = (string) ($profile['comment'] ?? '');
    if (function_exists('pppProfileMetaDecode')) {
      $meta = pppProfileMetaDecode($comment);
      return strtolower(trim((string) ($meta['expmode'] ?? 'none'))) ?: 'none';
    }
    if (preg_match('/expmode=([a-z0-9-]+)/i', $comment, $matches)) return strtolower(trim($matches[1]));
    return 'none';
  }
}

if (!function_exists('mikhmonBillingProfileExpiredMode')) {
  function mikhmonBillingProfileExpiredMode($service, $profile) {
    return strtolower((string) $service) === 'pppoe'
      ? mikhmonBillingPppoeProfileExpiredMode($profile)
      : mikhmonBillingHotspotProfileExpiredMode($profile);
  }
}

if (!function_exists('mikhmonBillingProfileCanManage')) {
  function mikhmonBillingProfileCanManage($service, $profile) {
    return mikhmonBillingProfileExpiredMode($service, $profile) === 'none';
  }
}
