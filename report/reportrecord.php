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
