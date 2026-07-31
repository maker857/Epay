<?php

require_once __DIR__.'/../includes/lib/PdoHelper.php';

use lib\PdoHelper;

function assertSameValue($expected, $actual, $message)
{
	if ($expected !== $actual) {
		throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true));
	}
}

$prefix = 'tdd_'.bin2hex(random_bytes(4));
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
	$db->exec('CREATE TABLE pre_plugin (name VARCHAR(30) PRIMARY KEY)');
	$result = $db->insert('plugin', ['name' => 'example']);
	assertSameValue(1, $result, 'Insert into a table without an id sequence must return the affected row count.');

	$db->exec('CREATE TABLE pre_record (id BIGSERIAL PRIMARY KEY, label VARCHAR(30) NOT NULL)');
	$id = $db->insert('record', ['label' => 'example']);
	assertSameValue(1, (int)$id, 'Insert into a table with an id sequence must return the generated id.');

	echo "PdoHelper PostgreSQL insert tests passed.\n";
} finally {
	$db->exec('DROP TABLE IF EXISTS pre_record');
	$db->exec('DROP TABLE IF EXISTS pre_plugin');
}
