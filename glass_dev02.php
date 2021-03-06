<?php
    set_time_limit(0);
    error_reporting( E_ALL );
    
    include_once('/home/cron_engine_trade.php');
    define('WAITTIME', 10);
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
    $pair = isset($argv[2])?$argv[2]:'GAS_BTC';
    $arg3 = isset($argv[3])?$argv[3]:'';

    $isecho = $arg3 == 'echo';
    $istest = $arg3 == 'test';

    include_once(MAINDIR.'modules/timeObject.php');
    include_once(MAINDIR.'include/utils.php');
    include_once(INCLUDE_PATH.'fdbg.php');
    include_once(INCLUDE_PATH.'events.php');
    include_once(MAINDIR.'include/db/mySQLProvider.php');
    include_once(MAINDIR.'include/console.php');
    include_once(MAINDIR.'include/log.php');
    include_once(MAINDIR.'include/glass/trades.php');
    include_once(MAINDIR.'include/glass/glass.php');
    include_once(MAINDIR.'include/glass/levels.php');
    include_once(MAINDIR.'include/glass/tradeConfig.php');
    include_once(MAINDIR.'include/glass/candles.php');
    include_once(MAINDIR.'include/glass/trade_manager.php');
    include_once(MAINDIR.'include/glass/math.php');
    include_once(MAINDIR.'include/queue.php');
    include_once(MAINDIR.'include/crawlers/baseCrawler.php');
    include_once(MAINDIR.'include/crawlers/'.$market_symbol.'Crawler.php');   

    GLOBAL $volumes;
    
    $dbname = 'trade';
    $config = new tradeConfig('data/'.$market_symbol.'_glass.json');

    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';
    $dbp = new mySQLProvider('localhost', $dbname, $user, $password);

    $scriptID = basename(__FILE__).$pair;
    $scriptCode = md5(time());

    if (!$istest) 
        startScript($dbp, $scriptID, $scriptCode, WAITTIME, '', $is_dev);
    $FDBGLogFile = $scriptID.'.log';
    new console($is_dev && $isecho);
    
    $startTime = strtotime('NOW');
    $crawlerName = $market_symbol.'Crawler';

    $crawler = new $crawlerName([$pair]);

    console::log('START '.$scriptID);
    $prevPrice = 0;


    $wall_smoon_count = 8;
    $wall_ask_q = new Queue($wall_smoon_count);
    $wall_bid_q = new Queue($wall_smoon_count);
    $i = 0;
    $tickStep   = 5;
    $levels     = new Levels();

    $optionsAll = $config->get('options', []);
    $options = $config->get('default_options');
    if (isset($optionsAll[$pair])) $options = union($options, $optionsAll[$pair]);

    $candleMin  = 60; //min
    $candles    = new Candles($crawler, $pair, $options['CANDLEINTERVAL'] * 60, time(), 
                            time() - 60 * $options['CANDLEINTERVAL'] * $options['CANDLECOUNT']);

    $mngcfg =  $options['MANAGER'];
    $manager = new tradeManager($candles, $mngcfg);

    if ($istest) {
        $result = $manager->analizer();
        if ($is_dev) print_r($result);

        if (!isset($result['test_result']) || ($result['test_result']['count'] == 0)) {
            echo "TEST RESULT\n";
            exit;
        }
        exit;
    }

    $purchAll = $config->get('purchases', []);
    $purchase = @$purchAll[$pair];
    $tradeLog = new Log('data/'.$scriptID.'_trade.log');

    $tradeClass = new Trades();
    $directAvg = new Queue(3);

    while (true) {

        //$buysell = $candles->volumeExtreme(); // Экстремальные значение объемов
        //$macd = $candles->macd($options['MACD'][0], $options['MACD'][1], $options['MACD'][2], $options['MACD'][3]);

        $echo = '';
        $debug = '';
        $time = time();
        $candles->update($time);

        if ($trades = $crawler->getTradeList()) {
            if (isset($trades['error'])) {
                console::log($trades['error']);
                sleep(WAITAFTERERROR);
            } else {

                $tradeClass->addHistory($trades);
                $prices     = $tradeClass->lastPrice($pair);
                $min_profit = $prices['sell'] * $mngcfg['min_percent'];
                $candles->updateCurPrices($prices);

                //$echo .= 'Цены: '.sprintf(NFRM, $prices['buy']).' '.sprintf(NFRM, $prices['sell'])."\n";

                $req_data = $manager->tradeRequired($purchase);
                $debug .= jsonencode($req_data)."\n";
                /*
                echo $debug;
                exit;
                */

                $orders = $crawler->getOrderList([$pair]);
                $glass = new Glass($orders[$pair]);
                $hist = $glass->histogram(isset($options['HISTOGRAM_STEP'])?$options['HISTOGRAM_STEP']:$prices['buy'] * 0.01);

                $maxwall_ask = $glass->maxWall($hist['ask']);
                $maxwall_bid = $glass->maxWall($hist['bid']);

                $volumes = $tradeClass->lastVolumes($pair, $options['TICK']);
                // Текущая скорость покупок и продаж в сек.
                $buy_persec = $volumes['buy_persec']; 
                $sell_persec = $volumes['sell_persec'];

                $allvol = $volumes['buy'] + $volumes['sell'];
                $directAvg->push($volumes['buy']/$allvol - $volumes['sell']/$allvol);

                if (false) {//!$directAvg->isFull()) {
                    $echo .= "Подготовка\n";
                } else {
                    $direct = $directAvg->weighedAvg(); // покупают - продают = настроение рынка, т.е. объем дисбаланса за TICK сек. положительно если больше покупают
                    //$echo .= 'DIRECT: '.$direct." VOLS: b{$volumes['buy']}-s{$volumes['sell']} PRICES: {$prices['buy']}, {$prices['sell']}\n";

                    if (!$purchase && ($req_data['buy'] > 0)) {

    //-------------------------ПОДГОТОВКА ПОКУПКИ-----------------------     
                        $lastCap = $manager->lastCap();
                        $cap = $lastCap[1] - $lastCap[0];
                        $min_profit_test = $min_profit;
                        if ($cap < $min_profit_test) {
                            $echo .= "Текущий зазор недостаточен для торговли ".sprintf(NFRM, $cap).
                                ", требуется не менее ".sprintf(NFRM, $min_profit_test)."\n";
                        } else {

                            // Подготавливаем ордер
                            $temp_order = ['time'=>date(DATEFORMAT, $time), 'price'=>min($prices['sell'], 
                                            $req_data['buy_price']), 'volume'=>$mngcfg['buy_volume'], 'state'=>'order_buy'];
                            $iscreate = 0;

                            if ($maxwall_ask[0]) {
                                // Расчитываем расстояние от требуемой цены продажи до наибольшей правой стенки
                                //$req_data = $manager->tradeRequired($temp_order);
                                $sell_price = $temp_order['price'] + $min_profit;
                                
                                $wallPrice  = $maxwall_ask[0];
                                $wall_dest  = $wallPrice - $sell_price;


                                //$echo .= sprintf(NFRM, $sell_price)."\n";

                                // Если стенка близко 
                                if (($wall_dest <= 0) && ($buy_persec > 0)) {  
                                    // Расчитваем ту цену которая будет после extra_ask секунд, разбирается ли эта стенка
                                    $wallPrice = $glass->extraType('asks', $buy_persec * $mngcfg['extra_ask']);
                                    $wall_dest = $wallPrice - $sell_price;
                                } 

                                if ($wall_dest > 0) $iscreate++;
                                else $echo .= "Откладываем покупку, так как стенка на ".sprintf(NFRM, $wallPrice).", объем: ".$maxwall_ask[1]."\n";

                            } else if ($direct <= $mngcfg['max_buy_direct']) { //Здесь расчет цены попупки с учетом снижения цены
                                //$extra = glass->extrapolate($buy_persec, $sell_persec);
                                $echo .= "Ждем, так как больше продаж {$direct}\n";
                            } else $iscreate++; // Или если стенок нет то покупаем

                            if ($iscreate > 0) {
                                $echo .= "Выставляем ордер на покупку по цене ".sprintf(NFRM, $temp_order['price'])."\n";
                                $purchAll[$pair] = $purchase = $temp_order;
                                $config->set('purchases', $purchAll);
                            }
                        }
                    }

                    if ($purchase) {
                        //$extra = $glass->extrapolate($buy_persec, $sell_persec, 1);
                        //print_r($extra);

    //-------------------------ПОКУПКА-----------------------                     
                        if ($purchase['state'] == 'order_buy') {

                            if ($prices['sell'] <= $purchase['price']) {
                                $echo .= "Сработал ордер на покупку по цене ".sprintf(NFRM, $purchase['price'])."\n";
                                $purchase['state'] = 'completed_buy';
                                $purchAll[$pair] = $purchase;
                                $config->set('purchases', $purchAll);
                                $tradeLog->log($echo.$debug);
                            } else {
                                $left_wall = 0;
                                if (($direct <= $mngcfg['max_buy_direct']) && ($sell_persec > 0)) { // Если продажи преобладают
                                    $left_wall = $glass->extraType('bids', $sell_persec * $mngcfg['extra_bid'] * abs($direct));
                                } else if ($maxwall_bid[0] > 0) $left_wall = $maxwall_bid[0];

                                if ($left_wall > 0) {
                                    $left_price = $left_wall + $left_wall * $mngcfg['min_left_wall'];
                                    if ($req_data['buy_price'] < $left_price) {
                                        $req_data['buy_price'] = $left_price;
                                        $echo .= "Корректируем цену по левой стенке: ".sprintf(NFRM, $req_data['buy_price'])."\n";
                                    }
                                }                            
                                if ($req_data['buy_price'] != $purchase['price']) {
                                    $isLess = $req_data['buy_price'] < $purchase['price'];
                                    $purchase['price'] = $req_data['buy_price'];

                                    $echo .= ($isLess?'Уменьшаем':'Увеличиваем')." цену ".sprintf(NFRM, $purchase['price'])." в ордере на покупку\n";
                                    $purchAll[$pair] = $purchase;
                                    $config->set('purchases', $purchAll);
                                }
                            }
                        } else if (($purchase['state'] == 'completed_buy') && ($req_data['sell'] > 0)) {

    //-------------------------ПОДГОТОВКА ОРДЕРА НА ПРОДАЖУ-----------------------                     
                            if ($direct >= $mngcfg['min_sell_direct']) {
                                $echo .= "Ждем, так как много покупателей {$direct}\n";
                            } else {
                                $purchase['state'] = 'order_sell';
                                $purchase['sell_price'] = $req_data['sell_price'];
                                $purchAll[$pair] = $purchase;
                                $config->set('purchases', $purchAll);
                                $profit = $purchase['sell_price'] - $purchase['price'];
                                $echo .= "Выставляем ордер на продажу по цене ".sprintf(NFRM, $req_data['sell_price']).
                                            ", профит: ".sprintf(NFRM, $profit)."!!!\n";
                            }
                        } else if ($purchase['state'] == 'order_sell') {

    //-------------------------ПРОДАЖА-----------------------   
                            if ($prices['buy'] >= $req_data['sell_price']) {
                                $profit = $purchase['sell_price'] - $purchase['price'];
                                $echo .= "Продажа по цене ".sprintf(NFRM, $req_data['sell_price']).", профит: ".sprintf(NFRM, $profit)."!!!\n";
                                $purchase = null;
                                unset($purchAll[$pair]);
                                $profitAll = $config->get('profit', [$pair=>0]);
                                $profitAll[$pair] = (isset($profitAll[$pair])?$profitAll[$pair]:0) + $profit;

                                $config->set('profit', $profitAll);
                                $config->set('purchases', $purchAll);
                                $tradeLog->log($echo.$debug);
                            } else {
                                $right_wall = 0;
                                if (($direct >= $mngcfg['min_sell_direct']) && ($buy_persec > 0)) { // Если преобладают покупки
                                    $right_wall = $glass->extraType('asks', $buy_persec * $mngcfg['extra_ask'] * $direct);
                                }// else if ($maxwall_ask[0] > 0) $right_wall = $maxwall_ask[0];
                                else if ($direct <= $mngcfg['max_buy_direct']) { // Если преобладают продажи
                                    // И если текщая цена больше требуемой цены продажи, то продавать по тек. цене
                                    if ($req_data['sell_price'] < $prices['buy'])
                                        $req_data['sell_price'] = $prices['buy'];
                                }


                                if ($right_wall > 0) {
                                    //$echo .= "Right WALL {$right_wall}\n";
                                    $right_price = $right_wall - $right_wall * $mngcfg['min_right_wall'];
                                    if ($req_data['sell_price'] < $right_price) {
                                        $req_data['sell_price'] = $right_price;
                                        $echo .= "Корректируем цену по правой стенке: ".sprintf(NFRM, $req_data['sell_price'])."\n";
                                    }
                                }

                                if ($req_data['sell_price'] != $purchase['sell_price']) {
                                    $isLess = $req_data['sell_price'] < $purchase['sell_price'];
                                    $purchase['sell_price'] = $req_data['sell_price'];

                                    $echo .= ($isLess?'Уменьшаем':'Увеличиваем')." цену ".sprintf(NFRM, $purchase['sell_price'])." в ордере на продажу\n";
                                    $purchAll[$pair] = $purchase;
                                    $config->set('purchases', $purchAll);
                                } else {
                                    $profit = $purchase['sell_price'] - $purchase['price'];
                                    $echo .= "Минимальная цена продажи: ".sprintf(NFRM, $req_data['sell_price']).
                                            ". В ордере ".sprintf(NFRM, $purchase['sell_price'])." профит: ".sprintf(NFRM, $profit)."\n";
                                }
                            }
                        }
                    }
                }
            }

            if ($echo) console::log($pair."\n".$echo.$debug);

            cronReport($dbp, $scriptID, ['time'=>$time]);
        }

        if (isStopScript($dbp, $scriptID, $scriptCode)) break;
        if (($dtime = $time + WAITTIME - time()) > 0) sleep($dtime);

        $config->readFile();
        $optionsAll = $config->get('options', []);
        $options = $config->get('default_options');
        if (isset($optionsAll[$pair])) $options = union($options, $optionsAll[$pair]);
    }
    console::log('STOP '.$scriptID);

    $dbp->close();
?>