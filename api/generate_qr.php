<?php
require __DIR__.'/phpqrcode/qrlib.php';
$code = $_GET['code'] ?? '';
$base = "http://".$_SERVER['HTTP_HOST'].dirname($_SERVER['PHP_SELF']);
$url  = $base."/join_quiz.php?code=".urlencode($code);

header('Content-Type: image/png');
QRcode::png($url, false, QR_ECLEVEL_M, 6, 1);
