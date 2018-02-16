<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine.php');
    define('WAITTIME', 30);
    define('REMOVEINTERVAL', '7 DAY');
    define('REMOVEINTERVALORDERS', '1 DAY');
    define('DBPREF', '');
    define('DATEFORMAT', 'Y-m-d H:i:s');
    define('TRADEPATH', '/home/vmaya/trade/');
    define('MAINDIR', dirname(__FILE__).'/');

    include_once(MAINDIR.'modules/timeObject.php');
    include_once(MAINDIR.'include/utils.php');
    include_once(MAINDIR.'modules/cur_watch.php');
    include_once(MAINDIR.'modules/volumes.php');
    include_once(MAINDIR.'data/exmo_pairs.php');
    include_once(TRADEPATH.'include/events.php');
    include_once(INCLUDE_PATH.'fdbg.php');
    include_once(MAINDIR.'include/console.php');

    $dbname = 'trade';
    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';

    startTransaction();
    DB::query("DELETE FROM _ask WHERE time <= NOW() - INTERVAL ".REMOVEINTERVALORDERS);
    DB::query("DELETE FROM _bid WHERE time <= NOW() - INTERVAL ".REMOVEINTERVALORDERS);
    DB::query("DELETE FROM _orders WHERE time <= NOW() - INTERVAL ".REMOVEINTERVAL);
    commitTransaction();

    $scriptID = basename(__FILE__);
    $scriptCode = md5(time());
    startScript($scriptID, $scriptCode, WAITTIME);
    $FDBGLogFile = (__FILE__).'.log';
    new console($is_dev);

    GLOBAL $volumes;
    
    $queryURL = 'https://api.exmo.me/v1/order_book?limit=100&pair='.$pairs;
    $startTime = strtotime('NOW');

    console::log('START '.$scriptID);

    $events = new Events();
    
    while (true) {
        $time = time();

        if ($data = @json_decode(file_get_contents($queryURL), true)) {
            if (is_array($data)) {
                if (isset($data['error']) && $data['error']) {
                    console::log($data['error']);
                } else {
                    foreach ($data as $pair=>$item) {
                         
                        $pairA   = explode('_', $pair);
                        $cur_in_id  = curID($pairA[0]);
                        $cur_out_id = curID($pairA[1]);
                        $volumes = new Volumes($item['ask'], $item['bid']);
                        $mysqltime = date(DATEFORMAT, ceil($time / WAITTIME) * WAITTIME);

                        $query = "INSERT INTO ".DBPREF."_orders (`time`, `cur_in`, `cur_out`, `ask_quantity`, `ask_amount`, `bid_quantity`, `bid_amount`, `ask_top`, `bid_top`, `ask_glass`, `bid_glass`) ".
                            "VALUES ('{$mysqltime}', {$cur_in_id}, {$cur_out_id}, {$item['ask_quantity']}, {$item['ask_amount']}, {$item['bid_quantity']}, ".
                            "'{$item['bid_amount']}', {$item['ask_top']}, {$item['bid_top']}, ".$volumes->getAskvol().','.$volumes->getBidvol().')';
                        DB::query($query);

                        $events->pairdata('exmoorders', $pair, ['time'=>date('d.m H:i'), 'ask_top'=>$item['ask_top'], 'bid_top'=>$item['bid_top'],
                                                          'ask_glass'=>$volumes->getAskvol(), 'bid_glass'=>$volumes->getBidvol()]);


                        //$volumes->save(DB::lastID());
                    }
                }
            }
        }

        cronReport($scriptID, ['is_response'=>is_array($data)]);
        if (isStopScript($scriptID, $scriptCode)) break;

        if (($dtime = $time + WAITTIME - time()) > 0) sleep($dtime);
    }

    console::clearUID();
    console::log('STOP '.$scriptID);
    if ($db) $db->close();
?>