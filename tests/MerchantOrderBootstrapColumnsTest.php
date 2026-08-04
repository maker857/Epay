<?php

$source = file_get_contents(__DIR__.'/../includes/lib/api/Pay.php');
if ($source === false) {
	throw new RuntimeException('Unable to read merchant payment API implementation.');
}

$matches = [];
preg_match_all('/INSERT INTO `pre_order` \(([^)]+)\) VALUES \(([^)]+)\)/', $source, $matches, PREG_SET_ORDER);

$merchantInsertCount = 0;
foreach ($matches as $match) {
	$columns = str_replace('`', '', $match[1]);
	if (strpos($columns, 'cert_info') === false) continue;
	$merchantInsertCount++;
	if (strpos($columns, 'type') === false || strpos($columns, 'channel') === false) {
		throw new RuntimeException('Merchant order creation must initialize the non-null type and channel columns.');
	}
}

if ($merchantInsertCount !== 2) {
	throw new RuntimeException('Expected both merchant order creation endpoints to be covered.');
}

echo "Merchant order bootstrap column tests passed.\n";
