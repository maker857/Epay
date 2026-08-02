<?php

require_once __DIR__.'/../includes/common.php';

function assertMoneyState($condition, $message)
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$suffix = bin2hex(random_bytes(6));
$uid = null;
$tradePrefix = 'money-'.$suffix;

try {
	$uid = $DB->insert('user', [
		'key' => substr(hash('sha256', $tradePrefix), 0, 32),
		'money' => '10.00',
		'addtime' => 'NOW()',
		'pay' => 1,
		'settle' => 1,
		'status' => 1,
	]);
	assertMoneyState($uid !== false, 'Unable to create balance test user: '.$DB->error());

	assertMoneyState(changeUserMoney($uid, 5, true, 'test credit', $tradePrefix.'-credit') === 1, 'A valid credit must update exactly one user.');
	$money = $DB->findColumn('user', 'money', ['uid'=>$uid]);
	$record = $DB->find('record', '*', ['uid'=>$uid, 'trade_no'=>$tradePrefix.'-credit']);
	assertMoneyState(number_format((float)$money, 2, '.', '') === '15.00', 'A valid credit must update the balance.');
	assertMoneyState($record && number_format((float)$record['oldmoney'], 2, '.', '') === '10.00' && number_format((float)$record['newmoney'], 2, '.', '') === '15.00', 'A valid credit must write matching old and new balances.');

	$missingThrown = false;
	try {
		changeUserMoney(2147482999, 1, true, 'missing user', $tradePrefix.'-missing');
	} catch (RuntimeException $e) {
		$missingThrown = true;
	}
	assertMoneyState($missingThrown, 'A missing merchant must raise an exception.');
	assertMoneyState($DB->getTransactionDepth() === 0, 'A missing merchant must not leave an open transaction.');
	assertMoneyState((int)$DB->count('record', ['trade_no'=>$tradePrefix.'-missing']) === 0, 'A missing merchant must not create a balance record.');

	$beforeFailure = number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '');
	$recordFailureThrown = false;
	try {
		changeUserMoney($uid, 2, true, str_repeat('x', 21), $tradePrefix.'-record-failure');
	} catch (RuntimeException $e) {
		$recordFailureThrown = true;
	}
	assertMoneyState($recordFailureThrown, 'A balance record insert failure must raise an exception.');
	assertMoneyState($DB->getTransactionDepth() === 0, 'A failed balance record insert must close its transaction.');
	$afterFailure = number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '');
	assertMoneyState($afterFailure === $beforeFailure, 'A failed balance record insert must roll back the balance update.');

	assertMoneyState($DB->beginTransaction(), 'Unable to start outer balance test transaction.');
	$outerDepth = $DB->getTransactionDepth();
	$nestedThrown = false;
	try {
		changeUserMoney($uid, 3, true, str_repeat('y', 21), $tradePrefix.'-nested-failure');
	} catch (RuntimeException $e) {
		$nestedThrown = true;
	}
	assertMoneyState($nestedThrown, 'A nested balance failure must raise an exception.');
	assertMoneyState($DB->getTransactionDepth() === $outerDepth, 'A nested balance failure must restore the outer transaction depth.');
	assertMoneyState($DB->rollBack(), 'Unable to roll back outer balance test transaction.');

	assertMoneyState($DB->beginTransaction(), 'Unable to start changeUserMoney2 test transaction.');
	$oldMoney = $DB->getColumn('SELECT money FROM pre_user WHERE uid=:uid FOR UPDATE', [':uid'=>$uid]);
	$innerThrown = false;
	try {
		changeUserMoney2($uid, $oldMoney, 1, true, str_repeat('z', 21), $tradePrefix.'-inner-failure');
	} catch (RuntimeException $e) {
		$innerThrown = true;
	}
	assertMoneyState($innerThrown, 'changeUserMoney2 must propagate a balance record insert failure.');
	assertMoneyState($DB->rollBack(), 'Unable to roll back changeUserMoney2 test transaction.');
	$finalMoney = number_format((float)$DB->findColumn('user', 'money', ['uid'=>$uid]), 2, '.', '');
	assertMoneyState($finalMoney === $beforeFailure, 'Failed nested balance operations must not change the committed balance.');

	echo "User money transaction tests passed.\n";
} finally {
	while ($DB->getTransactionDepth() > 0) {
		if (!$DB->rollBack()) break;
	}
	if ($uid !== null) {
		$DB->delete('record', ['uid'=>$uid]);
		$DB->delete('user', ['uid'=>$uid]);
	}
}
