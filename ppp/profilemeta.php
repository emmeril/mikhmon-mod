<?php

function pppProfileMetaDecode($value) {
  $meta = array(
    'price' => '',
    'selling-price' => '',
    'expmode' => 'none',
    'validity' => '',
    'comment' => (string) $value,
  );

  if (preg_match('/^\[MIKHMON-PPPOE price=([0-9.]*) selling=([0-9.]*) expmode=([a-z0-9-]+) validity=([^\]]*)\](?: (.*))?$/s', (string) $value, $matches)) {
    $meta['price'] = $matches[1] === '0' ? '' : $matches[1];
    $meta['selling-price'] = $matches[2] === '0' ? '' : $matches[2];
    $meta['expmode'] = $matches[3];
    $meta['validity'] = $matches[4];
    $meta['comment'] = isset($matches[5]) ? $matches[5] : '';
  } elseif (preg_match('/^\[MIKHMON-PPPOE price=([0-9.]*) selling=([0-9.]*)\](?: (.*))?$/s', (string) $value, $matches)) {
    $meta['price'] = $matches[1] === '0' ? '' : $matches[1];
    $meta['selling-price'] = $matches[2] === '0' ? '' : $matches[2];
    $meta['comment'] = isset($matches[3]) ? $matches[3] : '';
  }

  return $meta;
}

function pppProfileMetaEncode($price, $sellingPrice, $comment, $expmode = 'none', $validity = '') {
  $price = is_numeric($price) && $price >= 0 ? (string) $price : '0';
  $sellingPrice = is_numeric($sellingPrice) && $sellingPrice >= 0 ? (string) $sellingPrice : '0';
  $comment = trim((string) $comment);
  $expmode = in_array($expmode, array('none', 'remove', 'disable')) ? $expmode : 'none';
  $validity = trim((string) $validity);
  if (!preg_match('/^[0-9]+[wdhms](?:[0-9]+[wdhms])*$/i', $validity)) {
    $validity = '';
  }

  return '[MIKHMON-PPPOE price=' . $price . ' selling=' . $sellingPrice . ' expmode=' . $expmode . ' validity=' . $validity . ']' . ($comment !== '' ? ' ' . $comment : '');
}

function pppProfileOnUpScript($expmode, $validity) {
  if ($expmode === 'none' || $validity === '') {
    return '';
  }

  $action = $expmode === 'remove'
    ? '/ppp secret remove [find where name=$u]'
    : '/ppp secret set [find where name=$u] disabled=yes';
  $quote = chr(92) . '"';

  return ':local u $user; :local n ("mikhmon-pppoe-" . $u); :if ([:len [/system scheduler find where name=$n]] = 0) do={ /system scheduler add name=$n interval=' . $validity . ' on-event=":local u ' . $quote . '$u' . $quote . '; :local n (' . $quote . 'mikhmon-pppoe-' . $quote . ' . $u); /ppp active remove [find where name=$u]; ' . $action . '; /system scheduler remove [find where name=$n];"; };';
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
