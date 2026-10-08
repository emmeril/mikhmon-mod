<?php

function mikhmonBillingDay() {
  return 5;
}

function mikhmonBillingDueTimestamp($value) {
  $value = trim((string) $value);
  if ($value === '') return 0;
  $timestamp = strtotime($value);
  if (!$timestamp) return 0;
  // A date-only due date remains payable for the complete calendar day.
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return strtotime(date('Y-m-d', $timestamp) . ' 23:59:59');
  return $timestamp;
}

function mikhmonBillingUpcomingDueTimestamp($now = null) {
  $now = $now === null ? time() : (int) $now;
  $candidate = mktime(23, 59, 59, (int) date('n', $now), mikhmonBillingDay(), (int) date('Y', $now));
  if ($candidate < $now) $candidate = mktime(23, 59, 59, (int) date('n', $now) + 1, mikhmonBillingDay(), (int) date('Y', $now));
  return $candidate;
}

function mikhmonBillingNextDueTimestamp($baseTimestamp = null) {
  $baseTimestamp = (int) ($baseTimestamp ?: time());
  return mktime(23, 59, 59, (int) date('n', $baseTimestamp) + 1, mikhmonBillingDay(), (int) date('Y', $baseTimestamp));
}

function mikhmonBillingInitialProration($monthlyAmount, $activeDate) {
  $monthlyAmount = max(0, (float) $monthlyAmount);
  $activeAt = strtotime(trim((string) $activeDate) . ' 00:00:00');
  if (!$activeAt || $monthlyAmount <= 0) return $monthlyAmount;
  $daysInMonth = (int) date('t', $activeAt);
  $billableDays = $daysInMonth - (int) date('j', $activeAt) + 1;
  return round($monthlyAmount * $billableDays / max(1, $daysInMonth), 0);
}

function mikhmonInvoiceIsCollectible($invoice) {
  return in_array((string) ($invoice['status'] ?? ''), array('unpaid', 'issued', 'overdue', 'isolated'), true);
}

function mikhmonInvoiceCollectionFee($invoice) {
  if (array_key_exists('collection_fee', (array) $invoice)) return max(0, (float) $invoice['collection_fee']);
  return max(0, (float) ($invoice['admin_fee'] ?? 0));
}

function mikhmonInvoiceSubtotal($invoice) {
  if (array_key_exists('subtotal', (array) $invoice)) return max(0, (float) $invoice['subtotal']);
  return max(0, (float) ($invoice['amount'] ?? 0) - mikhmonInvoiceCollectionFee($invoice));
}

function mikhmonInvoiceApplyCollectionFee($invoice, $collectionFee, $collector = array()) {
  $invoice = (array) $invoice;
  $invoice['subtotal'] = mikhmonInvoiceSubtotal($invoice);
  $invoice['admin_fee'] = 0;
  $invoice['collection_fee'] = max(0, (float) $collectionFee);
  $invoice['amount'] = $invoice['subtotal'] + $invoice['collection_fee'];
  foreach (array('user_id', 'partner_id', 'name') as $field) {
    if (!array_key_exists($field, (array) $collector) || trim((string) $collector[$field]) === '') continue;
    $invoice['collection_biller_' . $field] = (string) $collector[$field];
  }
  return $invoice;
}

function mikhmonInvoiceEffectiveStatus($invoice, $now = null, $graceDays = 0) {
  $status = strtolower((string) ($invoice['status'] ?? 'draft'));
  if (in_array($status, array('paid', 'void'), true)) return $status;
  if (!empty($invoice['automation']['isolated_at']) || $status === 'isolated') return 'isolated';
  $now = $now === null ? time() : (int) $now;
  $dueAt = mikhmonBillingDueTimestamp($invoice['due_date'] ?? '');
  if ($dueAt > 0 && $now > $dueAt) return 'overdue';
  if ($status === 'unpaid') return 'issued';
  return in_array($status, array('draft', 'issued', 'overdue'), true) ? $status : 'draft';
}

function mikhmonInvoiceStatusLabel($status) {
  $labels = array('draft' => 'Draft', 'issued' => 'Terbit', 'overdue' => 'Jatuh Tempo', 'paid' => 'Lunas', 'isolated' => 'Isolir', 'void' => 'Batal', 'unpaid' => 'Terbit');
  return $labels[$status] ?? ucfirst((string) $status);
}

function mikhmonBillingIsolationTimestamp($dueAt, $graceDays) {
  return (int) $dueAt + 1 + max(0, (int) $graceDays) * 86400;
}
