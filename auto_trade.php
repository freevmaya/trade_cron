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
    $p_symbols      = @$params['s'];                // symbol
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

    define('CONFIGFILE', 'data/'.$market_symbol.'_auto_trade.json');

    GLOBAL $volumes;
    
    $dbname = 'trade';
    $def_coininfo = ['profit'=>0, 'list'=>[], 'skip'=>0, 'loss_total'=>0, 'profit_total'=>0, 'loss_count'=>0, 'profit_count'=>0];

    $config = new tradeConfig();

    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';
    $dbp = new mySQLProvider('localhost', $dbname, $user, $password);

    if ($p_symbols) $symbols = explode(',', $p_symbols);
    else $symbols = json_decode(file_get_contents(PAIRFILEDATA), true);

    $scriptID = basename(__FILE__).($is_dev?'dev':'');
    $scriptCode = md5(time());
    $WAITTIME = WAITTIME;

    startScript($dbp, $scriptID, $scriptCode, $WAITTIME, '', $is_dev);
    $FDBGLogFile = (__FILE__).'.log';
    new console($is_dev);
    
    $startTime = strtotime('NOW');
    $crawlerName = $market_symbol.'Crawler';
    $crawler = new $crawlerName($symbols);

    $senderName = $market_symbol.'Sender';
    $sender = new $senderName(json_decode(file_get_contents(APIKEYPATH.'apikey_'.$market_symbol.'.json'), true));

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

        // Проверяем восходящуюю EMA, см. параметры EMAINTERVAL и MINEMASLOPE. EMAINTERVAL - число, либо "none"

        $interval = $options['MANAGER']['EMAINTERVAL'];
        if ($interval != 'none') {

            $ema    = $candles->ema($interval);
            $slope  = ($ema[count($ema) - 1] - $ema[0])/$ema[count($ema) - 1]; 
            if ($slope >= $options['MANAGER']['MINEMASLOPE'])
                $result = true;
            else $result = "SLOPE: $slope\n";

        } else $result = true;

        if (!is_string($result) && $result) {
            if (!is_string($result = $candles->buyCheck($options['MANAGER']['MACD'], 
                            floatval($options['MANAGER']['buy_macd_value']), 
                            floatval($options['MANAGER']['buy_macd_direct'])))) { 
                $result = $returnCandle?$candles:true;
            }
        }

        if (!$returnCandle) $candles->dispose();        
        return $result;
    }

    function checkPairState($crawler, $symbol, $options) {
        if ($result = checkMACD($crawler, $symbol, $options, true)) {
            if (is_string($result)) 
                return $result;
            $candles = $result;

            /*

            $volumes    = $candles->getVolumes();
            $buyVol     = $candles->getData(9);             // Список объемов покупок   
            $sellVol    = Math::suba($volumes, $buyVol);    // Список объемов продаж

            echo $buyVol[count($buyVol) - 1].' '.$sellVol[count($sellVol) - 1]."\n";

            $buy        = varavg($buyVol, -1);
            $sell       = varavg($sellVol, -1);


            $upDirect   = calcDirect($sell, $buy);
            $candles->dispose();

            $result = $upDirect >= $options['MANAGER']['VOLDIRECT'];
            if (!$result) 
                $result = "Require VOLDIRECT {$upDirect} >= {$options['MANAGER']['VOLDIRECT']}\n";
            */

            return $result;
        }

        return false;
    }

    function readFileData($symbol, $defaultData=['purchase'=>null, 'profit'=>0]) {
        $file_name = str_replace('pair', $symbol, PURCHASE_FILE);
        if (file_exists($file_name)) {
            $file_data = json_decode(file_get_contents($file_name), true);
        } else $file_data = $defaultData;
        return $file_data;
    }

    function writeFileData($symbol, $file_data) {
        $file_name = str_replace('pair', $symbol, PURCHASE_FILE);
        file_put_contents($file_name, json_encode($file_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    function readOptions($symbol) {
        GLOBAL $config;

        $trade_options  = $config->get('default_options');
        $optionsAll     = $config->get('options', []);

        if (isset($optionsAll[$symbol])) $trade_options = union($trade_options, $optionsAll[$symbol]);
        return $trade_options;
    }

    function sellPurchase($sender, $symbol, $purchase, $price=0) {
        $sell_order = null;
        $sell_order = $sender->sell($symbol, $purchase['volume'], $price); // Продаем по рынку если price == 0
        if (($price == 0) && (!($selled = ($sell_order['status'] == 'FILLED')))) {
            $sender->cancelOrder($sell_order);
            $sell_order = null;
        }
        return $sell_order;
    }

    function readConfig($config, $ext_config='') {
        $read_attempt = 10;
        while (!$config->readFile(CONFIGFILE)) {
            sleep($WAITTIME);
            if (($read_attempt = $read_attempt - 1) == 0)
                throw new Exception("Error read config file", 1);                    
        }

        // Если в параметрах передаем дополнительный файл конфигурации
        if ($ext_config) {
            if ($config_extend = json_decode(file_get_contents($ext_config), true))
               $config->union($config_extend); 
        }
    }

    console::log('START '.$scriptID);
    $tradeClass     = new Trades();
    $prevPrice      = 0;
    $checkList      = [];
    $cur_index      = 0;
    $cur_count      = count($symbols);

    $defhistory     = [];
    foreach ($symbols as $symbol) $defhistory[$symbol] = $def_coininfo;
//    $allcoinInfo    = readFileData('allcoin', ['history'=>$defhistory, 'state'=>[]]);
    $history        = readFileData('allcoin', $defhistory);

    $allprofit = 0;

    foreach ($history as $item) {
        $allprofit += $item['profit'];
    }

    echo "TOTAL PROFIT: ".sprintf(NFRM, $allprofit),"\n";

    readConfig($config, @$params['config']);

    $prev_time = 0;
    $delta_time = 0;

// Основной цикл
    while (true) {
        ob_start();

        $time   = time();
        $stime  = date(DATEFORMAT, $time);
        if ($prev_time) $delta_time = $time - $prev_time;
        if ($cur_index == 0) $prev_time = $time;

        clearstatcache();
        $file_time = filectime(CONFIGFILE);
        if ($file_time >= $time - $WAITTIME) // Если недавно изменился файл, тогда обновляем
            readConfig($config, @$params['config']);

        $general        = $config->get('general');
        $WAITTIME       = $general['WAITTIME'];


        $file_time = filectime(PAIRFILEDATA);
        if ($file_time >= $time - $WAITTIME) { // Если недавно изменился файл списка пар, тогда обновляем список
            $read_attempt = 10;
            while (!($f_symbols = json_decode(file_get_contents(PAIRFILEDATA), true))) {
                sleep($WAITTIME);
                if (($read_attempt = $read_attempt - 1) == 0)
                    throw new Exception("Error pairs file", 1);                    
            }
            $symbols   = $f_symbols;
            $cur_count = count($symbols);
            $cur_index = 0;                

            echo "REFRESH CURRENCY LIST\n";
            print_r($symbols);
            if ($cur_count == 1) sleep($WAITTIME);
        }

        $symbol         = $symbols[$cur_index];
        $coins          = explode('_', $symbol);
        $baseCur        = $coins[1];
        $trade_options  = readOptions($symbol);
        $commission     = $config->get("commission");
        $komsa          = floatval($commission[$baseCur]);
        $isecho         = isset($params['echo'])?$params['echo']:$trade_options['ECHO'];
        $sender->test   = $trade_options['MODE'] == 'TEST';

        $check_options = array_merge([
            'state'=>'buy'
        ], $trade_options);

        if (!isset($history[$symbol])) $history[$symbol] = $def_coininfo;
        else if ($history[$symbol]['skip'] > 0) $history[$symbol]['skip'] = max($history[$symbol]['skip'] - $delta_time, 0);

        $histsymb = $history[$symbol];
        $isPurchase = count($histsymb['list']) > 0;

        $skip = ($histsymb['skip'] > 0) || ($histsymb['profit'] < 0);
        $all_skip = true;
/*
        if (!$skip && (($trade_options['INGNORELOSS'] == 0) && ($histsymb['loss_total'] > 0))) {
            if ($skip = $histsymb['profit_total']/pow($histsymb['loss_total'] * 2, 1.4) < 1) {
                echo "Lots of losses\n";
                sleep($WAITTIME);
            }
        }
*/        

        if (($isecho > 1) && $skip) echo "SKIP {$history[$symbol]['skip']} SEC\n";

        if (!$isPurchase && !$skip && 
            ($trade_options['MANAGER']['no_macd'] == 0) && is_string($result = checkPairState($crawler, $symbol, $trade_options))) {

            if ($isecho > 1) {
                echo "MACD and VOLUMES does not correspond to the condition\n{$result}";
            }
            $history[$symbol]['skip'] = $general['SKIPTIME'];
            $all_skip = false;
        } else if ($isPurchase || (!$skip)) {
            // Если есть покупки или нет пропуска
            $all_skip = false;

            if ($trades = $crawler->getTradeList([$symbol])) 
                $orders = $crawler->getOrderList([$symbol]);

            if ($trades && $orders) {

                if (isset($trades['error'])) {
                    console::log($trades['error']);
                    sleep(WAITAFTERERROR);
                } else {
                    //$sender->useServerTime();

                    $tradeClass->addHistory($trades);

                    // Для контроля продаж
                    $prices = $tradeClass->lastPrice($symbol);
                    foreach ($histsymb['list'] as $i=>$purchase) {
                        $filled = true;
                        if ($isSaleOrder = isset($purchase['sale_order'])) {
                            $state_order = $sender->checkOrder($purchase['order']);
                            $filled = (@$state_order['status']) == 'FILLED';
                        } else if (!$sender->test) {
                            $state_order = $sender->checkOrder($purchase['order']);
                            $filled = (@$state_order['status']) == 'FILLED';
                        }

                        if ($filled) {
                            $order = $purchase['order'];

                            $profit = ($purchase['take_profit'] - $purchase['price']) * $purchase['volume'];
                            $profit = $profit - $profit * $komsa;
                            $loss = ($purchase['price'] - $purchase['stop_loss']) * $purchase['volume'];
                            $loss = $loss + $loss * $komsa;

                            if ($isecho > 1) 
                                echo "CHECK take profit: ".sprintf(NFRM, $purchase['take_profit']).
                                        ", stop loss: ".sprintf(NFRM, $purchase['stop_loss']).", cur buy price: {$prices['buy']}\n";
                            if ($tradeClass->isPriceMore($symbol, $purchase['time'], $purchase['take_profit'])) {

                                if (isset($purchase['stoploss_order'])) {
                                    $result = $sender->cancelOrder($purchase['stoploss_order']);
                                }
                                if ($isSaleOrder || sellPurchase($sender, $symbol, $purchase)) {
                                    echo "TAKE PROFIT, price: {$purchase['take_profit']}, PROFIT: {$profit}\n";

                                    unset($history[$symbol]['list'][$i]);
                                    $history[$symbol]['profit'] += $profit;
                                    $history[$symbol]['profit_total'] += $profit;
                                    $history[$symbol]['profit_count']++;

                                    if (!$sender->test) {
                                        $vol = $purchase['take_profit'] * $purchase['volume'];
                                        $sender->addBalance($baseCur, $vol - $vol * $komsa);
                                    }
                                }

                            } else if ($tradeClass->isPriceBelow($symbol, $purchase['time'], $purchase['stop_loss'])) {
                                if ($trade_options['INGNORELOSS'] == 1) {
                                    if ($isecho > 1) echo "INGNORELOSS\n";
                                    $history[$symbol]['skip'] = $trade_options['SKIPIGNORELOSS'];
                                } else {
                                    if ($isSaleOrder) {
                                        $result = $sender->cancelOrder($purchase['sale_order']);
                                        sellPurchase($sender, $symbol, $purchase);
                                    }

                                    echo "STOP LOSS orderId: {$order['orderId']}, price: {$purchase['stop_loss']}, LOSS {$loss}\n";
                                    unset($history[$symbol]['list'][$i]);

                                    $history[$symbol]['profit'] -= $loss;
                                    $history[$symbol]['loss_total'] += $loss;
                                    $history[$symbol]['loss_count']++;
                                    if (!$sender->test) {
                                        $vol = $purchase['stop_loss'] * $purchase['volume'];
                                        $sender->addBalance($baseCur, $vol - $vol * $komsa);
                                    }

                                    $history[$symbol]['skip'] = $trade_options['SKIPAFTERLOSS'] * $history[$symbol]['loss_count']; // Если
                                }
                            }
                        }
                    }

                    $countPurchase = count($histsymb['list']);

                    // Если тестируем или недостаточно покупок этого символа
                    if (!$skip && ($countPurchase < $trade_options['MAXPURCHASESYMBOL'])) {
                        if (!isset($checkList[$symbol])) 
                            $checkList[$symbol] = new checkPair($symbol, $tradeClass);
                        else {
                            if ($istrade) {
                                $data = $checkList[$symbol]->check($orders[$symbol], $check_options);
                                if ($isecho > 1) echo $data['msg'];

                                if ($data['state'] == 'buy') {
                                    $balance = $sender->balance($baseCur);
                                    if (($buyvol = $sender->volumeFromBuy($symbol, $data['price'], 
                                                    floatval($trade_options['BUYMINVOLS']), $komsa * 2)) > 0) { 
                                        $require = $buyvol * $data['price'];

                                        if (!$sender->test && ($balance < $require)) {
                                            echo "Not enough balance. Require: {$require}, available: {$balance}\n";
                                        } else {

                                            $take_profit = roundOff($data['price'] + 
                                                        $data['price'] * floatval($trade_options['MANAGER']['min_percent']) + 
                                                        $data['price'] * $komsa * 2, $trade_options['PRICEROUNDOFF']);

                                            $stop_loss = roundOff($data['left_price'] - $data['left_price'] * floatval($trade_options['MANAGER']['stop_loss_indent']), $trade_options['PRICEROUNDOFF']) ;

                                            $order = $sender->buy($symbol, $buyvol, $data['price']);//DEV

                                            if ($order && !isset($order['code'])) {
                                                $purchase = ['date'=>$stime, 'time'=>$sender->serverTime(), 'symbol'=>$symbol, 
                                                        'take_profit'=>$take_profit, 'price'=>$data['price'], 'stop_loss'=>$stop_loss,
                                                        'volume'=>$order['executedQty'], 'order'=>$order]; 
                                                echo "***BUY***\n";

                                                $order_str = json_encode($order);
                                                echo "take_profit: {$take_profit}, price: {$data['price']}, stop_loss: {$stop_loss}, volume: {$order['executedQty']}, order: {$order_str}\n";
                                                echo $data['msg'];

                                                if (!$sender->test) {
                                                    sleep(1);

                                                    if ($trade_options['MANAGER']['STOPLOSSORDER'] == 1) {
                                                        // Если в опциях включено STOPLOSSORDER тогда сразу выставляем лимитный ордер на продажду по цене stop_loss
                                                        $state_order = $sender->checkOrder($order);
                                                        if ((@$state_order['status']) == 'FILLED') {
                                                            if ($sale_order = sellPurchase($sender, $symbol, $purchase, $stop_loss)) {
                                                                $purchase['stoploss_order'] = $sale_order;
                                                            }
                                                        }
                                                    } else if ($trade_options['MANAGER']['TAKEPROFITORDER'] == 1) {
                                                        // Если в опциях включено TAKEPROFITORDER тогда сразу выставляем лимитный ордер на продажду по цене take_profit
                                                        if ($sale_order = sellPurchase($sender, $symbol, $purchase, $take_profit)) {
                                                            $purchase['sale_order'] = $sale_order;
                                                        }
                                                    }
                                                }

                                                $history[$symbol]['list'][] = $purchase;
                                                if (!$sender->test)
                                                    $sender->addBalance($baseCur, -$data['price'] * $order['executedQty']);

                                            } else throw new Exception("Unknown error when creating an order buy", 1);
                                        }
                                    } else echo "Does not comply with the rule of trade\n";
                                } 
                            } else {
                                $data = $checkList[$symbol]->check($orders[$symbol], $check_options);
                                if ($isecho > 1) echo $data['msg'];
                            }
                        }

                    } else if ($isecho > 1) echo "skip trade section, SKIP: {$skip} OR {$countPurchase} < {$trade_options['MAXPURCHASESYMBOL']}\n";
                    writeFileData('allcoin', $history);
                }
            } else console::log("Empty trade or order list");

        }

        $cur_index = ($cur_index + 1) % $cur_count;

        $echo = ob_get_contents();
        ob_end_clean();

        if ($isecho && $echo) {
            echo "\n---{$stime}---{$symbol}-----------------\n";
            echo $echo;
        }

        if (!$skip || $all_skip) {

            cronReport($dbp, $scriptID, null);
            if (isStopScript($dbp, $scriptID, $scriptCode)) break;
            if (($dtime = $time + $WAITTIME - time()) > 0) sleep($dtime);
        }
    }
    console::log('STOP '.$scriptID);

    $dbp->close();
?>