<?php

require_once dirname(__DIR__) . '/lib/billing_policy.php';

function mikhmonBillingReportInvoiceDate($invoice, $effectiveStatus) {
  if ($effectiveStatus === 'paid') return (int) ($invoice['paid_at'] ?? $invoice['gateway_paid_at'] ?? 0);
  if (in_array($effectiveStatus, array('issued', 'overdue', 'isolated'), true)) return mikhmonBillingDueTimestamp($invoice['due_date'] ?? '');
  return (int) ($invoice['created_at'] ?? 0);
}

function mikhmonBillingReportFinancials($invoice, $serviceFilter = '') {
  $subtotal = mikhmonInvoiceSubtotal($invoice);
  $fullAdminFee = max(0, (float) ($invoice['admin_fee'] ?? 0));
  $fullCollectionFee = mikhmonInvoiceCollectionFee($invoice);
  $storedCommission = max(0, (float) ($invoice['biller_commission'] ?? 0));
  if (!array_key_exists('collection_fee', (array) $invoice) && $fullAdminFee > 0) {
    $fullAdminFee = 0;
  }
  $ratio = 1.0;
  if ($serviceFilter !== '') {
    $filteredSubtotal = 0;
    foreach ((array) ($invoice['services'] ?? array()) as $service) {
      $serviceType = ($service['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
      if ($serviceType === $serviceFilter) $filteredSubtotal += max(0, (float) ($service['amount'] ?? 0));
    }
    if ($filteredSubtotal <= 0) return false;
    $ratio = $subtotal > 0 ? min(1, $filteredSubtotal / $subtotal) : 0;
    $subtotal = $filteredSubtotal;
  }
  $adminFee = $fullAdminFee * $ratio;
  $collectionFee = $fullCollectionFee * $ratio;
  $commissionSource = ($invoice['status'] ?? '') === 'paid'
    ? ($storedCommission > 0 ? $storedCommission : $fullCollectionFee)
    : $fullCollectionFee;
  $commission = $commissionSource * $ratio;
  $amount = $subtotal + $adminFee + $collectionFee;
  return array(
    'subtotal' => $subtotal,
    'admin_fee' => $adminFee,
    'collection_fee' => $collectionFee,
    'commission' => $commission,
    'amount' => $amount,
    'net' => $amount - $commission,
  );
}

function mikhmonBillingReportPrepareInvoice($invoice, $customer, $filters, $now = null) {
  $now = $now === null ? time() : (int) $now;
  $effectiveStatus = mikhmonInvoiceEffectiveStatus($invoice, $now, (int) ($customer['grace_days'] ?? 0));
  $mitraId = (string) ($customer['mitra_id'] ?? '');
  $billerId = (string) (($invoice['paid_by_user_id'] ?? '') ?: ($invoice['collection_biller_user_id'] ?? ''));
  $gateway = strtolower((string) ($invoice['payment_gateway'] ?? ''));
  $method = $gateway !== '' ? $gateway : ($effectiveStatus === 'paid' ? 'manual' : 'unassigned');

  if (($filters['mitra_id'] ?? '') === 'unassigned' && $mitraId !== '') return false;
  if (($filters['mitra_id'] ?? '') !== '' && ($filters['mitra_id'] ?? '') !== 'unassigned' && $mitraId !== (string) $filters['mitra_id']) return false;
  if (($filters['biller_id'] ?? '') !== '' && $billerId !== (string) $filters['biller_id']) return false;
  if (($filters['status'] ?? '') !== '' && $effectiveStatus !== (string) $filters['status']) return false;
  if (($filters['method'] ?? '') !== '' && $method !== (string) $filters['method']) return false;

  $financials = mikhmonBillingReportFinancials($invoice, (string) ($filters['service'] ?? ''));
  if ($financials === false) return false;

  $reportAt = mikhmonBillingReportInvoiceDate($invoice, $effectiveStatus);
  if (!empty($filters['date_from']) && ($reportAt <= 0 || $reportAt < (int) $filters['date_from'])) return false;
  if (!empty($filters['date_to']) && ($reportAt <= 0 || $reportAt > (int) $filters['date_to'])) return false;

  $invoice['_effective_status'] = $effectiveStatus;
  $invoice['_report_at'] = $reportAt;
  $invoice['_mitra_id'] = $mitraId;
  $invoice['_biller_id'] = $billerId;
  $invoice['_payment_method'] = $method;
  foreach ($financials as $field => $value) $invoice['_report_' . $field] = $value;
  return $invoice;
}
