<?php

require_once __DIR__ . '/billing_profile.php';
require_once __DIR__ . '/billing_policy.php';
require_once __DIR__ . '/partner_workflow.php';
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
  $sellingPrices = mikhmonReportProfileSellingPrices($hotspotProfiles, array());
  $profileCosts = mikhmonReportProfileCosts($hotspotProfiles, array());
  $reportRecords = mikhmonFilterReportRecords(mikhmonGetReportRecords($session));
  $voucherRecordCosts = array();
  foreach ((array) mikhmonGetCustomers($session) as $customer) $customers[(string) ($customer['id'] ?? '')] = $customer;
  foreach ($reportRecords as $record) {
    $parts = mikhmonReportParts($record);
    $username = strtolower(trim((string) ($parts[2] ?? '')));
    $service = (isset($parts[9]) && strtolower(trim((string) $parts[9])) === 'pppoe')
      || (isset($parts[5]) && strtolower(trim((string) $parts[5])) === 'pppoe') ? 'pppoe' : 'hotspot';
    $comment = (string) ($parts[8] ?? '');
    if ($username === '' || $service !== 'hotspot' || !preg_match('/(?:^|\s)(?:vc-|voucher\b)/i', $comment)) continue;
    $voucherRecordCosts[$username] = max(0, mikhmonReportCostPrice($record, $profileCosts, $sellingPrices));
  }

  $portalVoucherUsers = array();
  foreach ((array) mikhmonGetInvoices($session) as $invoice) {
    if (($invoice['status'] ?? '') !== 'paid') continue;
    $paidAt = (int) ($invoice['paid_at'] ?? $invoice['gateway_paid_at'] ?? 0);
    if ($paidAt <= 0) continue;
    $kind = ($invoice['kind'] ?? 'monthly') === 'voucher' ? 'voucher' : 'customer';
    $customer = $customers[(string) ($invoice['customer_id'] ?? '')] ?? array();
    $ownerUserId = mikhmonPartnerWorkflowInvoiceOwnerId($invoice, $customer);
    $collectorUserId = (string) (($invoice['paid_by_user_id'] ?? '') ?: ($invoice['collection_biller_user_id'] ?? ''));
    $collector = $collectorUserId !== '' ? mikhmonFindUser($collectorUserId) : false;
    if (!$collector || !in_array($collector['role'] ?? '', array('mitra', 'biller'), true)) $collectorUserId = '';
    $gateway = strtolower(trim((string) ($invoice['payment_gateway'] ?? '')));
    $direct = $gateway !== '' || !empty($invoice['gateway_payment_received']) || $collectorUserId === '';
    $subtotal = mikhmonInvoiceSubtotal($invoice);
    $gross = max($subtotal, (float) ($invoice['amount'] ?? $subtotal));
    $adminFee = max(0, (float) ($invoice['admin_fee'] ?? 0));
    $collectionFee = mikhmonInvoiceCollectionFee($invoice);
    if (!array_key_exists('collection_fee', (array) $invoice) && $adminFee > 0) $adminFee = 0.0;
    $commission = max(0, (float) ($invoice['biller_commission'] ?? $collectionFee));
    $partnerCommission = max(0, (float) ($invoice['mitra_commission'] ?? 0));
    if ($kind === 'customer' && !array_key_exists('mitra_commission', (array) $invoice) && $ownerUserId !== '') {
      $legacyPartner = mikhmonFindPartner($ownerUserId, 'user_id');
      if ($legacyPartner && (string) ($legacyPartner['session'] ?? '') === (string) $session) {
        $partnerCommission = max(0, (float) ($legacyPartner['commission'] ?? 0));
      }
    }
    $voucherUsername = trim((string) ($invoice['voucher_username'] ?? ''));
    $profile = $kind === 'voucher' ? trim((string) ($invoice['voucher_profile'] ?? '')) : '';
    $cost = 0.0;
    if ($kind === 'voucher') {
      if (isset($invoice['voucher_cost'])) $cost = max(0, (float) $invoice['voucher_cost']);
      elseif ($voucherUsername !== '' && isset($voucherRecordCosts[strtolower($voucherUsername)])) $cost = $voucherRecordCosts[strtolower($voucherUsername)];
      elseif (isset($profileCosts['hotspot|' . $profile])) $cost = max(0, (float) $profileCosts['hotspot|' . $profile]);
    }
    if ($kind === 'voucher' && $voucherUsername !== '') $portalVoucherUsers[strtolower($voucherUsername)] = true;
    $transactions[] = array(
      'id' => 'invoice:' . (string) ($invoice['id'] ?? sha1(json_encode($invoice))),
      'date' => $paidAt,
      'source' => $kind,
      'reference' => (string) ($invoice['number'] ?? $invoice['id'] ?? '-'),
      'description' => $kind === 'voucher'
        ? 'Voucher ' . (string) ($invoice['voucher_profile'] ?? '')
        : (string) ($invoice['customer_name'] ?? $customer['name'] ?? 'Pelanggan'),
      'profile' => $profile,
      'owner_user_id' => $ownerUserId,
      'collector_user_id' => $collectorUserId,
      'payment_method' => $direct ? ($gateway !== '' ? $gateway : 'admin') : 'manual',
      'direct_received' => $direct,
      'revenue' => $subtotal,
      'gross' => $gross,
      'cost' => $cost,
      'voucher_commission' => $kind === 'voucher' ? max(0, $subtotal - $cost) : 0.0,
      'commission' => $commission,
      'partner_commission' => $partnerCommission,
      'discount' => max(0, (float) ($invoice['discount'] ?? 0)),
      'tax' => max(0, (float) ($invoice['tax'] ?? $invoice['ppn'] ?? 0)),
      'admin_fee' => $adminFee,
      'collection_fee' => $collectionFee,
      'unique_code' => max(0, (float) ($invoice['unique_code'] ?? 0)),
      'commission_settled' => !empty($invoice['commission_settled_at']),
      'invoice_id' => (string) ($invoice['id'] ?? ''),
    );
  }

  $voucherProfiles = mikhmonRevenueVoucherProfiles($hotspotProfiles);
  foreach ($reportRecords as $record) {
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
      'profile' => $profile !== '' ? $profile : 'Hotspot',
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

function mikhmonRevenueVoucherReportRows($transactions, $ownerNames = array()) {
  $rows = array();
  foreach ((array) $transactions as $transaction) {
    if (($transaction['source'] ?? '') !== 'voucher') continue;
    $timestamp = (int) ($transaction['date'] ?? 0);
    if ($timestamp <= 0) continue;
    $ownerId = (string) ($transaction['owner_user_id'] ?? '');
    $profile = trim((string) ($transaction['profile'] ?? ''));
    if ($profile === '') $profile = trim(preg_replace('/^Voucher\s*/i', '', (string) ($transaction['description'] ?? '')));
    if ($profile === '') $profile = 'Hotspot';
    $date = date('Y-m-d', $timestamp);
    $key = $date . '|' . $ownerId . '|' . strtolower($profile);
    if (!isset($rows[$key])) $rows[$key] = array(
      'date' => $date,
      'timestamp' => strtotime($date . ' 00:00:00'),
      'owner_user_id' => $ownerId,
      'owner_name' => $ownerId !== '' ? ($ownerNames[$ownerId] ?? 'Mitra tidak ditemukan') : 'Belum ditetapkan',
      'profile' => $profile,
      'quantity' => 0,
      'cost' => 0.0,
      'commission' => 0.0,
      'revenue' => 0.0,
    );
    $revenue = max(0, (float) ($transaction['revenue'] ?? 0));
    $cost = max(0, (float) ($transaction['cost'] ?? 0));
    $rows[$key]['quantity']++;
    $rows[$key]['cost'] += $cost;
    $rows[$key]['commission'] += max(0, (float) ($transaction['voucher_commission'] ?? ($revenue - $cost)));
    $rows[$key]['revenue'] += $revenue;
  }
  $rows = array_values($rows);
  usort($rows, function ($left, $right) {
    $dateOrder = (int) ($left['timestamp'] ?? 0) <=> (int) ($right['timestamp'] ?? 0);
    if ($dateOrder !== 0) return $dateOrder;
    $ownerOrder = strcasecmp((string) ($left['owner_name'] ?? ''), (string) ($right['owner_name'] ?? ''));
    return $ownerOrder !== 0 ? $ownerOrder : strcasecmp((string) ($left['profile'] ?? ''), (string) ($right['profile'] ?? ''));
  });
  return $rows;
}

function mikhmonRevenuePaidInvoiceReportRows($transactions, $ownerNames = array()) {
  $months = array(1=>'JANUARI','FEBRUARI','MARET','APRIL','MEI','JUNI','JULI','AGUSTUS','SEPTEMBER','OKTOBER','NOVEMBER','DESEMBER');
  $rows = array();
  foreach ((array) $transactions as $transaction) {
    if (($transaction['source'] ?? '') !== 'customer') continue;
    $timestamp = (int) ($transaction['date'] ?? 0);
    if ($timestamp <= 0) continue;
    $ownerId = (string) ($transaction['owner_user_id'] ?? '');
    $monthKey = date('Y-m', $timestamp);
    $key = $monthKey . '|' . $ownerId;
    if (!isset($rows[$key])) $rows[$key] = array(
      'month_key' => $monthKey,
      'timestamp' => strtotime($monthKey . '-01 00:00:00'),
      'month' => $months[(int) date('n', $timestamp)],
      'year' => (int) date('Y', $timestamp),
      'owner_user_id' => $ownerId,
      'owner_name' => $ownerId !== '' ? ($ownerNames[$ownerId] ?? 'Mitra tidak ditemukan') : 'Belum ditetapkan',
      'quantity' => 0,
      'subtotal' => 0.0,
      'discount' => 0.0,
      'tax' => 0.0,
      'admin' => 0.0,
      'unique_code' => 0.0,
      'total' => 0.0,
      'commission' => 0.0,
    );
    $rows[$key]['quantity']++;
    $rows[$key]['subtotal'] += max(0, (float) ($transaction['revenue'] ?? 0));
    $rows[$key]['discount'] += max(0, (float) ($transaction['discount'] ?? 0));
    $rows[$key]['tax'] += max(0, (float) ($transaction['tax'] ?? 0));
    $rows[$key]['admin'] += max(0, (float) ($transaction['admin_fee'] ?? 0)) + max(0, (float) ($transaction['collection_fee'] ?? 0));
    $rows[$key]['unique_code'] += max(0, (float) ($transaction['unique_code'] ?? 0));
    $rows[$key]['total'] += max(0, (float) ($transaction['gross'] ?? $transaction['revenue'] ?? 0));
    $rows[$key]['commission'] += max(0, (float) ($transaction['partner_commission'] ?? 0));
  }
  $rows = array_values($rows);
  usort($rows, function ($left, $right) {
    $dateOrder = (int) ($left['timestamp'] ?? 0) <=> (int) ($right['timestamp'] ?? 0);
    return $dateOrder !== 0 ? $dateOrder : strcasecmp((string) ($left['owner_name'] ?? ''), (string) ($right['owner_name'] ?? ''));
  });
  return $rows;
}

function mikhmonRevenueReportSectionTotals($rows, $fields) {
  $totals = array('quantity' => 0);
  foreach ((array) $fields as $field) $totals[$field] = 0.0;
  foreach ((array) $rows as $row) {
    $totals['quantity'] += max(0, (int) ($row['quantity'] ?? 0));
    foreach ((array) $fields as $field) $totals[$field] += max(0, (float) ($row[$field] ?? 0));
  }
  return $totals;
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
