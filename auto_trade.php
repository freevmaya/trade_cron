<?php
/*
    Параметры
        m - маркет
        s - торговые пары, через запятую
        e - показывать информацию
        td - 0 или 1 - если торговать
*/

    set_time_limit(0);
    
    include_once('/home/cron_engine_trade.php');
    define('WAITTIME', 5);
    define('FINDTIME', 240);
    define('WAITAFTERERROR', WAITTIME * 5);
    define('REMOVEINTERVAL', '1 WEEK');
    define('PURCHASE_FILE', 'data/trade_pair.json');
    define('DBPREF', '');
    define('DATEFORMAT', 'Y-m-d H:i:s');
    define('MAINDIR', dirname(__FILE__).'/');
    define('NFRM', "%01.8f");
    define('NFRMS', "%01.2f");
    define('MAXWAITTRADE', 60);
    define('PAIRFILEDATA', 'data/auto_trade_pairs.json');

    if (!isset($argv[1])) {
        echo "Name market no found\n";
        exit; 
    }

    $params = [];
    for ($i=1;$i<count($argv);$i++) {
        $a = explode('=', $argv[$i]);
        $params[$a[0]] = isset($a[1])?$a[1]:true;
    }

    $market_symbol  = $params['m'];                 // Маркет
    $isecho         = $params['e'];                 // echo
    $p_symbols      = @$params['s'];                 // symbol
    $istrade        = isset($params['td'])?$params['td']:false;

    include_once(MAINDIR.'modules/timeObject.php');
    include_once(MAINDIR.'include/utils.php');
    include_once(INCLUDE_PATH.'fdbg.php');
    include_once(INCLUDE_PATH.'events.php');
    include_once(MAINDIR.'include/db/mySQLProvider.php');
    include_once(MAINDIR.'include/console.php');
    include_once(MAINDIR.'include/queue.php');

    include_once(MAINDIR.'include/glass/trades.php');
    include_once(MAINDIR.'include/glass/glass.php');
    include_once(MAINDIR.'include/glass/levels.php');
    include_once(MAINDIR.'include/glass/tradeConfig.php');
    include_once(MAINDIR.'include/glass/orderHistory.php');
    include_once(MAINDIR.'include/glass/checkPair.php');
    include_once(MAINDIR.'include/glass/candles.php');
    include_once(MAINDIR.'include/glass/math.php');
    include_once(MAINDIR.'include/glass/sender/baseSender.php');
    include_once(MAINDIR.'include/glass/sender/'.$market_symbol.'Sender.php');
    include_once(MAINDIR.'include/crawlers/baseCrawler.php');
    include_once(MAINDIR.'include/crawlers/'.$market_symbol.'Crawler.php');   

    GLOBAL $volumes;
    
    $dbname = 'trade';
    $config = new tradeConfig('data/'.$market_symbol.'_auto_trade.json');

    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';
    $dbp = new mySQLProvider('localhost', $dbname, $user, $password);

    if ($p_symbols) $symbols = explode(',', $p_symbols);
    else $symbols = json_decode(file_get_contents(PAIRFILEDATA), true);

    $scriptID = basename(__FILE__).md5(implode("_", $symbols));
    $scriptCode = md5(time());
    $candleMin  = 60; //min

    startScript($dbp, $scriptID, $scriptCode, WAITTIME, '', $is_dev);
    $FDBGLogFile = (__FILE__).'.log';
    new console($is_dev);
    
    $startTime = strtotime('NOW');
    $crawlerName = $market_symbol.'Crawler';
    $crawler = new $crawlerName($symbols);

    $senderName = 'baseSender';//$market_symbol.'Sender';
    $sender = new $senderName(json_decode(file_get_contents(APIKEYPATH.'apikey_'.$market_symbol.'.json'), true));
    $optionsAll = $config->get('options', []);


/*
    $baseCurs = ['BTC','ETH','BNB'];
    $list = $crawler->getTradedWith($baseCurs);

    $pairs = [];
    foreach ($baseCurs as $rcur)
        foreach ($list as $lcur) {
            $ticker = $crawler->ticker($lcur.$rcur);
            if ($ticker['priceChangePercent'] > 0) $pairs[] = $ticker;
            if (count($pairs) > 20) break;
            usleep(1000);
        }

    print_r($pairs);

    exit;
*/    
    function checkMACD($crawler, $symbol, $options, $returnCandle=false) {
        $result = false;
        $time = time();
        $candles = new Candles($crawler, $symbol, $options['CANDLEINTERVAL'] * 60, $time, 
                                $time - 60 * $options['CANDLEINTERVAL'] * $options['CANDLECOUNT']);
        $candles->update($time);

        $ema = $candles->ema($options['MANAGER']['EMAINTERVAL']);

        $slope = ($ema[count($ema) - 1] - $ema[0])/$ema[count($ema) - 1];

        if ($slope > $options['MANAGER']['MINEMASLOPE']) {
            if ($candles->buyCheck($options['MANAGER']['MACD'], 
                            floatval($options['MANAGER']['buy_macd_value']), 
                            floatval($options['MANAGER']['buy_macd_direct']))) { 
                $result = $returnCandle?$candles:true;
            }
        } else echo "SLOPE: $slope\n";

        if (!$returnCandle) $candles->dispose();        
        return $result;
    }

    function checkPairState($crawler, $symbol, $options) {
        if ($candles = checkMACD($crawler, $symbol, $options, true)) {
            $volumes = $candles->getVolumes();
            $ext     = $candles->volumeExtreme();
            $up      = ($ext['buy'] - $volumes[count($volumes) - 1]) / $ext['buy'];

            $candles->dispose();

            $result = ($up >= $options['MANAGER']['BUYMORE']) && ($ext['buy'] >= $options['MANAGER']['MAXBUYVOLUME']);
            if (!$result) 
                echo "BUYMORE: {$up}, MAXBUYVOLUME: {$ext['buy']}\n";

            return $result;
        }

        return false;
    }

    function readFileData($symbol) {
        $file_name = str_replace('pair', $symbol, PURCHASE_FILE);
        if (file_exists($file_name)) {
            $file_data = json_decode(file_get_contents($file_name), true);
        } else $file_data = ['purchase'=>null, 'profit'=>0];
        return $file_data;
    }

    function writeFileData($symbol, $file_data) {
        $file_name = str_replace('pair', $symbol, PURCHASE_FILE);
        file_put_contents($file_name, json_encode($file_data));
    }

    function readOptions($symbol) {
        GLOBAL $config;
        $trade_options = $config->get('default_options');
        if (isset($optionsAll[$symbol])) $trade_options = union($trade_options, $optionsAll[$symbol]);
        return $trade_options;
    }

    console::log('START '.$scriptID);
    $tradeClass = new Trades();
    $prevPrice  = 0;
    $checkList  = [];
    $komsa      = 0.001;
    $state      = 'find';
    $cur_index  = 0;
    $cur_count  = count($symbols);
    $wait_buy_count = 0;

// Ищем ту валюту по которой есть покупка
    for ($i=0; $i<$cur_count; $i++) {
        $file_data = readFileData($symbols[$i]);
        if ($file_data['purchase'] != null) {
            // Если нашли, тогда режим торговли
            $state = 'trade';
            $cur_index = $i;
            break;
        }
    }

// Основной цикл
    while (true) {
        $time   = time();
        $stime  = date(DATEFORMAT, $time);

//        if (filectime(PAIRFILEDATA) >= $time - WAITTIME * 2) { // Если недавно изменился файл списка пар, тогда обновляем список
        if (!$p_symbols && ($state == 'find')) {
            $symbols    = json_decode(file_get_contents(PAIRFILEDATA), true);
            $cur_count  = count($symbols);
        }
//        }

        $symbol     = $symbols[$cur_index];

        ob_start();
        $file_data      = readFileData($symbol);
        $trade_options  = readOptions($symbol);
        $isPurchase     = $file_data['purchase'] != null;

        $check_options = array_merge([
            'state'=>$isPurchase?'sell':'buy'
        ], $trade_options);

        if ($state == 'find') {
            if ($isecho > 1) echo "{$stime} check MACD {$symbol}\n";
            if (checkPairState($crawler, $symbol, $trade_options)) {
                $state = 'trade';
                $wait_buy_count = 0;                
            } else {
                if ($isecho > 1) echo "NO MACD AND VOLUMES\n";
                $cur_index = ($cur_index + 1) % $cur_count;
            }
        } else if ($state == 'trade') {
            if ($trades = $crawler->getTradeList([$symbol])) {
                $orders = $crawler->getOrderList([$symbol]);

                if (isset($trades['error'])) {
                    console::log($trades['error']);
                    sleep(WAITAFTERERROR);
                } else {

                    $tradeClass->addHistory($trades);
                    foreach ($trades as $symbol=>$list) {

                        if (!isset($checkList[$symbol])) 
                            $checkList[$symbol] = new checkPair($symbol, $tradeClass);
                        else {
                            if ($istrade) {

                                $data = $checkList[$symbol]->check($orders[$symbol], $check_options);
    //                            $data['state'] = 'buy'; // DEV
    //                            $data['price'] = 0.11; // DEV

                                //echo .= print_r($data, true); // DEV

                                if ($isecho > 1) echo $data['msg'];
                                $curs = explode('_', $symbol);                            

                                if (!$isPurchase) {
                                    if ($data['state'] == 'buy') {
                                        if (checkMACD($crawler, $symbol, $trade_options)) {
                                            $balance = $sender->balance($curs[1]);
                                            if (($buyvol = $sender->volumeFromBuy($symbol, $data['price'], floatval($trade_options['BUYMINVOLS']), $komsa * 2)) > 0) { 
                                                $require = $buyvol * $data['price'];

                                                if ($balance < $require) {
                                                    echo "Not enough balance. Require: {$require}, available: {$balance}\n";
                                                } else {

                                                    $order = $sender->buy($symbol, $buyvol, $data['price']);//DEV

                                                    if ($order && !isset($order['code'])) {
                                                        $file_data['purchase'] = ['date'=>$stime, 'symbol'=>$symbol, 
                                                                'price'=>$data['price'], 'volume'=>$order['executedQty'], 'order'=>$order]; 
                                                        echo $stime." BUY!!!\n";
                                                        echo $data['msg'];
                                                        writeFileData($symbol, $file_data);
                                                    } else throw new Exception("Unknown error when creating an order buy", 1);
                                                }
                                            } else echo "Does not comply with the rule of trade\n";
                                        } else {
                                            echo "Purchase rejected MACD\n";
                                            $state = 'find';
                                        }
                                    } else {
                                        $wait_buy_count++;
                                        if ($wait_buy_count > MAXWAITTRADE) $state = 'find';
                                    } 
                                } else {
                                    $order = $file_data['purchase']['order'];

                                    if (!isset($order['SUCCESS'])) { // Если еще необработан
                                        $checkData = $sender->checkOrder($order);
                                        if ($checkData['status'] != 'FILLED') { // Если еще не заполенен то удаляем
                                            $result = $sender->cancelOrder($order);
                                            if ($result['orderId']) {
                                                $file_data['purchase'] = null;
                                                writeFileData($symbol, $file_data);
                                                $state = 'find';
                                            }
                                        } else {
                                            $file_data['purchase']['order']['SUCCESS'] = 1;
                                            writeFileData($symbol, $file_data);
                                        }
                                    } else if ($data['state'] == 'sell') {
                                        $t_profit = $data['price'] - $data['price'] * $komsa * 2 - $file_data['purchase']['price'];
                                        $prof_percent = $t_profit/$file_data['purchase']['price'];
                                        $min_percent = $trade_options['MANAGER']['min_percent'];
                                        echo "PROFIT PERCENT: ".sprintf(NFRM, $prof_percent).", REQUIRE: {$min_percent}\n";

                                        if ($prof_percent >= $min_percent) {
                                            $volume = $file_data['purchase']['volume'];

                                            $order = $sender->sell($symbol, $volume, $data['price']);//DEV
                                            echo print_r($order, true);

                                            if ($order && !isset($order['code'])) {
                                                $file_data['profit'] += $t_profit;
                                                $file_data['purchase'] = null;

                                                echo $stime." SELL PROFIT: {$file_data['profit']}\n";
                                                writeFileData($symbol, $file_data);
                                                $state = 'find';

                                            } else throw new Exception("Unknown error when creating an order sell", 1);
                                            echo $data['msg'];
                                        }
                                    }
                                }
                            } else {
                                $data = $checkList[$symbol]->check($orders[$symbol], $check_options);
                                if ($isecho > 1) echo $data['msg'];
                            }
                        }
                    }
                }
            } else console::log('Empty trade list');
        }

        $echo = ob_get_contents();
        ob_end_clean();                

        if ($isecho && $echo) {
            echo "\n\n";
            echo $echo;
        }

        cronReport($dbp, $scriptID, null);
        if (isStopScript($dbp, $scriptID, $scriptCode)) break;

        $waitTime = ($state=='find')?ceil(FINDTIME / $cur_count):WAITTIME;

        if (($dtime = $time + $waitTime - time()) > 0) sleep($dtime);
    }
    console::log('STOP '.$scriptID);

    $dbp->close();
?>