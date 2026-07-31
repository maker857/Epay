<?php

require_once __DIR__.'/../includes/lib/PdoHelper.php';

use lib\PdoHelper;

$prefix = 'sqlsafe_'.bin2hex(random_bytes(4));
$db = new PdoHelper([
	'driver' => getenv('DB_DRIVER') ?: 'pgsql',
	'host' => getenv('DB_HOST') ?: 'db',
	'port' => getenv('DB_PORT') ?: '5432',
	'user' => getenv('DB_USER'),
	'pwd' => getenv('DB_PASSWORD'),
	'dbname' => getenv('DB_NAME'),
	'dbqz' => $prefix,
]);

try {
	if ($db->exec('CREATE TABLE pre_item (id BIGSERIAL PRIMARY KEY, label TEXT NOT NULL)') === false) {
		throw new RuntimeException('Unable to create SQL injection test table: '.$db->error());
	}
	$db->insert('item', ['label' => 'first']);
	$db->insert('item', ['label' => 'second']);
	$db->insert('item', ['label' => "O'Reilly"]);

	$payload = "missing' OR 1=1 --";
	$exact = $db->getAll('SELECT * FROM pre_item WHERE label=:label', [':label'=>$payload]);
	$like = $db->getAll('SELECT * FROM pre_item WHERE label LIKE :label', [':label'=>'%'.$payload.'%']);
	$quoted = $db->getAll('SELECT * FROM pre_item WHERE label=:label', [':label'=>"O'Reilly"]);

	if (count($exact) !== 0) throw new RuntimeException('Injection payload changed the exact-match result set.');
	if (count($like) !== 0) throw new RuntimeException('Injection payload changed the fuzzy-search result set.');
	if (count($quoted) !== 1) throw new RuntimeException('A legitimate quoted value must remain queryable.');

	echo "PostgreSQL SQL parameter binding tests passed.\n";
} finally {
	$db->exec('DROP TABLE IF EXISTS pre_item');
}
