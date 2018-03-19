<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine_trade.php');
    define('WAITTIME', 5);
    define('WAITAFTERERROR', WAITTIME * 5);
    define('REMOVEINTERVAL', '1 WEEK');
    define('DBPREF', '');
    define('DATEFORMAT', 'Y-m-d H:i:s');
    define('MAINDIR', dirname(__FILE__).'/');
    define('NFRM', "%01.8f");
    define('NFRMS', "%01.2f");

    if (!isset($argv[1])) {
        echo "Name market no found\n";
        exit; 
    }

    $market_symbol = $argv[1];
    $symbol = isset($argv[2])?$argv[2]:'GAS_BTC';
    $isecho = isset($argv[3]);

    include_once(MAINDIR.'modules/timeObject.php');
    include_once(MAINDIR.'include/utils.php');
    include_once(INCLUDE_PATH.'fdbg.php');
    include_once(INCLUDE_PATH.'events.php');
    include_once(MAINDIR.'include/db/mySQLProvider.php');
    include_once(MAINDIR.'include/console.php');
    include_once(MAINDIR.'include/glass/trades.php');
    include_once(MAINDIR.'include/glass/glass.php');
    include_once(MAINDIR.'include/glass/levels.php');
    include_once(MAINDIR.'include/glass/tradeConfig.php');
    include_once(MAINDIR.'include/queue.php');
    include_once(MAINDIR.'include/crawlers/baseCrawler.php');
    include_once(MAINDIR.'include/crawlers/'.$market_symbol.'Crawler.php');   

    GLOBAL $volumes;
    
    $dbname = 'trade';
    $config = new tradeConfig('data/'.$market_symbol.'_trade.json');

    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';
    $dbp = new mySQLProvider('localhost', $dbname, $user, $password);

    $scriptID = basename(__FILE__);
    $scriptCode = md5(time());

    //startScript($dbp, $scriptID, $scriptCode, WAITTIME, '', $is_dev);
    $FDBGLogFile = (__FILE__).'.log';
    new console($is_dev);
    
    $startTime = strtotime('NOW');
    $crawlerName = $market_symbol.'Crawler';
    $crawler = new $crawlerName([$symbol]);

    console::log('START '.$scriptID);

    $tradeClass = new Trades();
    $directAvg = new Queue(3);
    $directAvgLong = new Queue(15);
    $prevPrice = 0;


    $wall_smoon_count = 3;
    $wall_ask_q = new Queue($wall_smoon_count);
    $wall_bid_q = new Queue($wall_smoon_count);
    $i = 0;
    $tick               = 60;
    $tickStep           = 5;
    $levels             = new Levels();

    while (true) {
        $time = time();
        if ($trades = $crawler->getTradeList()) {
            $orders = $crawler->getOrderList();

            if (isset($trades['error'])) {
                console::log($trades['error']);
                sleep(WAITAFTERERROR);
            } else {

                $tradeClass->addHistory($trades);
                $timeCount = $tradeClass->timeCountSec($symbol);
                $prices = $tradeClass->lastPrice($symbol);

                if ($timeCount > $tick) {
                    foreach ($trades as $pair=>$list) {

                        $volumes = $tradeClass->lastVolumes($pair, $tick);
                        $echo = '';

                        // Текущая скорость покупок и продаж в сек.
                        $buy_persec = $volumes['buy_persec']; 
                        $sell_persec = $volumes['sell_persec'];

                        // Избегаем нулевой скорости
                        $buy_persec = ($buy_persec<=0)?($sell_persec * 0.01):$buy_persec;
                        $sell_persec = ($sell_persec<=0)?($buy_persec * 0.01):$sell_persec;
                        $cur_direct = $buy_persec - $sell_persec;

                        $echo .= "-----------------{$pair}-TICK-{$tick}------------------\n";
//                        $echo .= 'SPEED SELL '.sprintf(NFRM, $sell_persec).', BUY '.sprintf(NFRM, $buy_persec)."\n";

                        if (!(($buy_persec > 0) && ($sell_persec > 0))) {
                            $tick += $tickStep;
                        } else {
                            $avg_price = ($prices['sell'] + $prices['buy']) / 2;

                            $glass = new Glass($orders[$pair]);
                            $stop = $glass->extrapolate(max($buy_persec, 1), 
                                                        max($sell_persec, 1), 
                                                        $tick);
                            $hist = $glass->histogram($avg_price * 0.85, $avg_price * 1.15);
                            $askWall = $glass->maxWall($hist['ask']);
                            $bidWall = $glass->maxWall($hist['bid']);

                            $wall_ask_q->push($stop['ask']['price']);
                            $wall_bid_q->push($stop['bid']['price']);

                            $allvol = $volumes['buy'] + $volumes['sell'];
                            $direct = $volumes['buy']/$allvol - $volumes['sell']/$allvol;
                            $directAvg->push($direct);
                            $directAvgLong->push($direct);

                            $direct_s = $directAvgLong->weighedAvg();
                            $spreedVol = $askWall[2] + $bidWall[2];
                            $tires  = 20;
                            $left_tires    = ceil($askWall[2] / $spreedVol * $tires); 
                            $right_tires     = $tires - $left_tires; 

                            echo $left_tires."\n";

                            $echo .= 'DIRECT S: '.sprintf(NFRM, $direct_s).', L: '.sprintf(NFRM, $directAvgLong->weighedAvg())."\n";
                            $echo .= 'BID: '.sprintf(NFRM, $wall_bid_q->weighedAvg()).' ASK: '.sprintf(NFRM, $wall_ask_q->weighedAvg())."\n";

                            $echo .= sprintf(NFRM, $bidWall[0]).
                                        str_repeat('-', $left_tires + ($direct_s<0?-1:0)).($direct_s<0?'<':'').
                                    sprintf(NFRM, $avg_price).
                                        ($direct_s<0?'':'>').str_repeat('-', $right_tires + ($direct_s<0?0:-1)).
                                     sprintf(NFRM, $askWall[0])."\n";
                        }
                        if ($isecho) echo $echo;
                        $echo = '';
                    }
                }
            }
            //cronReport($dbp, $scriptID, ['is_response'=>is_array($trades)]);
        } else console::log('Empty trade list');

        //if (isStopScript($dbp, $scriptID, $scriptCode)) break;
        if (($dtime = $time + WAITTIME - time()) > 0) sleep($dtime);
    }
    console::log('STOP '.$scriptID);

    $dbp->close();
?>