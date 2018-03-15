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

                if ($timeCount > $tick) {
                    foreach ($trades as $pair=>$list) {
                        $levels_data = json_decode(file_get_contents('data/'.$market_symbol.'_levels.json'), true);
                        $levels->setData($levels_data[$pair]);

                        $optionsAll = $config->get('options', []);
                        if (isset($optionsAll[$pair])) $options = $optionsAll[$pair];
                        else $options = [
                            "MINSUPPORT"=>0,
                            "MINRESIST"=>0,
                            "MINPROFIT"=>0.005,
                            "MINRIGHTWALLDEST"=>0.12,
                            "MINLEFTWALLDEST"=>0.1,
                            "MINWALLSPREED"=>0.008
                        ];

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
                        $echo .= 'SPEED SELL '.sprintf(NFRM, $sell_persec).', BUY '.sprintf(NFRM, $buy_persec)."\n";

                        if (!(($buy_persec > 0) && ($sell_persec > 0))) {
                            $tick += $tickStep;
                        } else {

                            $glass = new Glass($orders[$pair]);
                            $stop = $glass->extrapolate(max($buy_persec, 1), 
                                                        max($volumes['sell_persec'], 1), 
                                                        $tick * 10);
                            $wall_ask_q->push($stop['ask']['price']);
                            $wall_bid_q->push($stop['bid']['price']);

                            $trade_price = $tradeClass->lastPrice($pair);                           // Последняя цены покупки и продажи
                            if ($trade_price['buy'] && $trade_price['sell']) {
                                $trade_price_avg = ($trade_price['buy'] + $trade_price['sell']) / 2;    // Средняя торговая цена

                                $cur_price  = $trade_price_avg;

                                $directAvg->push($cur_direct);
                                $directAvgLong->push($cur_direct);

                                $i++;
                                if ($i > $wall_smoon_count) {
                                    $spreed = $trade_price['buy'] - $trade_price['sell'];

                                    $wdirect  = $directAvg->weighedAvg();
                                    $ldirect  = $directAvgLong->weighedAvg();

                                    $level_test = $levels->check($cur_price);
                                    $echo .= "PRICE: ".sprintf(NFRM, $cur_price).', LEVEL TEST: '.sprintf(NFRM, $level_test)." ".
                                                    ($levels->checkData()?'ok':'error')."\n";

                                    $wall_ask = $wall_ask_q->weighedAvg();
                                    $wall_bid = $wall_bid_q->weighedAvg();
                                    $wall_interval = $wall_ask - $wall_bid - $spreed;                       // Спред между стенками
                                    /*
                                    $cur_price = ($glass->curPrice('ask') + $glass->curPrice('bid')) / 2;   // Текущая средняя цена в стакане
                                    $cur_direct = $stop['avg_price'] - $cur_price;                          // Текущий расчетный умпульс
                                    */

                                    $left_dest  = ($trade_price['sell'] - $wall_bid) / $wall_interval;             // Расстояние до стенок
                                    $right_dest = ($wall_ask - $trade_price['buy']) / $wall_interval;

                                    $echo .= 'WALLS: '.sprintf(NFRM, $wall_bid).'|'.sprintf(NFRM, $wall_ask).
                                                    " WSPRED: ".sprintf(NFRMS, $wall_interval/$cur_price * 100)."%\n";
                                    $echo .= 'DIRECT_3: '.sprintf(NFRM, $wdirect).", DIRECT_15: ".sprintf(NFRM, $ldirect)."\n";
                                    $echo .= 'LEFT: '.sprintf(NFRMS, $left_dest * 100).'%, RIGHT: '.sprintf(NFRMS, $right_dest * 100)."%\n";

                                    $purchases  = $config->get('purchases', []);
                                    $purchase = isset($purchases[$pair])?$purchases[$pair]:null;

                                    $timeStr = date('d.m H:i:s', $time);

                                    if ($level_test >= $options['MINSUPPORT']) {

                                        // Если есть поддержка от уровня и если расстояние до правой стенки больше минимального профита, 
                                        // т.е. можно заработать на изменении цены до правой стенки

                                        $to_right_wall = $wall_ask - ($cur_price + $cur_price * $options['MINWALLSPREED']);
                                        $echo .= "Готовим покупку. До правой стенки: ".sprintf(NFRM, $to_right_wall)."\n";
                                        if ($to_right_wall > 0) { 

                                            if (($left_dest < $options['MINLEFTWALLDEST']) && ($wdirect >= 0)) {

                                                console::log($echo);
                                                console::log("{$timeStr} ПОКУПКА! Цена: {$trade_price['buy']}\n");
                                                $echo = '';

                                                /*
                                                $purchase = [
                                                    'price'=>$trade_price['buy'],
                                                    'time'=>time(),
                                                    'volume'=>1
                                                ];
                                                $purchases[$pair] = $purchase;
                                                $config->set('purchases', $purchases);
                                                */
                                            }
                                        }
                                    } else if ($level_test <= $options['MINRESIST']) { 

                                        // Если есть приобретение, сопротивление от уровня,
                                        // есть минимальный профит и есть правая стенка на определеном расстоянии, 
                                        // а так преобладает продажи

                                        $prof_percent = $options['MINPROFIT'];//($trade_price['sell'] - $purchase['price']) / $purchase['price'];
                                        if ($prof_percent >= $options['MINPROFIT']) {
                                            $profit = $prof_percent * $purchase['volume'];
                                            $to_right_wall = $options['MINRIGHTWALLDEST'] - $right_dest;
                                            $msg = "Готовим продажу по цене: {$trade_price['sell']}, профит: {$profit}\n";
                                            $echo .= $msg."\n";
                                            if (($to_right_wall > 0) && ($wdirect <= 0)) {

                                                console::log($echo);
                                                console::log("{$timeStr} ПРОДАЖА! {$msg}");
                                                $echo = '';

                                                /*
                                                $purchase = null;
                                                unset($purchases[$pair]);
                                                $config->set('purchases', $purchases);
                                                */
                                            }
                                        }
                                    }
                                }
                            }
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