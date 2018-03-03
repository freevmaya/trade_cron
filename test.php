<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine_trade.php');    
    include_once('include/utils.php');
    include_once('include/db/dataBaseProvider.php');
    include_once('include/db/mySQLProvider.php');
    include_once(INCLUDE_PATH.'/LiteMemcache.php');


	$obj = new stdClass();
	$obj->base = ['simple'=>'ASD'];

    $result = merge(['price'=>1], $obj, ['price', 'base']);

    print_r($result);
?>