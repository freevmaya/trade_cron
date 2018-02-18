<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine.php');
    define('WAITTIME', 30);
    define('REMOVEINTERVAL', '1 WEEK');
    define('DBPREF', '');
    define('DATEFORMAT', 'Y-m-d H:i:s');
    define('TRADEPATH', '/home/vmaya/trade/');
    define('MAINDIR', dirname(__FILE__).'/');

    include_once(MAINDIR.'modules/timeObject.php');
    include_once(MAINDIR.'include/utils.php');
    
    include_once(MAINDIR.'data/exmo_pairs.php');
    include_once(INCLUDE_PATH.'fdbg.php');
    include_once(MAINDIR.'include/console.php');
    include_once(TRADEPATH.'include/events.php');
    include_once(TRADEPATH.'include/exmoUtils.php');

    GLOBAL $volumes;
    
    $dbname = 'trade';
    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';

    startTransaction();
    DB::query("DELETE FROM _trades WHERE time <= NOW() - INTERVAL ".REMOVEINTERVAL);
    commitTransaction();
    
    $scriptID = basename(__FILE__);
    $scriptCode = md5(time());
    startScript($scriptID, $scriptCode, WAITTIME);
    $FDBGLogFile = (__FILE__).'.log';
    new console($is_dev);
    
    $queryURL = 'https://api.exmo.me/v1/trades/?pair=';
    $startTime = strtotime('NOW');

    $events = new Events();
    $pairs_a = explode(',', $pairs);

    console::log('START '.$scriptID);

    $prev = [];
    while (true) {
        $time = time();

        foreach ($pairs_a as $pair) {
            if ($data = @json_decode(file_get_contents($queryURL.$pair), true)) {
                if (isset($data['error']) && $data['error']) {
                    console::log($data['error']);
                } else {
                    if ($result = parseExmoTrades($data, $pair, isset($prev[$pair])?$prev[$pair]:null)) {
                        $pairA      = explode('_', $pair);
                        $cur_in_id  = curID($pairA[0]);
                        $cur_out_id = curID($pairA[1]);
                        $mysqltime  = date(DATEFORMAT, ceil($time / WAITTIME) * WAITTIME);
                        $query = "REPLACE ".DBPREF."_trades (`time`, `cur_in`, `cur_out`, `buy_price`, `sell_price`, `buy_volumes`, `sell_volumes`) ".
                            "VALUES ('{$mysqltime}', {$cur_in_id}, {$cur_out_id}, {$result['buy_price']}, {$result['sell_price']},".
                            " {$result['buy_volumes']}, {$result['sell_volumes']})";
                        DB::query($query);

                        $events->pairdata('exmotrades', $pair, ['time'=>date('d.m H:i'), 'buy_price'=>$result['buy_price'], 'sell_price'=>$result['sell_price'],
                                                          'buy_volumes'=>$result['buy_volumes'], 'sell_volumes'=>$result['sell_volumes']]);

                        $prev[$pair] = $result;
                    }
                }
            }

            cronReport($scriptID, ['is_response'=>is_array($data)]);
        }

        if (isStopScript($scriptID, $scriptCode)) break;
        if (($dtime = $time + WAITTIME - time()) > 0) sleep($dtime);
    }

    console::clearUID();
    console::log('STOP '.$scriptID);
    if ($db) $db->close();
?>