<?php

function pppProfileMetaDecode($value) {
  $meta = array(
    'price' => '',
    'selling-price' => '',
    'comment' => (string) $value,
  );

  if (preg_match('/^\[MIKHMON-PPPOE price=([0-9.]*) selling=([0-9.]*)\](?: (.*))?$/s', (string) $value, $matches)) {
    $meta['price'] = $matches[1] === '0' ? '' : $matches[1];
    $meta['selling-price'] = $matches[2] === '0' ? '' : $matches[2];
    $meta['comment'] = isset($matches[3]) ? $matches[3] : '';
  }

  return $meta;
}

function pppProfileMetaEncode($price, $sellingPrice, $comment) {
  $price = is_numeric($price) && $price >= 0 ? (string) $price : '0';
  $sellingPrice = is_numeric($sellingPrice) && $sellingPrice >= 0 ? (string) $sellingPrice : '0';
  $comment = trim((string) $comment);

  return '[MIKHMON-PPPOE price=' . $price . ' selling=' . $sellingPrice . ']' . ($comment !== '' ? ' ' . $comment : '');
}

function pppProfilePriceFormat($value, $currency, $indoCurrencies) {
  if ($value === '' || !is_numeric($value)) {
    return '';
  }

  if (in_array($currency, $indoCurrencies)) {
    return number_format((float) $value, 0, ',', '.');
  }

  return number_format((float) $value, 2, '.', ',');
}

