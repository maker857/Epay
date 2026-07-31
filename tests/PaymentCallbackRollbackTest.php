<?php

require_once __DIR__.'/../includes/common.php';

use lib\Payment;

$suffix = substr(bin2hex(random_bytes(8)), 0, 5);
$tradeNo = date('YmdHis').$suffix;
$missingUid = 2147483000;
$order = [
	'trade_no' => $tradeNo,
	'out_trade_no' => 'callback-rollback-'.$suffix,
	'api_trade_no' => null,
	'uid' => $missingUid,
	'tid' => 2,
	'type' => 1,
	'channel' => 0,
	'name' => 'callback rollback test',
	'money' => '10.00',
	'realmoney' => '10.00',
	'getmoney' => '10.00',
	'param' => json_encode(['uid' => $missingUid]),
	'addtime' => date('Y-m-d H:i:s'),
	'status' => 0,
	'profits' => 0,
	'settle' => 0,
	'combine' => 0,
];

try {
	if (!$DB->insert('order', $order)) throw new RuntimeException('Unable to create rollback test order: '.$DB->error());
	$failed = false;
	try {
		Payment::processOrder(true, $order, 'rollback-api-'.$suffix);
	} catch (Throwable $e) {
		$failed = true;
	}
	if (!$failed) throw new RuntimeException('A failed balance update must propagate an exception.');
	$status = $DB->findColumn('order', 'status', ['trade_no' => $tradeNo]);
	$recordCount = $DB->count('record', ['trade_no' => $tradeNo]);
	if ((int)$status !== 0) throw new RuntimeException('Failed callback must roll the order status back to 0.');
	if ((int)$recordCount !== 0) throw new RuntimeException('Failed callback must not leave a balance record.');
	echo "Payment callback rollback test passed.\n";
} finally {
	$DB->delete('record', ['trade_no' => $tradeNo]);
	$DB->delete('order', ['trade_no' => $tradeNo]);
}
