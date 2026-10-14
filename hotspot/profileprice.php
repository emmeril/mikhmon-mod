<?php

if (!function_exists('mikhmonNormalizeProfilePrice')) {
  function mikhmonNormalizeProfilePrice($value) {
    $value = trim((string) $value);

    if ($value === '' || !is_numeric($value) || (float) $value < 0) {
      return '0';
    }

    return $value;
  }
}

if (!function_exists('mikhmonProfileReportPrice')) {
  function mikhmonProfileReportPrice($price, $sellingPrice) {
    $price = mikhmonNormalizeProfilePrice($price);
    $sellingPrice = mikhmonNormalizeProfilePrice($sellingPrice);

    return (float) $sellingPrice > 0 ? $sellingPrice : $price;
  }
}

if (!function_exists('mikhmonHotspotProfilePrices')) {
  function mikhmonHotspotProfilePrices($profile) {
    $parts = explode(',', (string) (is_array($profile) ? ($profile['on-login'] ?? '') : $profile));
    return array(
      'hpp' => isset($parts[2]) && is_numeric(trim($parts[2])) ? max(0, (float) $parts[2]) : 0.0,
      'harga' => isset($parts[4]) && is_numeric(trim($parts[4])) ? max(0, (float) $parts[4]) : 0.0,
      'commission' => isset($parts[7]) && is_numeric(trim($parts[7])) ? max(0, (float) $parts[7]) : 0.0,
      'validity' => isset($parts[3]) ? trim((string) $parts[3]) : '',
    );
  }
}

if (!function_exists('mikhmonHotspotProfileStoreCommission')) {
  function mikhmonHotspotProfileStoreCommission($onLogin, $commission) {
    $onLogin = (string) $onLogin;
    $commission = mikhmonNormalizeProfilePrice($commission);
    $end = strpos($onLogin, '")');
    if ($end === false) return $onLogin;
    return substr($onLogin, 0, $end) . $commission . ',"' . substr($onLogin, $end + 1);
  }
}
