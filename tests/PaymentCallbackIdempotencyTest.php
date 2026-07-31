<?php

require_once __DIR__.'/../includes/common.php';

use lib\Payment;

function assertPaymentSame($expected, $actual, $message)
{
	if ($expected !== $actual) {
		throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true));
	}
}

$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$tradeNo = date('YmdHis').substr($suffix, 0, 5);
$userKey = substr(hash('sha256', 'callback-test-'.$suffix), 0, 32);
$uid = null;

try {
	$uid = $DB->insert('user', [
		'key' => $userKey,
		'money' => '0.00',
		'addtime' => 'NOW()',
		'pay' => 1,
		'settle' => 1,
		'status' => 1,
	]);
	if (!$uid) {
		throw new RuntimeException('Unable to create the callback test user: '.$DB->error());
	}

	$order = [
		'trade_no' => $tradeNo,
		'out_trade_no' => 'callback-test-'.$suffix,
		'api_trade_no' => null,
		'uid' => (int)$uid,
		'tid' => 2,
		'type' => 1,
		'channel' => 0,
		'name' => 'callback idempotency test',
		'money' => '10.00',
		'realmoney' => '10.00',
		'getmoney' => '10.00',
		'param' => json_encode(['uid' => (int)$uid]),
		'addtime' => date('Y-m-d H:i:s'),
		'status' => 0,
		'profits' => 0,
		'settle' => 0,
		'combine' => 0,
	];
	if (!$DB->insert('order', $order)) {
		throw new RuntimeException('Unable to create the callback test order: '.$DB->error());
	}

	$channel = [
		'id' => 0,
		'mode' => 0,
		'costrate' => 0,
		'daytop' => 0,
		'daymaxorder' => 0,
	];

	Payment::processOrder(true, $order, 'callback-api-'.$suffix);
	Payment::processOrder(true, $order, 'callback-api-'.$suffix);

	$money = $DB->findColumn('user', 'money', ['uid' => $uid]);
	$recordCount = $DB->count('record', ['uid' => $uid, 'trade_no' => $tradeNo, 'type' => '余额充值']);
	$status = $DB->findColumn('order', 'status', ['trade_no' => $tradeNo]);

	assertPaymentSame('10.00', number_format((float)$money, 2, '.', ''), 'A duplicate callback must not credit the user twice.');
	assertPaymentSame(1, (int)$recordCount, 'A duplicate callback must create only one balance record.');
	assertPaymentSame(1, (int)$status, 'The processed order must be marked paid.');

	echo "Payment callback idempotency test passed.\n";
} finally {
	if ($uid !== null) {
		$DB->delete('record', ['uid' => $uid]);
	}
	$DB->delete('order', ['trade_no' => $tradeNo]);
	if ($uid !== null) {
		$DB->delete('user', ['uid' => $uid]);
	}
}
