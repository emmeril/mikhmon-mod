<?php

require_once __DIR__ . '/billing_profile.php';
require_once __DIR__ . '/billing_policy.php';
require_once dirname(__DIR__) . '/report/reportrecord.php';

function mikhmonRevenueOwnerIdFromText($value) {
  return preg_match('/\[mitra:([^\]]+)\]/i', (string) $value, $matches) ? trim((string) $matches[1]) : '';
}

function mikhmonRevenueVoucherProfiles($profiles) {
  $names = array();
  foreach ((array) $profiles as $profile) {
    $name = trim((string) ($profile['name'] ?? ''));
    if ($name !== '' && mikhmonBillingProfileExpiredMode('hotspot', $profile) !== 'none') $names[$name] = true;
  }
  return $names;
}

function mikhmonRevenueTransactions($session, $hotspotProfiles = array()) {
  $transactions = array();
  $customers = array();
  foreach ((array) mikhmonGetCustomers($session) as $customer) $customers[(string) ($customer['id'] ?? '')] = $customer;

  $portalVoucherUsers = array();
  foreach ((array) mikhmonGetInvoices($session) as $invoice) {
    if (($invoice['status'] ?? '') !== 'paid') continue;
    $paidAt = (int) ($invoice['paid_at'] ?? $invoice['gateway_paid_at'] ?? 0);
    if ($paidAt <= 0) continue;
    $kind = ($invoice['kind'] ?? 'monthly') === 'voucher' ? 'voucher' : 'customer';
    $customer = $customers[(string) ($invoice['customer_id'] ?? '')] ?? array();
    $ownerUserId = (string) ($customer['mitra_id'] ?? '');
    $collectorUserId = (string) (($invoice['paid_by_user_id'] ?? '') ?: ($invoice['collection_biller_user_id'] ?? ''));
    $collector = $collectorUserId !== '' ? mikhmonFindUser($collectorUserId) : false;
    if (!$collector || !in_array($collector['role'] ?? '', array('mitra', 'biller'), true)) $collectorUserId = '';
    $gateway = strtolower(trim((string) ($invoice['payment_gateway'] ?? '')));
    $direct = $gateway !== '' || !empty($invoice['gateway_payment_received']) || $collectorUserId === '';
    $subtotal = mikhmonInvoiceSubtotal($invoice);
    $gross = max($subtotal, (float) ($invoice['amount'] ?? $subtotal));
    $commission = max(0, (float) ($invoice['biller_commission'] ?? mikhmonInvoiceCollectionFee($invoice)));
    $voucherUsername = trim((string) ($invoice['voucher_username'] ?? ''));
    if ($kind === 'voucher' && $voucherUsername !== '') $portalVoucherUsers[strtolower($voucherUsername)] = true;
    $transactions[] = array(
      'id' => 'invoice:' . (string) ($invoice['id'] ?? sha1(json_encode($invoice))),
      'date' => $paidAt,
      'source' => $kind,
      'reference' => (string) ($invoice['number'] ?? $invoice['id'] ?? '-'),
      'description' => $kind === 'voucher'
        ? 'Voucher ' . (string) ($invoice['voucher_profile'] ?? '')
        : (string) ($invoice['customer_name'] ?? $customer['name'] ?? 'Pelanggan'),
      'owner_user_id' => $ownerUserId,
      'collector_user_id' => $collectorUserId,
      'payment_method' => $direct ? ($gateway !== '' ? $gateway : 'admin') : 'manual',
      'direct_received' => $direct,
      'revenue' => $subtotal,
      'gross' => $gross,
      'commission' => $commission,
      'commission_settled' => !empty($invoice['commission_settled_at']),
      'invoice_id' => (string) ($invoice['id'] ?? ''),
    );
  }

  $voucherProfiles = mikhmonRevenueVoucherProfiles($hotspotProfiles);
  $sellingPrices = mikhmonReportProfileSellingPrices($hotspotProfiles, array());
  $profileCosts = mikhmonReportProfileCosts($hotspotProfiles, array());
  foreach (mikhmonFilterReportRecords(mikhmonGetReportRecords($session)) as $record) {
    $parts = mikhmonReportParts($record);
    $service = (isset($parts[9]) && strtolower(trim((string) $parts[9])) === 'pppoe')
      || (isset($parts[5]) && strtolower(trim((string) $parts[5])) === 'pppoe') ? 'pppoe' : 'hotspot';
    $profile = trim((string) ($parts[7] ?? ''));
    $comment = (string) ($parts[8] ?? '');
    $looksLikeVoucher = isset($voucherProfiles[$profile]) || preg_match('/(?:^|\s)(?:vc-|voucher\b)/i', $comment);
    if ($service !== 'hotspot' || !$looksLikeVoucher) continue;
    $username = trim((string) ($parts[2] ?? ''));
    if ($username !== '' && isset($portalVoucherUsers[strtolower($username)])) continue;
    $soldAt = mikhmonReportRowTimestamp($record);
    if ($soldAt <= 0) continue;
    $ownerUserId = mikhmonRevenueOwnerIdFromText(implode(' ', array_map('strval', $record)));
    $amount = max(0, mikhmonReportSellingPrice($record, $sellingPrices));
    if ($amount <= 0) continue;
    $transactions[] = array(
      'id' => 'voucher:' . sha1((string) ($record['name'] ?? json_encode($record))),
      'date' => $soldAt,
      'source' => 'voucher',
      'reference' => $username !== '' ? $username : 'Voucher',
      'description' => 'Voucher ' . ($profile !== '' ? $profile : 'Hotspot'),
      'owner_user_id' => $ownerUserId,
      'collector_user_id' => $ownerUserId,
      'payment_method' => $ownerUserId !== '' ? 'manual' : 'admin',
      'direct_received' => $ownerUserId === '',
      'revenue' => $amount,
      'gross' => $amount,
      'commission' => 0.0,
      'commission_settled' => true,
      'cost' => max(0, mikhmonReportCostPrice($record, $profileCosts, $sellingPrices)),
      'invoice_id' => '',
    );
  }

  usort($transactions, function ($left, $right) {
    return (int) ($right['date'] ?? 0) <=> (int) ($left['date'] ?? 0);
  });
  return $transactions;
}

function mikhmonRevenueFilterTransactions($transactions, $filters) {
  return array_values(array_filter((array) $transactions, function ($row) use ($filters) {
    $date = (int) ($row['date'] ?? 0);
    if (!empty($filters['date_from']) && $date < (int) $filters['date_from']) return false;
    if (!empty($filters['date_to']) && $date > (int) $filters['date_to']) return false;
    if (($filters['source'] ?? '') !== '' && (string) ($row['source'] ?? '') !== (string) $filters['source']) return false;
    if (($filters['owner_user_id'] ?? '') === 'unassigned' && (string) ($row['owner_user_id'] ?? '') !== '') return false;
    if (($filters['owner_user_id'] ?? '') !== '' && ($filters['owner_user_id'] ?? '') !== 'unassigned'
      && (string) ($row['owner_user_id'] ?? '') !== (string) $filters['owner_user_id']) return false;
    if (($filters['collector_user_id'] ?? '') !== '' && (string) ($row['collector_user_id'] ?? '') !== (string) $filters['collector_user_id']) return false;
    if (($filters['method'] ?? '') === 'manual' && !empty($row['direct_received'])) return false;
    if (($filters['method'] ?? '') === 'direct' && empty($row['direct_received'])) return false;
    return true;
  }));
}

function mikhmonRevenueTotals($transactions) {
  $totals = array('customer' => 0.0, 'voucher' => 0.0, 'revenue' => 0.0, 'gross' => 0.0, 'commission' => 0.0, 'direct' => 0.0, 'manual' => 0.0);
  foreach ((array) $transactions as $row) {
    $source = ($row['source'] ?? '') === 'voucher' ? 'voucher' : 'customer';
    $revenue = max(0, (float) ($row['revenue'] ?? 0));
    $gross = max(0, (float) ($row['gross'] ?? $revenue));
    $totals[$source] += $revenue;
    $totals['revenue'] += $revenue;
    $totals['gross'] += $gross;
    $totals['commission'] += max(0, (float) ($row['commission'] ?? 0));
    $totals[!empty($row['direct_received']) ? 'direct' : 'manual'] += $gross;
  }
  return $totals;
}

function mikhmonRevenuePartnerSummaries($transactions, $ownerNames = array()) {
  $summaries = array();
  foreach ((array) $transactions as $row) {
    $ownerId = (string) ($row['owner_user_id'] ?? '');
    $key = $ownerId !== '' ? $ownerId : 'unassigned';
    if (!isset($summaries[$key])) $summaries[$key] = array(
      'owner_user_id' => $ownerId,
      'name' => $ownerId !== '' ? ($ownerNames[$ownerId] ?? 'Mitra tidak ditemukan') : 'Belum ditetapkan',
      'customer' => 0.0, 'voucher' => 0.0, 'total' => 0.0,
    );
    $source = ($row['source'] ?? '') === 'voucher' ? 'voucher' : 'customer';
    $amount = max(0, (float) ($row['revenue'] ?? 0));
    $summaries[$key][$source] += $amount;
    $summaries[$key]['total'] += $amount;
  }
  uasort($summaries, function ($left, $right) {
    return $right['total'] <=> $left['total'];
  });
  return array_values($summaries);
}

function mikhmonRevenueDepositTotal($deposits, $collectorUserId = '') {
  $total = 0.0;
  foreach ((array) $deposits as $deposit) {
    if ($collectorUserId !== '' && (string) ($deposit['collector_user_id'] ?? '') !== (string) $collectorUserId) continue;
    $total += max(0, (float) ($deposit['amount'] ?? 0));
  }
  return $total;
}

function mikhmonRevenueCollectorSummary($transactions, $deposits, $collectorUserId) {
  $summary = array('manual' => 0.0, 'direct' => 0.0, 'commission' => 0.0, 'commission_open' => 0.0, 'deposited' => 0.0, 'outstanding' => 0.0);
  foreach ((array) $transactions as $row) {
    if ((string) ($row['collector_user_id'] ?? '') !== (string) $collectorUserId) continue;
    $gross = max(0, (float) ($row['gross'] ?? 0));
    $summary[!empty($row['direct_received']) ? 'direct' : 'manual'] += $gross;
    $commission = max(0, (float) ($row['commission'] ?? 0));
    $summary['commission'] += $commission;
    if ($commission > 0 && empty($row['commission_settled'])) $summary['commission_open'] += $commission;
  }
  $summary['deposited'] = mikhmonRevenueDepositTotal($deposits, (string) $collectorUserId);
  $summary['outstanding'] = max(0, $summary['manual'] - $summary['deposited']);
  return $summary;
}
