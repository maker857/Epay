<?php

$root = dirname(__DIR__);
$checks = [
	'submit2.php' => ['trade_no=\'{$trade_no}\''],
	'cashier.php' => ['trade_no=\'{$trade_no}\''],
	'getshop.php' => ['trade_no=\'{$trade_no}\''],
	'api.php' => ['trade_no=\'{$trade_no}\'', 'out_trade_no=\'{$out_trade_no}\''],
	'includes/lib/api/Pay.php' => ['trade_no=\'{$trade_no}\'', 'out_trade_no=\'{$out_trade_no}\''],
	'admin/ajax_user.php' => [
		'{$_POST[\'column\']}',
		'{$_POST[\'value\']}',
		'{$_POST[\'kw\']}',
		'str_replace(\'_\', \' \', $_POST[\'order\'])',
	],
	'user/ajax2.php' => [
		'daddslashes($_POST[\'kw\'])',
		'daddslashes($_POST[\'starttime\'])',
		'daddslashes($_POST[\'endtime\'])',
	],
	'admin/ajax_order.php' => ['{$_POST[\'column\']}', '{$_POST[\'value\']}', '{$_POST[\'starttime\']}', '{$_POST[\'endtime\']}', '{$_POST[\'applyid\']}'],
	'admin/ajax_transfer.php' => ['{$_POST[\'column\']}', '{$_POST[\'value\']}', '{$_POST[\'starttime\']}', '{$_POST[\'endtime\']}'],
	'admin/ajax_profitsharing.php' => ['{$_POST[\'column\']}', '{$_POST[\'value\']}', '{$_POST[\'starttime\']}', '{$_POST[\'endtime\']}'],
	'admin/download.php' => ['{$_GET[\'column\']}', '{$_GET[\'value\']}', '{$_GET[\'starttime\']}', '{$_GET[\'endtime\']}'],
	'user/download.php' => ['\'{$kw}\'', '\'{$starttime}', '\'{$endtime}'],
	'includes/lib/api/Merchant.php' => ['uid=\'{$pid}\'', 'A.status=\'{$status}\''],
];

$failures = [];
foreach ($checks as $relativePath => $forbiddenFragments) {
	$contents = file_get_contents($root.'/'.$relativePath);
	foreach ($forbiddenFragments as $fragment) {
		if (strpos($contents, $fragment) !== false) {
			$failures[] = $relativePath.' still contains unsafe SQL fragment: '.$fragment;
		}
	}
}

if ($failures) {
	throw new RuntimeException("SQL injection regression checks failed:\n- ".implode("\n- ", $failures));
}

echo "SQL injection regression checks passed.\n";
