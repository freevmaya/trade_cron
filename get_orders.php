<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine_trade.php');
    define('WAITTIME', 30);
    define('WAITAFTERERROR', WAITTIME * 10);
    define('REMOVEINTERVAL', '7 DAY');
    define('REMOVEINTERVALORDERS', '1 DAY');
    define('DBPREF', '');
    define('DATEFORMAT', 'Y-m-d H:i:s');
    define('MAINDIR', dirname(__FILE__).'/');

    if (!isset($argv[1])) {
        echo "Name market no found\n";
        exit; 
    }

    $market_symbol = $argv[1];

    include_once(MAINDIR.'modules/timeObject.php');
    include_once(MAINDIR.'include/utils.php');
    include_once(MAINDIR.'modules/cur_watch.php');
    include_once(MAINDIR.'modules/volumes.php');
    include_once(TRADEPATH.'include/_dbu.php');
    include_once(TRADEPATH.'include/_edbu2.php');
    include_once(TRADEPATH.'include/events.php');
    include_once(INCLUDE_PATH.'fdbg.php');
    include_once(MAINDIR.'include/db/mySQLProvider.php');
    include_once(MAINDIR.'include/console.php');
    include_once(MAINDIR.'include/crawlers/baseCrawler.php');

    $dbname = 'trade';

    include_once(MAINDIR.'include/crawlers/'.$market_symbol.'Crawler.php');


    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';
    $dbp = new mySQLProvider('localhost', $dbname, $user, $password);

    $crawlerName = $market_symbol.'Crawler';
    $crawler = new $crawlerName();

    startTransaction();
    $dbp->query("DELETE FROM _orders_{$market_symbol} WHERE time <= NOW() - INTERVAL ".REMOVEINTERVAL);
    commitTransaction();

    $scriptID = basename(__FILE__).$market_symbol;
    $scriptCode = md5(time());

    startScript($dbp, $scriptID, $scriptCode, WAITTIME, '', $is_dev);

    $FDBGLogFile = (__FILE__).'.log';
    new console($is_dev);

    GLOBAL $volumes;

    $startTime = strtotime('NOW');


    console::log('START '.$scriptID);

    $events = new Events();
    
    while (true) {
        $time = time();

        if ($data = $crawler->getOrders()) {
            
            if (is_array($data)) {
                if (isset($data['error']) && $data['error']) {
                    console::log($data['error']);
                    sleep(WAITAFTERERROR);
                } else {
                    foreach ($data as $pair=>$item) {
                         
                        $pairA   = explode('_', $pair);
                        $cur_in_id  = curID($pairA[0]);
                        $cur_out_id = curID($pairA[1]);
                        $volumes = new Volumes($item['ask'], $item['bid']);
                        $mysqltime = date(DATEFORMAT, ceil($time / WAITTIME) * WAITTIME);

                        $query = "INSERT INTO ".DBPREF."_orders_{$market_symbol} (`time`, `cur_in`, `cur_out`, `ask_quantity`, `ask_amount`, `bid_quantity`, `bid_amount`, `ask_top`, `bid_top`, `ask_glass`, `bid_glass`) ".
                            "VALUES ('{$mysqltime}', {$cur_in_id}, {$cur_out_id}, {$item['ask_quantity']}, {$item['ask_amount']}, {$item['bid_quantity']}, ".
                            "'{$item['bid_amount']}', {$item['ask_top']}, {$item['bid_top']}, ".$volumes->getAskvol().','.$volumes->getBidvol().')';
                        $dbp->query($query);

                        $events->pairdata("{$market_symbol}orders", $pair, ['time'=>date('d.m H:i'), 'ask_top'=>$item['ask_top'], 'bid_top'=>$item['bid_top'],
                                                          'ask_glass'=>$volumes->getAskvol(), 'bid_glass'=>$volumes->getBidvol()]);


                        //$volumes->save($dbp->lastID());
                    }
                }
            }
        }

        cronReport($dbp, $scriptID, ['is_response'=>is_array($data)]);
        if (isStopScript($dbp, $scriptID, $scriptCode)) break;

        if (($dtime = $time + WAITTIME - time()) > 0) sleep($dtime);
    }

    console::log('STOP '.$scriptID);
    $dbp->close();
    if ($db) $db->close();
?>