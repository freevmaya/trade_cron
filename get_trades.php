<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine_trade.php');
    define('WAITTIME', 30);
    define('WAITAFTERERROR', WAITTIME * 10);
    define('REMOVEINTERVAL', '1 WEEK');
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

    include_once(INCLUDE_PATH.'_dbu.php');
    include_once(INCLUDE_PATH.'_edbu2.php');
    include_once(INCLUDE_PATH.'fdbg.php');
    include_once(INCLUDE_PATH.'events.php');
    include_once(INCLUDE_PATH.'exmoUtils.php');
    include_once(MAINDIR.'include/db/mySQLProvider.php');
    include_once(MAINDIR.'include/console.php');
    include_once(MAINDIR.'include/crawlers/baseCrawler.php');

    include_once(MAINDIR.'include/crawlers/'.$market_symbol.'Crawler.php');   

    GLOBAL $volumes;
    
    $dbname = 'trade';
    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';
    $dbp = new mySQLProvider('localhost', $dbname, $user, $password);

    startTransaction();
    $dbp->query("DELETE FROM _trades_{$market_symbol} WHERE time <= NOW() - INTERVAL ".REMOVEINTERVAL);
    commitTransaction();
    
    $scriptID = basename(__FILE__);
    $scriptCode = md5(time());
    startScript($dbp, $scriptID, $scriptCode, WAITTIME, '', $is_dev);
    $FDBGLogFile = (__FILE__).'.log';
    new console($is_dev);
    
    $startTime = strtotime('NOW');

    $events = new Events();

    $crawlerName = $market_symbol.'Crawler';
    $crawler = new $crawlerName();

    console::log('START '.$scriptID);

    $table = DBPREF."_trades_{$market_symbol}";
    $market_id = getMarketId($dbp, $market_symbol);
    while (true) {
        $time = time();

        if ($trades = $crawler->getTrades()) {
            if (isset($trades['error'])) {
                console::log($trades['error']);
                sleep(WAITAFTERERROR);
            } else {
                foreach ($trades as $pair=>$data) {
                    $pairA             = explode('_', $pair);
                    $data['cur_in']    = curID($pairA[0]);
                    $data['cur_out']   = curID($pairA[1]);

                    $mysqltime  = date(DATEFORMAT, ceil($time / WAITTIME) * WAITTIME);
                    $query = "REPLACE {$table} (`time`, `cur_in`, `cur_out`, `buy_price`, `sell_price`, `buy_volumes`, `sell_volumes`) ".
                        "VALUES ('{$mysqltime}', {$data['cur_in']}, {$data['cur_out']}, {$data['buy_price']}, {$data['sell_price']},".
                        " {$data['buy_volumes']}, {$data['sell_volumes']})";
                    $dbp->query($query);

                    $events->pairdata("{$market_symbol}trades", $pair, ['time'=>date('d.m H:i'), 'buy_price'=>$data['buy_price'], 'sell_price'=>$data['sell_price'],
                                                      'buy_volumes'=>$data['buy_volumes'], 'sell_volumes'=>$data['sell_volumes']]);


                    $query = "SELECT MIN(`sell_price`) AS min_price, MAX(`sell_price`) AS max_price FROM {$table} ".
                            "WHERE `cur_in`={$data['cur_in']} AND `cur_out`={$data['cur_out']}";
                    if ($minmax = $dbp->line($query)) {
                        $pair_id = getMPID($market_id, $data['cur_in'], $data['cur_out']);
                        $query = "REPLACE _minmax (`pair_id`, `min`, `max`) VALUES ($pair_id, {$minmax['min_price']}, {$minmax['max_price']})";
                        $dbp->query($query);
                    }
                }
            }

            cronReport($dbp, $scriptID, ['is_response'=>is_array($trades)]);
        }

        if (isStopScript($dbp, $scriptID, $scriptCode)) break;
        if (($dtime = $time + WAITTIME - time()) > 0) sleep($dtime);
    }
    console::log('STOP '.$scriptID);

    $dbp->close();
    if ($db) $db->close();
?>