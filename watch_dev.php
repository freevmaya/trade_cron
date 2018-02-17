<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine.php');
    define('WAITTIME', 30);
    define('CYCLETIME', 10);

    //define('CHECKTIME', 60);
    //define('REMOVEINTERVAL', '3 DAY');
    //define('GRABER', true);
    //define('DEVUSER', 4);

    define('DBPREF', '');
    define('DATEFORMAT', 'Y-m-d H:i:s');
    define('HOMEPATH', '/home/');

    define('TRADEPATH', HOMEPATH.'vmaya/trade/');
    define('MAINDIR', dirname(__FILE__).'/');

    include_once(INCLUDE_PATH.'fdbg.php');
    include_once(TRADEPATH.'include/events.php');
    include_once(TRADEPATH.'include/Memcache.php');
    include_once(TRADEPATH.'include/exmoUtils.php');
    include_once(MAINDIR.'include/utils.php');
    include_once(MAINDIR.'modules/timeObject.php');
    include_once(MAINDIR.'modules/cur_watch.php');
    include_once(MAINDIR.'modules/volumes.php');
    include_once(MAINDIR.'modules/dataModule.php');
    include_once(MAINDIR.'modules/exmo_dataModule.php');
    include_once(MAINDIR.'modules/sender.php');
    include_once(MAINDIR.'modules/exmo_sender.php');
    include_once(MAINDIR.'include/console.php');

    GLOBAL $volumes, $sender;
    
    $dbname     = 'trade';
    $isdea      = explode('_', dirname(__FILE__));
    $is_dev     = $isdea[count($isdea) - 1] == 'dev';
    $scriptID   = basename(__FILE__);
    $scriptCode = md5(time());

    startScript($scriptID, $scriptCode, WAITTIME);
    $FDBGLogFile = (__FILE__).'.log';
    
    $sender = new exmoSender();
    $dm = new exmoDataModule($sender, new MCache());
    new console($is_dev, $dm);

    console::log('START '.$scriptID);

    while (true) {
        $time   = time();
        $orders = $dm->getActualOrders();
        console::clearUID();
        //$dm->trace("COUNT: ".count($orders));
        
        if ($orders) {
            $dm->resetActualPairs();
            foreach ($orders as $key=>$order) {
                $time = time();
                console::setUID($order['uid']);
                $dm->setTime($time);
                $watcher = new cur_watch($dm, $order, $time);
                $watcher->watch($sender);

            }
        }

//        echo date(DATEFORMAT, $time)."\n";
        cronReport($scriptID, '');
        if (isStopScript($scriptID, $scriptCode)) break;

        if (($dtime = $time + CYCLETIME - time()) > 0) sleep($dtime);
    }

    console::clearUID();
    console::log('STOP '.$scriptID);
        
    if ($db) $db->close();
?>