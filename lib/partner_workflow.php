<?php

require_once dirname(__DIR__) . '/include/database.php';

function mikhmonPartnerWorkflowFindAccount($session, $userId, $activeOnly = true) {
  $userId = trim((string) $userId);
  if ($userId === '') return false;
  $user = mikhmonFindUser($userId);
  $partner = mikhmonFindPartner($userId, 'user_id');
  if (!$user || !$partner || ($user['role'] ?? '') !== 'mitra') return false;
  if ((string) ($user['session'] ?? '') !== (string) $session || (string) ($partner['session'] ?? '') !== (string) $session) return false;
  if ($activeOnly && (empty($user['active']) || empty($partner['active']))) return false;
  return array('user' => $user, 'partner' => $partner);
}

function mikhmonPartnerWorkflowVoucherOwner($session, $requestedUserId, $role, $currentUserId) {
  $role = strtolower(trim((string) $role));
  $requestedUserId = trim((string) $requestedUserId);
  $currentUserId = trim((string) $currentUserId);
  $ownerId = $role === 'mitra' ? $currentUserId : $requestedUserId;

  if ($role !== 'admin' && $role !== 'mitra') {
    return array('valid' => false, 'user_id' => '', 'tag' => '', 'message' => 'Peran ini tidak dapat menetapkan pemilik voucher.');
  }
  if ($ownerId === '' && $role === 'admin') return array('valid' => true, 'user_id' => '', 'tag' => '', 'message' => '');

  $account = mikhmonPartnerWorkflowFindAccount($session, $ownerId, true);
  if (!$account) return array('valid' => false, 'user_id' => '', 'tag' => '', 'message' => 'Mitra voucher tidak valid atau sudah nonaktif.');
  return array(
    'valid' => true,
    'user_id' => $ownerId,
    'tag' => '[mitra:' . $ownerId . ']',
    'name' => (string) ($account['partner']['name'] ?? $account['user']['name'] ?? 'Mitra'),
    'message' => '',
  );
}

function mikhmonPartnerWorkflowApplyInvoiceSnapshot($session, $invoice, $customer) {
  $invoice = (array) $invoice;
  $customer = (array) $customer;
  $ownerId = trim((string) ($customer['mitra_id'] ?? ''));
  $invoice['mitra_user_id'] = $ownerId;
  $invoice['mitra_partner_id'] = '';
  $invoice['mitra_name'] = '';
  $invoice['mitra_commission'] = 0.0;
  if ($ownerId === '') return $invoice;

  $account = mikhmonPartnerWorkflowFindAccount($session, $ownerId, false);
  if (!$account) return $invoice;
  $partner = $account['partner'];
  $invoice['mitra_partner_id'] = (string) ($partner['id'] ?? '');
  $invoice['mitra_name'] = (string) ($partner['name'] ?? $account['user']['name'] ?? 'Mitra');
  if (($invoice['kind'] ?? 'monthly') !== 'voucher') {
    $invoice['mitra_commission'] = max(0, (float) ($partner['commission'] ?? 0));
  }
  return $invoice;
}

function mikhmonPartnerWorkflowInvoiceOwnerId($invoice, $customer = array()) {
  if (array_key_exists('mitra_user_id', (array) $invoice)) return trim((string) ($invoice['mitra_user_id'] ?? ''));
  return trim((string) ($customer['mitra_id'] ?? ''));
}
