<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine.php');
    define('WAITTIME', 30);
    define('REMOVEINTERVAL', '3 DAY');
    define('DBPREF', '');
    define('GRABER', true);
    define('DATEFORMAT', 'Y-m-d H:i:s');
    define('HOMEPATH', '/home/');

    define('TRADEPATH', HOMEPATH.'vmaya/trade/');
    define('MAINDIR', dirname(__FILE__).'/');

    include_once(INCLUDE_PATH.'fdbg.php');
    include_once(TRADEPATH.'include/events.php');
    include_once(MAINDIR.'include/utils.php');
    include_once(MAINDIR.'modules/timeObject.php');
    include_once(MAINDIR.'modules/cur_watch.php');
    include_once(MAINDIR.'modules/volumes.php');
    include_once(MAINDIR.'modules/dataModule.php');
    include_once(MAINDIR.'modules/sender.php');
    include_once(MAINDIR.'include/console.php');

    GLOBAL $volumes;
    
    $dbname = 'trade';
    $FDBGLogFile = (__FILE__).'.log';
    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';
    $scriptID = basename(__FILE__);
    $scriptCode = md5(time());

    startScript($scriptID, $scriptCode, WAITTIME);

    new console($is_dev);
    console::log('START '.$scriptID.' '.date('H:i:s'));

    while (true) {
        if ($test = DB::line("SELECT * FROM _test WHERE `state`='active'")) {
            $twhere = "`uid`={$test['uid']} AND `market_id`={$test['market_id']} AND `pair`='{$test['pair']}'";
            $start_time = strtotime($test['start_time']);
            $end_time = strtotime($test['end_time']);
            $cur_time = strtotime($test['cur_time']);
            $events = new Events();
            new console();

            $sender = new Sender();
            $dm = new dataModule($test);

            $wData = $dm->getWatchOrders($test['uid'], $test['market_id'], ['test'], [$test['pair']]);
            if (($count = count($wData))>0) {
                DB::query("UPDATE _test SET `state`='process' WHERE {$twhere}");

                $dm->addUserEvent($test['uid'], 'TESTEVENT', ['state'=>'START', 'time'=>$cur_time]);

                while ($cur_time < $end_time) {
                    $abort = false;
                    if ($is_dev) echo date('d.m H:i:s', $cur_time).", COUNT ORDERS: {$count}\n";

                    $dm->addUserEvent($test['uid'], 'TESTEVENT', ['state'=>'PROCESS', 'time'=>$cur_time]);
                    foreach ($wData as $key=>$adata) {
                        $dm->setTime($cur_time);
                        $watcher = new cur_watch($dm, $adata, $cur_time);
                        if ($watcher->watch($sender, ['testComplete'=>false]) == 1) {
                            $cur_time += $watcher->period();
                        }
                        $recState = DB::line("SELECT `state` FROM _test WHERE {$twhere}");
                        if ($abort = $recState['state'] == 'abort') break;
                    }
                    if ($abort) break;
                    $cur_time += WAITTIME;
                    DB::query("UPDATE _test SET `cur_time`='".date(DATEFORMAT, $cur_time)."' WHERE {$twhere}");
                }

                $dm->addUserEvent($test['uid'], 'TESTEVENT', ['state'=>'END', 'time'=>$cur_time]);
            }
            DB::query("UPDATE _test SET `state`='success' WHERE {$twhere}");
        }
        sleep(1);
        cronReport($scriptID, '');
        if (isStopScript($scriptID, $scriptCode)) break;
    }

    console::log('STOP '.$scriptID);
    if ($db) $db->close();
?>