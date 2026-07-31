<?php
/*数据库配置*/
$dbconfig=array(
	'driver' => getenv('DB_DRIVER') ?: 'mysql',
	'host' => getenv('DB_HOST') ?: 'localhost', //数据库服务器
	'port' => (int)(getenv('DB_PORT') ?: 3306), //数据库端口
	'user' => getenv('DB_USER') ?: '', //数据库用户名
	'pwd' => getenv('DB_PASSWORD') ?: '', //数据库密码
	'dbname' => getenv('DB_NAME') ?: '', //数据库名
	'dbqz' => getenv('DB_PREFIX') ?: 'pay' //数据表前缀
);
