<?php

require_once __DIR__.'/../includes/common.php';

use lib\Payment;

function assertCallbackValue($expected, $actual, $message)
{
	if ($expected !== $actual) {
		throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true));
	}
}

function callbackTestChannel()
{
	return ['id'=>0, 'mode'=>0, 'costrate'=>0, 'daytop'=>0, 'daymaxorder'=>0];
}

if (($argv[1] ?? null) === 'worker') {
	$tradeNo = $argv[2];
	$startAt = (float)$argv[3];
	$channel = callbackTestChannel();
	while (microtime(true) < $startAt) {
		usleep(1000);
	}
	$order = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade_no', [':trade_no'=>$tradeNo]);
	Payment::processOrder(true, $order, 'concurrent-api-'.$tradeNo);
	echo "worker completed\n";
	exit(0);
}

$suffix = substr(bin2hex(random_bytes(8)), 0, 12);
$tradeNo = substr(date('YmdHis').$suffix, 0, 19);
$userKey = substr(hash('sha256', 'callback-concurrency-'.$suffix), 0, 32);
$uid = null;
$processes = [];

try {
	$uid = $DB->insert('user', [
		'key' => $userKey,
		'money' => '0.00',
		'addtime' => 'NOW()',
		'pay' => 1,
		'settle' => 1,
		'status' => 1,
	]);
	if (!$uid) throw new RuntimeException('Unable to create test user: '.$DB->error());

	$order = [
		'trade_no' => $tradeNo,
		'out_trade_no' => 'callback-concurrency-'.$suffix,
		'api_trade_no' => null,
		'uid' => (int)$uid,
		'tid' => 2,
		'type' => 1,
		'channel' => 0,
		'name' => 'callback concurrency test',
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
	if (!$DB->insert('order', $order)) throw new RuntimeException('Unable to create test order: '.$DB->error());

	$startAt = microtime(true) + 0.5;
	$command = [PHP_BINARY, __FILE__, 'worker', $tradeNo, (string)$startAt];
	$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
	for ($i = 0; $i < 2; $i++) {
		$processes[] = proc_open($command, $descriptors, $pipes);
		if (!is_resource($processes[$i])) throw new RuntimeException('Unable to start callback worker.');
		$processes[$i] = [$processes[$i], $pipes];
	}

	foreach ($processes as [$process, $pipes]) {
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);
		if ($exitCode !== 0) throw new RuntimeException("Callback worker failed ({$exitCode}): {$stderr}{$stdout}");
	}

	$money = $DB->findColumn('user', 'money', ['uid' => $uid]);
	$recordCount = $DB->count('record', ['uid' => $uid, 'trade_no' => $tradeNo]);
	$status = $DB->findColumn('order', 'status', ['trade_no' => $tradeNo]);
	assertCallbackValue('10.00', number_format((float)$money, 2, '.', ''), 'Concurrent callbacks must credit only once.');
	assertCallbackValue(1, (int)$recordCount, 'Concurrent callbacks must create only one balance record.');
	assertCallbackValue(1, (int)$status, 'The concurrently processed order must be marked paid.');
	echo "Payment callback concurrency test passed.\n";
} finally {
	foreach ($processes as [$process, $pipes]) {
		if (is_resource($process)) proc_terminate($process);
	}
	if ($uid !== null) $DB->delete('record', ['uid' => $uid]);
	$DB->delete('order', ['trade_no' => $tradeNo]);
	if ($uid !== null) $DB->delete('user', ['uid' => $uid]);
}
