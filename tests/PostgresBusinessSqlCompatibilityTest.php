<?php

require_once __DIR__.'/../includes/common.php';

function assertBusinessSql($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

$root = realpath(__DIR__.'/..');
$cron = file_get_contents($root.'/cron.php');
$channel = file_get_contents($root.'/includes/lib/Channel.php');
$ajaxUser = file_get_contents($root.'/admin/ajax_user.php');
$ajaxOrder = file_get_contents($root.'/admin/ajax_order.php');
$ajaxProfitSharing = file_get_contents($root.'/admin/ajax_profitsharing.php');

assertBusinessSql(stripos($cron, 'TO_DAYS(') === false, 'cron.php must not use the MySQL-only TO_DAYS() function.');
assertBusinessSql(stripos($channel, 'ORDER BY rand()') === false, 'Channel fallback must not hard-code MySQL RAND().');
assertBusinessSql(!preg_match('/NOW\(\)\s*-\s*INTERVAL\s+\{?\$?order_days/i', $ajaxUser), 'Inactive merchant filtering must not use MySQL interval syntax.');
assertBusinessSql(!preg_match('/UPDATE\s+pre_order\s+SET[^;\r\n]+LIMIT\s+1/i', $ajaxOrder), 'Order status updates must not use UPDATE LIMIT.');
assertBusinessSql(!preg_match('/UPDATE\s+pre_psorder\s+SET[^;\r\n]+LIMIT\s+1/i', $ajaxProfitSharing), 'Profit-sharing status updates must not use UPDATE LIMIT.');

$cutoff = date('Y-m-d H:i:s', strtotime('-1 day'));
assertBusinessSql($DB->query('SELECT trade_no FROM pre_order WHERE endtime>=:cutoff AND notify>0 AND notifytime<NOW() LIMIT 1', [':cutoff'=>$cutoff]) !== false, 'The PostgreSQL callback retry query must execute.');

$randomFunction = $DB->getDriver() === 'pgsql' ? 'RANDOM()' : 'RAND()';
assertBusinessSql($DB->query("SELECT id FROM pre_channel WHERE status=1 ORDER BY {$randomFunction} LIMIT 1") !== false, 'The driver-specific random channel query must execute.');

$inactiveSince = date('Y-m-d H:i:s', strtotime('-30 days'));
assertBusinessSql($DB->query('SELECT uid FROM pre_user WHERE uid NOT IN (SELECT DISTINCT uid FROM pre_order WHERE date>=:inactive_since)', [':inactive_since'=>$inactiveSince]) !== false, 'The inactive merchant query must execute.');

echo "PostgreSQL business SQL compatibility tests passed.\n";
