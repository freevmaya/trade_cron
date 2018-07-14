<?php
    error_reporting(E_ALL);
    include('include/php-binance-api.php');
    include('include/glass/sender/baseSender.php');
    include('include/glass/sender/binanceSender.php');

    $config = json_decode(file_get_contents('/home/apikeys/apikey_binance.json'), true);

    $sender = new binanceSender($config);
    $info = $sender->exchangeInfo('WANBTC');
    print_r($info);
?>  