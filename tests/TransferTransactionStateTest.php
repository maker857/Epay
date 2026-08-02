<?php

require_once __DIR__.'/../includes/common.php';

use lib\Transfer;

function assertTransferState($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

function transferTestNo($suffix)
{
	return date('YmdHis').str_pad((string)$suffix, 5, '0', STR_PAD_LEFT);
}

global $conf, $userrow;

$suffix = random_int(10000, 80000);
$uid = null;
$channelId = null;
$bizNos = [];
$originalConf = $conf;
$originalUserrow = $userrow ?? null;

try {
	$conf['transfer_minmoney'] = 0;
	$conf['transfer_maxmoney'] = 0;
	$conf['transfer_maxlimit'] = 0;
	$conf['transfer_rate'] = 0;
	$conf['settle_rate'] = 0;
	$conf['settle_type'] = 0;
	$conf['alipay_satf'] = 0;

	$uid = $DB->insert('user', [
		'key'=>substr(hash('sha256', 'transfer-'.$suffix), 0, 32),
		'money'=>'100.00',
		'addtime'=>'NOW()',
		'pay'=>1,
		'settle'=>1,
		'transfer'=>1,
		'status'=>1,
	]);
	assertTransferState($uid !== false, 'Unable to create transfer test user: '.$DB->error());
	$userrow = $DB->find('user', '*', ['uid'=>$uid]);

	$channelId = $DB->insert('channel', [
		'mode'=>0,
		'type'=>1,
		'plugin'=>'transfer_test',
		'name'=>'transfer test',
		'rate'=>'100.00',
		'status'=>1,
	]);
	assertTransferState($channelId !== false, 'Unable to create transfer test channel: '.$DB->error());

	$successNo = transferTestNo($suffix);
	$bizNos[] = $successNo;
	$submitCalls = 0;
	$successSubmitter = function () use (&$submitCalls, $DB, $uid, $successNo) {
		$submitCalls++;
		assertTransferState($DB->getTransactionDepth() === 0, 'The external transfer call must run outside a database transaction.');
		$reserved = $DB->find('transfer', '*', ['biz_no'=>$successNo]);
		assertTransferState($reserved && (int)$reserved['status'] === 0, 'The transfer must be persisted before the external call.');
		$balance = $DB->findColumn('user', 'money', ['uid'=>$uid]);
		assertTransferState(number_format((float)$balance, 2, '.', '') === '90.00', 'The balance must be reserved before the external call.');
		return ['code'=>0, 'status'=>1, 'orderid'=>'remote-success', 'paydate'=>date('Y-m-d H:i:s')];
	};
	$result = Transfer::add($uid, 'alipay', $successNo, 'payee@example.com', 'Payee', 10, null, 'success test', null, $channelId, $successSubmitter);
	assertTransferState($result['code'] === 0 && (int)$result['status'] === 1, 'A successful external transfer must return success.');
	$successOrder = $DB->find('transfer', '*', ['biz_no'=>$successNo]);
	assertTransferState((int)$successOrder['status'] === 1 && $successOrder['pay_order_no'] === 'remote-success', 'A successful transfer must persist the provider result.');

	$duplicate = Transfer::add($uid, 'alipay', $successNo, 'payee@example.com', 'Payee', 10, null, 'duplicate test', null, $channelId, $successSubmitter);
	assertTransferState($duplicate['code'] === -1, 'A duplicate merchant transfer number must be rejected.');
	assertTransferState($submitCalls === 1, 'A duplicate transfer must not call the provider again.');
	assertTransferState(number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '') === '90.00', 'A duplicate transfer must not deduct the balance again.');

	$failureNo = transferTestNo($suffix + 1);
	$bizNos[] = $failureNo;
	$failureResult = Transfer::add($uid, 'alipay', $failureNo, 'failed@example.com', 'Failed', 10, null, 'failure test', null, $channelId, function () use ($DB) {
		assertTransferState($DB->getTransactionDepth() === 0, 'A failed external transfer call must run outside a transaction.');
		return ['code'=>-1, 'status'=>2, 'msg'=>'provider rejected', 'errmsg'=>'provider rejected'];
	});
	assertTransferState($failureResult['code'] === -1, 'An explicit provider failure must remain a failure response.');
	$failureOrder = $DB->find('transfer', '*', ['biz_no'=>$failureNo]);
	assertTransferState((int)$failureOrder['status'] === 2, 'An explicit provider failure must mark the transfer failed.');
	assertTransferState(number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '') === '90.00', 'An explicit provider failure must refund the reserved balance.');
	assertTransferState((int)$DB->count('record', ['uid'=>$uid, 'trade_no'=>$failureNo, 'type'=>'代付退回']) === 1, 'An explicit provider failure must write one refund record.');

	$unknownNo = transferTestNo($suffix + 2);
	$bizNos[] = $unknownNo;
	$unknownResult = Transfer::add($uid, 'alipay', $unknownNo, 'unknown@example.com', 'Unknown', 10, null, 'unknown test', null, $channelId, function () {
		throw new RuntimeException('provider timeout');
	});
	assertTransferState($unknownResult['code'] === -1 && (int)$unknownResult['status'] === 0, 'An uncertain provider result must remain pending reconciliation.');
	$unknownOrder = $DB->find('transfer', '*', ['biz_no'=>$unknownNo]);
	assertTransferState((int)$unknownOrder['status'] === 0, 'An uncertain provider result must keep the local transfer pending.');
	assertTransferState(number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '') === '80.00', 'An uncertain provider result must keep the balance reserved.');

	$localFailureNo = transferTestNo($suffix + 3);
	$bizNos[] = $localFailureNo;
	$localFailure = Transfer::add($uid, 'alipay', $localFailureNo, 'local@example.com', 'Local', 10, null, 'local failure test', null, $channelId, function () {
		return ['code'=>0, 'status'=>1, 'orderid'=>str_repeat('r', 81), 'paydate'=>date('Y-m-d H:i:s')];
	});
	assertTransferState($localFailure['code'] === -1 && (int)$localFailure['status'] === 0, 'A local finalization failure must return a pending reconciliation result.');
	$localFailureOrder = $DB->find('transfer', '*', ['biz_no'=>$localFailureNo]);
	assertTransferState((int)$localFailureOrder['status'] === 0, 'A local finalization failure must keep the transfer pending.');
	assertTransferState(number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '') === '70.00', 'A local finalization failure must keep the balance reserved.');

	$recovered = Transfer::status($localFailureNo, function () {
		return ['code'=>0, 'status'=>1, 'orderid'=>'remote-recovered', 'paydate'=>date('Y-m-d H:i:s')];
	});
	assertTransferState($recovered['code'] === 0 && (int)$recovered['status'] === 1, 'A provider query must recover a pending transfer.');
	$recoveredOrder = $DB->find('transfer', '*', ['biz_no'=>$localFailureNo]);
	assertTransferState((int)$recoveredOrder['status'] === 1, 'A recovered transfer must be marked successful.');
	assertTransferState(number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '') === '70.00', 'A successful reconciliation must not change the reserved balance again.');

	Transfer::processNotify($unknownNo, 2, 'asynchronous failure');
	Transfer::processNotify($unknownNo, 2, 'duplicate asynchronous failure');
	$notifiedFailure = $DB->find('transfer', '*', ['biz_no'=>$unknownNo]);
	assertTransferState((int)$notifiedFailure['status'] === 2, 'An asynchronous failure must mark a pending transfer failed.');
	assertTransferState(number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '') === '80.00', 'An asynchronous failure must refund the reserved balance once.');
	assertTransferState((int)$DB->count('record', ['uid'=>$uid, 'trade_no'=>$unknownNo, 'type'=>'代付退回']) === 1, 'Duplicate failure callbacks must not refund twice.');

	$redSuccessNo = transferTestNo($suffix + 4);
	$bizNos[] = $redSuccessNo;
	$redCreated = Transfer::red_add($uid, 'alipay', $redSuccessNo, 10, 'red success', $channelId);
	assertTransferState($redCreated['code'] === 0 && (int)$redCreated['status'] === 4, 'A red packet transfer must be created in the waiting state.');
	assertTransferState(number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '') === '70.00', 'Creating a red packet must reserve its balance.');
	$redCalls = 0;
	$redReceived = Transfer::red_receive($redSuccessNo, 'recipient-openid', function () use (&$redCalls, $DB, $redSuccessNo) {
		$redCalls++;
		assertTransferState($DB->getTransactionDepth() === 0, 'A red packet provider call must run outside a database transaction.');
		$order = $DB->find('transfer', '*', ['biz_no'=>$redSuccessNo]);
		assertTransferState((int)$order['status'] === 0, 'A claimed red packet must be persisted as processing before the provider call.');
		return ['code'=>0, 'status'=>1, 'orderid'=>'red-remote-success', 'paydate'=>date('Y-m-d H:i:s')];
	});
	assertTransferState($redReceived['code'] === 0 && (int)$redReceived['status'] === 1, 'A red packet provider success must be returned.');
	assertTransferState((int)$DB->findColumn('transfer', 'status', ['biz_no'=>$redSuccessNo]) === 1, 'A received red packet must be marked successful.');
	$redDuplicate = Transfer::red_receive($redSuccessNo, 'recipient-openid', function () use (&$redCalls) {
		$redCalls++;
		return ['code'=>0, 'status'=>1, 'orderid'=>'duplicate'];
	});
	assertTransferState($redDuplicate['code'] === -1 && $redCalls === 1, 'A received red packet must not call the provider twice.');

	$redFailureNo = transferTestNo($suffix + 5);
	$bizNos[] = $redFailureNo;
	Transfer::red_add($uid, 'alipay', $redFailureNo, 10, 'red failure', $channelId);
	$redFailure = Transfer::red_receive($redFailureNo, 'failed-openid', function () {
		return ['code'=>-1, 'status'=>2, 'msg'=>'red provider rejected'];
	});
	assertTransferState($redFailure['code'] === -1, 'A red packet provider failure must be returned.');
	assertTransferState((int)$DB->findColumn('transfer', 'status', ['biz_no'=>$redFailureNo]) === 4, 'An explicit red packet failure must return to the claimable state.');
	assertTransferState(number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '') === '60.00', 'A claim failure must keep the red packet balance reserved.');

	$redUnknownNo = transferTestNo($suffix + 6);
	$bizNos[] = $redUnknownNo;
	Transfer::red_add($uid, 'alipay', $redUnknownNo, 10, 'red unknown', $channelId);
	$redUnknown = Transfer::red_receive($redUnknownNo, 'unknown-openid', function () {
		throw new RuntimeException('red provider timeout');
	});
	assertTransferState($redUnknown['code'] === -1 && (int)$redUnknown['status'] === 0, 'An uncertain red packet result must remain pending reconciliation.');
	assertTransferState((int)$DB->findColumn('transfer', 'status', ['biz_no'=>$redUnknownNo]) === 0, 'An uncertain red packet must remain processing.');
	assertTransferState(number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '') === '50.00', 'An uncertain red packet must keep its balance reserved.');

	echo "Transfer transaction state tests passed.\n";
} finally {
	while ($DB->getTransactionDepth() > 0) {
		if (!$DB->rollBack()) break;
	}
	foreach ($bizNos as $bizNo) {
		$DB->delete('record', ['trade_no'=>$bizNo]);
		$DB->delete('transfer', ['biz_no'=>$bizNo]);
	}
	if ($channelId !== null) $DB->delete('channel', ['id'=>$channelId]);
	if ($uid !== null) {
		$DB->delete('record', ['uid'=>$uid]);
		$DB->delete('user', ['uid'=>$uid]);
	}
	$conf = $originalConf;
	$userrow = $originalUserrow;
}
