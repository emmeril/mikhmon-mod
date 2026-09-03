<?php

function mikhmonServiceRouterRowBelongsToCustomer($row, $service, $customer, $ownerTag = '') {
  if (!is_array($row)) return false;
  $comment = trim((string) ($row['comment'] ?? ''));
  $customerName = trim((string) ($customer['name'] ?? ''));
  $ownerTag = trim((string) $ownerTag);
  if ($comment === '' || $customerName === '') return false;

  // Mikhmon appends the mitra tag to comments. Scoped users must carry the
  // current tag; admins may recover a tagged user after a delayed sync.
  if ($ownerTag !== '') {
    if (strpos($comment, $ownerTag) === false) return false;
    $comment = trim(str_replace($ownerTag, '', $comment));
  } else {
    $comment = trim(preg_replace('/\s+\[mitra:[^\]]+\]\s*$/i', '', $comment));
  }

  $expectedComment = $service === 'pppoe' ? $customerName : 'up-' . $customerName;
  return strcasecmp($comment, $expectedComment) === 0;
}
