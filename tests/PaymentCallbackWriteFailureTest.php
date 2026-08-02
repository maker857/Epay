<?php

require_once __DIR__.'/../includes/common.php';

use lib\Payment;

function assertCallbackWriteState($condition, $message)
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$suffix = bin2hex(random_bytes(5));
$tradeNo = date('YmdHis').substr($suffix, 0, 5);
$uid = null;

try {
	$uid = $DB->insert('user', [
		'key' => substr(hash('sha256', 'callback-write-'.$suffix), 0, 32),
		'money' => '0.00',
		'addtime' => 'NOW()',
		'pay' => 1,
		'settle' => 1,
		'status' => 1,
	]);
	assertCallbackWriteState($uid !== false, 'Unable to create callback write test user: '.$DB->error());

	$order = [
		'trade_no' => $tradeNo,
		'out_trade_no' => 'callback-write-'.$suffix,
		'api_trade_no' => null,
		'uid' => (int)$uid,
		'tid' => 2,
		'type' => 1,
		'channel' => 0,
		'name' => 'callback write failure test',
		'money' => '10.00',
		'realmoney' => '10.00',
		'getmoney' => '10.00',
		'param' => json_encode(['uid'=>(int)$uid]),
		'addtime' => date('Y-m-d H:i:s'),
		'status' => 0,
		'profits' => 0,
		'settle' => 0,
		'combine' => 0,
	];
	assertCallbackWriteState($DB->insert('order', $order) !== false, 'Unable to create callback write test order: '.$DB->error());

	$exception = null;
	try {
		Payment::processOrder(true, $order, str_repeat('a', 151));
	} catch (RuntimeException $e) {
		$exception = $e;
	}
	assertCallbackWriteState($exception !== null, 'An order completion write failure must propagate an exception.');
	assertCallbackWriteState(strpos($exception->getMessage(), 'completion fields') !== false, 'The exception must identify the failed order completion stage. Got: '.$exception->getMessage());
	assertCallbackWriteState((int)$DB->findColumn('order', 'status', ['trade_no'=>$tradeNo]) === 0, 'A failed completion write must roll the order status back.');
	assertCallbackWriteState(number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '') === '0.00', 'A failed completion write must not credit the merchant.');
	assertCallbackWriteState((int)$DB->count('record', ['uid'=>$uid, 'trade_no'=>$tradeNo]) === 0, 'A failed completion write must not leave a balance record.');

	echo "Payment callback write failure tests passed.\n";
} finally {
	while ($DB->getTransactionDepth() > 0) {
		if (!$DB->rollBack()) break;
	}
	if ($uid !== null) $DB->delete('record', ['uid'=>$uid]);
	$DB->delete('order', ['trade_no'=>$tradeNo]);
	if ($uid !== null) $DB->delete('user', ['uid'=>$uid]);
}
