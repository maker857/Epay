<?php

require_once __DIR__.'/../includes/lib/PdoHelper.php';

use lib\PdoHelper;

function assertPrefixState($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

$root = realpath(__DIR__.'/..');
$cron = file_get_contents($root.'/cron.php');
$ajaxUser = file_get_contents($root.'/admin/ajax_user.php');
$update = file_get_contents($root.'/install/update.php');

assertPrefixState(stripos($cron, 'delete from pay_wxkflog') === false, 'cron.php must not hard-code pay_wxkflog.');
assertPrefixState(!preg_match('/\bFROM\s+pay_order\b/i', $ajaxUser), 'admin/ajax_user.php must not hard-code pay_order.');
assertPrefixState(!preg_match('/\bJOIN\s+pay_blacklist\b/i', $ajaxUser), 'admin/ajax_user.php must not hard-code pay_blacklist.');
assertPrefixState(stripos($update, 'SELECT v FROM pay_config') === false, 'install/update.php must honor the configured database prefix.');

$prefix = 'prefix_'.bin2hex(random_bytes(4));
$db = new PdoHelper([
	'driver'=>getenv('DB_DRIVER') ?: 'pgsql',
	'host'=>getenv('DB_HOST') ?: 'db',
	'port'=>getenv('DB_PORT') ?: '5432',
	'user'=>getenv('DB_USER'),
	'pwd'=>getenv('DB_PASSWORD'),
	'dbname'=>getenv('DB_NAME'),
	'dbqz'=>$prefix,
]);

try {
	assertPrefixState($db->exec('CREATE TABLE pre_order (trade_no VARCHAR(32) PRIMARY KEY)') !== false, 'Unable to create custom-prefix order table.');
	assertPrefixState($db->exec('CREATE TABLE pre_blacklist (id BIGSERIAL PRIMARY KEY, content VARCHAR(32))') !== false, 'Unable to create custom-prefix blacklist table.');
	assertPrefixState($db->exec('CREATE TABLE pre_wxkflog (id BIGSERIAL PRIMARY KEY, addtime TIMESTAMP)') !== false, 'Unable to create custom-prefix log table.');
	assertPrefixState($db->insert('order', ['trade_no'=>'prefix-test']) !== false, 'Unable to insert into custom-prefix order table.');
	assertPrefixState($db->findColumn('order', 'trade_no', ['trade_no'=>'prefix-test']) === 'prefix-test', 'The logical pre_ prefix must resolve to the configured table prefix.');
	echo "Database prefix compatibility tests passed.\n";
} finally {
	$db->exec('DROP TABLE IF EXISTS pre_wxkflog');
	$db->exec('DROP TABLE IF EXISTS pre_blacklist');
	$db->exec('DROP TABLE IF EXISTS pre_order');
}
