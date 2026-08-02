<?php

require_once __DIR__.'/../includes/lib/PdoHelper.php';

use lib\PdoHelper;

function assertTransactionState($condition, $message)
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$prefix = 'txstate_'.bin2hex(random_bytes(4));
$dbconfig = [
	'driver' => getenv('DB_DRIVER') ?: 'pgsql',
	'host' => getenv('DB_HOST') ?: 'db',
	'port' => getenv('DB_PORT') ?: '5432',
	'user' => getenv('DB_USER'),
	'pwd' => getenv('DB_PASSWORD'),
	'dbname' => getenv('DB_NAME'),
	'dbqz' => $prefix,
];

$db = new PdoHelper($dbconfig);

try {
	assertTransactionState($db->exec('CREATE TABLE pre_entry (id BIGSERIAL PRIMARY KEY, label VARCHAR(30) NOT NULL)') !== false, 'Unable to create transaction test table.');

	assertTransactionState($db->query('SELECT * FROM pre_missing_table') === false, 'The invalid query must fail.');
	assertTransactionState($db->error() !== null, 'A failed query must expose its error.');
	assertTransactionState((int)$db->getColumn('SELECT 1') === 1, 'The recovery query must succeed.');
	assertTransactionState($db->error() === null, 'A successful query must clear the previous database error.');

	$thrown = false;
	try {
		$db->transaction(function (PdoHelper $connection) {
			$connection->exec('INSERT INTO pre_entry (label) VALUES (NULL)');
			return true;
		});
	} catch (RuntimeException $e) {
		$thrown = true;
	}
	assertTransactionState($thrown, 'A failed SQL statement must prevent transaction() from returning success.');
	assertTransactionState($db->getTransactionDepth() === 0, 'A failed top-level transaction must restore transaction depth to zero.');
	assertTransactionState((int)$db->count('entry', []) === 0, 'A failed transaction must not leave inserted rows.');

	assertTransactionState($db->beginTransaction(), 'Unable to start outer transaction.');
	$outerDepth = $db->getTransactionDepth();
	$thrown = false;
	try {
		$db->transaction(function (PdoHelper $connection) {
			$connection->exec('INSERT INTO pre_entry (label) VALUES (NULL)');
			return true;
		});
	} catch (RuntimeException $e) {
		$thrown = true;
	}
	assertTransactionState($thrown, 'A failed nested transaction must propagate an exception.');
	assertTransactionState($db->getTransactionDepth() === $outerDepth, 'A failed nested transaction must restore the outer transaction depth.');
	assertTransactionState($db->rollBack(), 'Unable to roll back outer transaction.');

	echo "PdoHelper transaction state tests passed.\n";
} finally {
	while ($db->getTransactionDepth() > 0) {
		if (!$db->rollBack()) break;
	}
	$db->exec('DROP TABLE IF EXISTS pre_entry');
}
