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

function mikhmonPartnerWorkflowVoucherCreatorTag($role, $userId = '') {
  $role = strtolower(trim((string) $role));
  $userId = trim((string) $userId);
  if ($role === 'system') return '[creator:system]';
  if ($userId !== '') {
    $user = mikhmonFindUser($userId);
    if ($user && ($user['role'] ?? '') === $role) return '[creator:' . $userId . ']';
  }
  return $role === 'mitra' ? '' : '[creator:admin]';
}

function mikhmonPartnerWorkflowVoucherCreator($session, $comment) {
  $comment = (string) $comment;
  $creatorId = '';
  if (preg_match('/\[creator:([^\]]+)\]/i', $comment, $matches)) $creatorId = trim((string) $matches[1]);
  if ($creatorId === 'system') return 'Sistem';
  if ($creatorId === 'admin') return 'Administrator';
  if ($creatorId !== '') {
    $user = mikhmonFindUser($creatorId);
    if ($user && (($user['session'] ?? '') === '' || (string) $user['session'] === (string) $session)) {
      $name = trim((string) ($user['name'] ?? $user['username'] ?? ''));
      if (($user['role'] ?? '') === 'mitra') {
        $partner = mikhmonFindPartner($creatorId, 'user_id');
        if ($partner && trim((string) ($partner['name'] ?? '')) !== '') $name = trim((string) $partner['name']);
        return 'Mitra' . ($name !== '' ? ' - ' . $name : '');
      }
      if (($user['role'] ?? '') === 'admin') return 'Administrator' . ($name !== '' ? ' - ' . $name : '');
    }
  }
  if (preg_match('/\[mitra:([^\]]+)\]/i', $comment, $matches)) {
    $account = mikhmonPartnerWorkflowFindAccount($session, trim((string) $matches[1]), false);
    if ($account) {
      $name = trim((string) ($account['partner']['name'] ?? $account['user']['name'] ?? ''));
      return 'Mitra' . ($name !== '' ? ' - ' . $name : '');
    }
  }
  return 'Administrator';
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
