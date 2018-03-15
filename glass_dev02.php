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
    $FDBGLogFile = (__FILE__).'.log';
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
    if (isset($optionsAll[$pair])) $options = $optionsAll[$pair];
    else $options = json_decode(
        '{
            "TICK": 30,
            "CANDLEINTERVAL": 15,
            "CANDLECOUNT": 120,
            "MANAGER": {
                "ema_interval": 8,
                "min_percent": 0.004
            }
        }', true);        

    $candleMin  = 60; //min
    $candles    = new Candles($crawler, $pair, $options['CANDLEINTERVAL'] * 60, time(), time() - 60 * 
                    $options['CANDLEINTERVAL'] * $options['CANDLECOUNT']);

    $mngcfg =  $options['MANAGER'];
    $manager = new tradeManager($candles, $mngcfg);
    $result = $manager->analizer();
    print_r($result);

    if (!isset($result['test_result']) || ($result['test_result']['count'] == 0)) {
        echo "TEST RESULT\n";
        exit;
    }

    if ($istest) exit;

    $purchAll = $config->get('purchases', []);
    $purchase = @$purchAll[$pair];

    $tradeClass = new Trades();

    while (true) {

        //$buysell = $candles->volumeExtreme(); // Экстремальные значение объемов
        //$macd = $candles->macd($options['MACD'][0], $options['MACD'][1], $options['MACD'][2], $options['MACD'][3]);

        $echo = '';
        $time = time();
        $candles->update($time);

        if ($trades = $crawler->getTradeList()) {
            if (isset($trades['error'])) {
                console::log($trades['error']);
                sleep(WAITAFTERERROR);
            } else {

                $tradeClass->addHistory($trades);
                $prices = $tradeClass->lastPrice($pair);

                $candles->updateCurPrices($prices);

                $orders = $crawler->getOrderList();
                $glass = new Glass($orders[$pair]);
                $hist = $glass->histogram(isset($options['HISTOGRAM_STEP'])?$options['HISTOGRAM_STEP']:$prices['buy'] * 0.01);

                $maxwall_ask = $glass->maxWall($hist['ask']);
                $maxwall_bid = $glass->maxWall($hist['bid']);
                $data = $manager->tradeCycle($purchase);

                if (!$purchase) {

                    //print_r($maxwall_bid);
                    if ($prices['buy'] <= $data['buy_price']) {
                        //Заготовка покупки
                        $temp_order = ['time'=>date(DATEFORMAT, $time), 'price'=>$data['buy_price'], 'volume'=>1];

                        $volumes = $tradeClass->lastVolumes($pair, $options['TICK']);
                        // Текущая скорость покупок и продаж в сек.
                        $buy_persec = $volumes['buy_persec']; 
                        $sell_persec = $volumes['sell_persec'];

                        $direct = $volumes['buy'] - $volumes['sell']; // покупают - продают = настроение рынка, т.е. объем дисбаланса за TICK сек. положительно если больше покупают

                        if ($direct < 0) {
                            //Здесь расчет цены попупки с учетом снижения цены
                            //$extra = glass->extrapolate($buy_persec, $sell_persec);
                            $echo .= "Больше продаж\n";
                        } else if ($maxwall_ask[0]) {
                            // Расчитываем расстояние от требуемой цены продажи до наибольшей стенки
                            $data = $manager->tradeCycle($temp_order);
                            
                            $wallPrice = $maxwall_ask[0];
                            $wall_vol = $maxwall_ask[1];
                            $wall_dest = $wallPrice - $data['sell_price'];

                            // Если стенка далеко, то покупаем. Или если объем стенки разбирается за 5 сек.
                            if (($wall_dest > 0) || ($wall_vol < $buy_persec * 5)) {  
                                $purchase = $temp_order;
                            } else $echo .= "Откладываем покупку, стенка на ".sprintf(NFRM, $wallPrice).", объем: ".$wall_vol."\n";

                        } else $purchase = $temp_order; // Или если стенок нет то покупаем

                        if ($purchase) {
                            $echo .= "Выставляем ордер по цене ".sprintf(NFRM, $data['buy_price'])."\n";
                            $purchAll[$pair] = $purchase;
                            $config->set('purchases', $purchAll);
                        }
                    } else $echo .= "Ждем цену меньше ".sprintf(NFRM, $data['buy_price'])."\n";

                } else {
                    $profit = $data['sell_price'] - $purchase['price'];
                    if ($prices['sell'] >= $data['sell_price']) {
                        $echo .= "Продажа по цене ".sprintf(NFRM, $data['sell_price']).", профит: ".sprintf(NFRM, $profit)."!!!\n";
                        $purchase = null;
                        unset($purchAll[$pair]);
                        $profitAll = $config->get('profit', [$pair=>0]);
                        $profitAll[$pair] += $profit;

                        $config->set('profit', $profitAll);
                        $config->set('purchases', $purchAll);
                    } else $echo .= "тек цена продажи: ".sprintf(NFRM, $data['sell_price']).", профит: ".sprintf(NFRM, $profit)."\n";
                }
            }

            console::log($pair."\n".$echo);

            cronReport($dbp, $scriptID, ['time'=>$time]);
        }

        if (isStopScript($dbp, $scriptID, $scriptCode)) break;
        if (($dtime = $time + WAITTIME - time()) > 0) sleep($dtime);
    }
    console::log('STOP '.$scriptID);

    $dbp->close();
?>