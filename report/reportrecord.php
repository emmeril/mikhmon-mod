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
