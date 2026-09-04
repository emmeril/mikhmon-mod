<?php

if (!function_exists('mikhmonIsReportRecord')) {
	function mikhmonIsReportRecord($row)
	{
		if (!is_array($row) || !isset($row['name'])) {
			return false;
		}

		$parts = explode('-|-', $row['name']);
		return count($parts) >= 9
			&& trim($parts[0]) !== ''
			&& trim($parts[1]) !== ''
			&& trim($parts[2]) !== ''
			&& is_numeric(trim($parts[3]))
			&& trim($parts[7]) !== '';
	}
}

if (!function_exists('mikhmonFilterReportRecords')) {
	function mikhmonFilterReportRecords($rows)
	{
		if (!is_array($rows)) {
			return array();
		}

		return array_values(array_filter($rows, 'mikhmonIsReportRecord'));
	}
}

if (!function_exists('mikhmonReportBillingInvoiceServices')) {
	function mikhmonReportBillingInvoiceServices($invoice)
	{
		if (!empty($invoice['services']) && is_array($invoice['services'])) return $invoice['services'];
		if (!empty($invoice['username'])) return array(array(
			'service' => ($invoice['service'] ?? 'hotspot') === 'pppoe' ? 'pppoe' : 'hotspot',
			'username' => (string) $invoice['username'], 'profile' => (string) ($invoice['profile'] ?? ''),
			'amount' => (float) ($invoice['amount'] ?? 0),
		));
		return array();
	}
}

if (!function_exists('mikhmonReportBillingPaidAt')) {
	function mikhmonReportBillingPaidAt($invoice)
	{
		$paidAt = isset($invoice['paid_at']) ? (int) $invoice['paid_at'] : 0;
		return (($invoice['status'] ?? '') === 'paid' && $paidAt > 0) ? $paidAt : 0;
	}
}

if (!function_exists('mikhmonReportBillingPeriodMatch')) {
	function mikhmonReportBillingPeriodMatch($paidAt, $idhr = '', $idbl = '')
	{
		if ($paidAt <= 0) return false;
		$idhr = trim((string) $idhr);
		$idbl = trim((string) $idbl);
		if ($idhr !== '') return strtolower(date('M/d/Y', $paidAt)) === strtolower($idhr);
		if ($idbl !== '') return strtolower(date('MY', $paidAt)) === strtolower($idbl);
		return true;
	}
}

if (!function_exists('mikhmonReportBillingRows')) {
	function mikhmonReportBillingRows($session, $idhr = '', $idbl = '')
	{
		$rows = array();
		if (!function_exists('mikhmonGetInvoices')) return $rows;
		foreach ((array) mikhmonGetInvoices($session) as $invoice) {
			$paidAt = mikhmonReportBillingPaidAt($invoice);
			if (!$paidAt || !mikhmonReportBillingPeriodMatch($paidAt, $idhr, $idbl)) continue;
			$customer = function_exists('mikhmonFindCustomer') ? mikhmonFindCustomer($session, $invoice['customer_id'] ?? '') : array();
			$ownerTag = !empty($customer['mitra_id']) ? '[mitra:' . (string) $customer['mitra_id'] . ']' : '';
			$date = strtolower(date('M/d/Y', $paidAt));
			$time = date('H:i:s', $paidAt);
			$invoiceNumber = trim((string) ($invoice['number'] ?? $invoice['id'] ?? 'Billing'));
			$gateway = strtoupper(trim((string) ($invoice['payment_gateway'] ?? '')));
			$sourceLabel = 'Billing / ' . $invoiceNumber . ($gateway !== '' ? ' / ' . $gateway : '');
			foreach (mikhmonReportBillingInvoiceServices($invoice) as $service) {
				$username = trim((string) ($service['username'] ?? ''));
				if ($username === '') continue;
				$type = ($service['service'] ?? '') === 'pppoe' ? 'PPPoE' : 'Hotspot';
				$profile = trim((string) ($service['profile'] ?? ''));
				$amount = (float) ($service['amount'] ?? 0);
				$parts = array($date, $time, $username, $amount, '', $type, '', $profile, $sourceLabel, strtolower($type), '');
				$rows[] = array(
					'name' => implode('-|-', $parts), 'source' => $date, 'owner' => strtolower(date('MY', $paidAt)),
					'billing_invoice_id' => (string) ($invoice['id'] ?? ''), 'billing' => true,
					'billing_payment_gateway' => strtolower($gateway),
					'billing_owner_tag' => $ownerTag,
				);
			}
		}
		return $rows;
	}
}

if (!function_exists('mikhmonReportServiceKey')) {
	function mikhmonReportServiceKey($row)
	{
		$parts = mikhmonReportParts($row);
		$username = isset($parts[2]) ? strtolower(trim($parts[2])) : '';
		if ($username === '') return '';
		$type = (isset($parts[9]) && strtolower(trim($parts[9])) === 'pppoe')
			|| (isset($parts[5]) && strtolower(trim($parts[5])) === 'pppoe') ? 'pppoe' : 'hotspot';
		return $type . '|' . $username;
	}
}

if (!function_exists('mikhmonReportBillingServiceStarts')) {
	function mikhmonReportBillingServiceStarts($session)
	{
		$starts = array();
		if (!function_exists('mikhmonGetInvoices')) return $starts;
		foreach ((array) mikhmonGetInvoices($session) as $invoice) {
			$paidAt = mikhmonReportBillingPaidAt($invoice);
			if (!$paidAt) continue;
			foreach (mikhmonReportBillingInvoiceServices($invoice) as $service) {
				$type = ($service['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
				$username = strtolower(trim((string) ($service['username'] ?? '')));
				if ($username === '') continue;
				$key = $type . '|' . $username;
				if (!isset($starts[$key]) || $paidAt < $starts[$key]) $starts[$key] = $paidAt;
			}
		}
		return $starts;
	}
}

if (!function_exists('mikhmonReportBillingServiceKeys')) {
	function mikhmonReportBillingServiceKeys($session)
	{
		$keys = array();
		$addServices = function ($services) use (&$keys) {
			foreach ((array) $services as $service) {
				$type = ($service['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
				$username = strtolower(trim((string) ($service['username'] ?? '')));
				if ($username !== '') $keys[$type . '|' . $username] = true;
			}
		};
		if (function_exists('mikhmonGetInvoices')) {
			foreach ((array) mikhmonGetInvoices($session) as $invoice) $addServices(mikhmonReportBillingInvoiceServices($invoice));
		}
		if (function_exists('mikhmonGetCustomers')) {
			foreach ((array) mikhmonGetCustomers($session) as $customer) {
				$addServices(function_exists('mikhmonCustomerServices') ? mikhmonCustomerServices($customer) : array());
			}
		}
		return $keys;
	}
}

if (!function_exists('mikhmonReportRowTimestamp')) {
	function mikhmonReportRowTimestamp($row)
	{
		$parts = mikhmonReportParts($row);
		if (empty($parts[0])) return 0;
		$date = (string) $parts[0];
		if (preg_match('/^([a-z]{3})\/(\d{1,2})\/(\d{4})$/i', $date, $matches)) {
			$date = $matches[3] . '-' . date('m', strtotime($matches[1] . ' 1')) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
		}
		return strtotime($date . ' ' . ($parts[1] ?? '00:00:00')) ?: 0;
	}
}

if (!function_exists('mikhmonReportMergeBillingRows')) {
	function mikhmonReportMergeBillingRows($session, $rows, $idhr = '', $idbl = '')
	{
		$billingRows = mikhmonReportBillingRows($session, $idhr, $idbl);
		// A paid Billing invoice is the authoritative transaction for that customer
		// from its first payment onward; omit profile-login records to prevent duplicates.
		$billingStarts = mikhmonReportBillingServiceStarts($session);
		// Billing services are not sales while their first invoice is unpaid (or has
		// not been created yet). This is especially important for PPPoE profiles,
		// whose on-up script records every login regardless of payment status.
		$billingServices = mikhmonReportBillingServiceKeys($session);
		$filtered = array();
		foreach ((array) $rows as $row) {
			$key = mikhmonReportServiceKey($row);
			$rowAt = mikhmonReportRowTimestamp($row);
			if ($key !== '' && isset($billingServices[$key]) && !isset($billingStarts[$key])) continue;
			if ($key !== '' && $rowAt > 0 && isset($billingStarts[$key]) && $rowAt >= $billingStarts[$key]) continue;
			$filtered[] = $row;
		}
		return array_merge($filtered, $billingRows);
	}
}

if (!function_exists('mikhmonReportParts')) {
	function mikhmonReportParts($row)
	{
		return explode('-|-', is_array($row) && isset($row['name']) ? $row['name'] : '');
	}
}

if (!function_exists('mikhmonReportSellingPrice')) {
	function mikhmonReportSellingPrice($row, $profileSellingPrices = array())
	{
		$parts = mikhmonReportParts($row);
		$service = (isset($parts[9]) && strtolower(trim($parts[9])) === 'pppoe')
			|| (isset($parts[5]) && strtolower(trim($parts[5])) === 'pppoe') ? 'pppoe' : 'hotspot';
		$profile = isset($parts[7]) ? trim($parts[7]) : '';
		$key = $service . '|' . $profile;

		// Legacy Hotspot records stored HPP in field 3. Use the profile's
		// Selling Price until that transaction format is updated.
		if ($service === 'hotspot' && !isset($parts[9]) && isset($profileSellingPrices[$key])) {
			return (float) $profileSellingPrices[$key];
		}

		return isset($parts[3]) && is_numeric(trim($parts[3])) ? (float) $parts[3] : 0;
	}
}

if (!function_exists('mikhmonReportCostPrice')) {
	function mikhmonReportCostPrice($row, $profileCosts = array(), $profileSellingPrices = array())
	{
		$parts = mikhmonReportParts($row);

		if (isset($parts[10]) && is_numeric(trim($parts[10]))) {
			return (float) $parts[10];
		}

		$service = (isset($parts[9]) && strtolower(trim($parts[9])) === 'pppoe')
			|| (isset($parts[5]) && strtolower(trim($parts[5])) === 'pppoe') ? 'pppoe' : 'hotspot';
		$profile = isset($parts[7]) ? trim($parts[7]) : '';
		$key = $service . '|' . $profile;

		return isset($profileCosts[$key]) ? (float) $profileCosts[$key] : mikhmonReportSellingPrice($row, $profileSellingPrices);
	}
}

if (!function_exists('mikhmonReportNetProfit')) {
	function mikhmonReportNetProfit($row, $profileCosts = array(), $profileSellingPrices = array())
	{
		return mikhmonReportSellingPrice($row, $profileSellingPrices) - mikhmonReportCostPrice($row, $profileCosts, $profileSellingPrices);
	}
}

if (!function_exists('mikhmonReportProfileCosts')) {
	function mikhmonReportProfileCosts($hotspotProfiles, $pppProfiles)
	{
		$costs = array();

		foreach (is_array($hotspotProfiles) ? $hotspotProfiles : array() as $profile) {
			$parts = explode(',', isset($profile['on-login']) ? $profile['on-login'] : '');
			$name = isset($profile['name']) ? trim($profile['name']) : '';
			$price = isset($parts[2]) ? trim($parts[2]) : '';
			if ($name !== '' && is_numeric($price)) {
				$costs['hotspot|' . $name] = (float) $price;
			}
		}

		foreach (is_array($pppProfiles) ? $pppProfiles : array() as $profile) {
			$name = isset($profile['name']) ? trim($profile['name']) : '';
			$comment = isset($profile['comment']) ? $profile['comment'] : '';
			if ($name !== '' && preg_match('/^\[MIKHMON-PPPOE price=([0-9.]*) selling=/', $comment, $matches)) {
				$costs['pppoe|' . $name] = (float) $matches[1];
			}
		}

		return $costs;
	}
}

if (!function_exists('mikhmonReportProfileSellingPrices')) {
	function mikhmonReportProfileSellingPrices($hotspotProfiles, $pppProfiles)
	{
		$sellingPrices = array();

		foreach (is_array($hotspotProfiles) ? $hotspotProfiles : array() as $profile) {
			$parts = explode(',', isset($profile['on-login']) ? $profile['on-login'] : '');
			$name = isset($profile['name']) ? trim($profile['name']) : '';
			$sellingPrice = isset($parts[4]) ? trim($parts[4]) : '';
			if ($name !== '' && is_numeric($sellingPrice) && (float) $sellingPrice > 0) {
				$sellingPrices['hotspot|' . $name] = (float) $sellingPrice;
			}
		}

		foreach (is_array($pppProfiles) ? $pppProfiles : array() as $profile) {
			$name = isset($profile['name']) ? trim($profile['name']) : '';
			$comment = isset($profile['comment']) ? $profile['comment'] : '';
			if ($name !== '' && preg_match('/^\[MIKHMON-PPPOE price=[0-9.]* selling=([0-9.]*)/', $comment, $matches) && (float) $matches[1] > 0) {
				$sellingPrices['pppoe|' . $name] = (float) $matches[1];
			}
		}

		return $sellingPrices;
	}
}
