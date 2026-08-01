<?php
$nosession = true;
$s = isset($_GET['s'])?$_GET['s']:exit('404 Not Found');
unset($_GET['s']);
include("./includes/common.php");

if (function_exists("set_time_limit"))
{
	@set_time_limit(0);
}
if (function_exists("ignore_user_abort"))
{
	@ignore_user_abort(true);
}

$sitename=isset($_GET['sitename'])?base64_decode($_GET['sitename']):'';
$submit2=true;

try{
	$result = \lib\Plugin::loadForPay($s);
	\lib\Payment::echoDefault($result);
}catch(\Throwable $e){
    $diagnostic = [
        'route' => $s,
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        'post_keys' => array_keys($_POST),
        'exception' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
    error_log('[pay callback] '.json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	sysmsg($e->getMessage());
}
