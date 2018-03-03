<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine_trade.php');
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
    include_once(TRADEPATH.'include/exmoUtils.php');
    include_once(TRADEPATH.'include/LiteMemcache.php');
    include_once(MAINDIR.'include/db/mySQLProvider.php');
    include_once(MAINDIR.'include/utils.php');
    include_once(MAINDIR.'modules/timeObject.php');
    include_once(MAINDIR.'modules/cur_watch.php');
    include_once(MAINDIR.'modules/volumes.php');
    include_once(MAINDIR.'modules/dataModule.php');
    include_once(MAINDIR.'modules/exmo_dataModule.php');
    include_once(MAINDIR.'include/console.php');
    include_once(MAINDIR.'include/senders/exmo_sender.php');
    GLOBAL $volumes, $sender;
    
    $dbname     = 'trade';
    $dbp = new mySQLProvider('localhost', $dbname, $user, $password);

    $isdea      = explode('_', dirname(__FILE__));
    $is_dev     = $isdea[count($isdea) - 1] == 'dev';
    $scriptID   = basename(__FILE__);
    $scriptCode = md5(time());

    startScript($dbp, $scriptID, $scriptCode, WAITTIME, '', $is_dev);

    $FDBGLogFile = (__FILE__).'.log';
    
    $mcache = new LiteMemcache();
    $dbp->setCacheProvider($mcache);

    $dm = new exmoDataModule($dbp, new exmo_sender(), new MCache());
    new console($is_dev, $dm);

    console::log('START '.$scriptID);

    while (true) {
        $time   = time();
        $orders = $dm->getActualOrders();
        
        if ($orders) {
            $dm->resetActualPairs();
            foreach ($orders as $key=>$order) {
                $time = time();
                $dm->setTime($time);
                $watcher = new cur_watch($dm, $order, $time);
                $watcher->watch();

            }
        }

//        echo date(DATEFORMAT, $time)."\n";
        cronReport($dbp, $scriptID, '');
        if (isStopScript($dbp, $scriptID, $scriptCode)) break;

        if (($dtime = $time + CYCLETIME - time()) > 0) sleep($dtime);
    }

    console::log('STOP '.$scriptID);
        
    if ($db) $db->close();
?>