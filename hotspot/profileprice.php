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
