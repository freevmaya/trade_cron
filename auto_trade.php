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
    define('PURCHASE_FILE', 'data/auto_tpair.json');
    define('DBPREF', '');
    define('DATEFORMAT', 'Y-m-d H:i:s');
    define('MAINDIR', dirname(__FILE__).'/');
    define('NFRM', "%01.8f");
    define('NFRMS', "%01.2f");
    define('MAXWAITTRADE', 60);
    define('PAIRFILEDATA', 'data/auto_trade_pairs.json');
    define('DEFAULTMARKET', 'binance');

    if (!isset($argv[1])) {
        echo "Name market no found\n";
        exit; 
    }

    $params = [];
    for ($i=1;$i<count($argv);$i++) {
        $a = explode('=', $argv[$i]);
        $params[$a[0]] = isset($a[1])?$a[1]:true;
    }

    $market_symbol  = isset($params['m'])?$params['m']:DEFAULTMARKET;                 // Маркет
    $p_symbols      = @$params['s'];                // symbol
    $istrade        = isset($params['td'])?$params['td']:false;

    include_once(MAINDIR.'modules/timeObject.php');
    include_once(MAINDIR.'include/utils.php');
    include_once(INCLUDE_PATH.'fdbg.php');
    include_once(INCLUDE_PATH.'events.php');
    include_once(MAINDIR.'include/db/mySQLProvider.php');
    include_once(MAINDIR.'include/console.php');
    include_once(MAINDIR.'include/queue.php');
    include_once(MAINDIR.'include/restClient.php');
    include_once(MAINDIR.'include/tradeView.php');

    include_once(MAINDIR.'include/glass/trades.php');
    include_once(MAINDIR.'include/glass/glass.php');
    include_once(MAINDIR.'include/glass/levels.php');
    include_once(MAINDIR.'include/glass/tradeConfig.php');
//    include_once(MAINDIR.'include/glass/orderHistory.php');
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
    else $symbols = null;
//    else $symbols = json_decode(file_get_contents(PAIRFILEDATA), true);

    $scriptID = basename(__FILE__).($is_dev?'dev':'');
    $scriptCode = md5(time());
    $WAITTIME = WAITTIME;
    $FDBGLogFile = (__FILE__).'.log';
    new console($is_dev);
    
    $startTime = strtotime('NOW');

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

        //print_r($trade_options);
        return $trade_options;
    }

    function sellPurchase($sender, $symbol, $purchase, $price=0) {
        $sell_order = null;
        $sell_order = $sender->sell($symbol, $purchase['volume'], $price); // Продаем по рынку если price == 0
        if (isset($sell_order['code'])) {
            echo $sell_order['msg'];
            $sell_order = null;
        } else if (($price == 0) && (!($selled = ($sell_order['status'] == 'FILLED')))) {
            $sender->cancelOrder($sell_order);
            $sell_order = null;
        }
        return $sell_order;
    }

    function getMinPrice($purchaseList) {
        $minPrice = 1000000;
        foreach ($purchaseList as $item) $minPrice = min($minPrice, $item['price']); 
        return $minPrice;
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
            if ($config_extend = json_decode(file_get_contents($ext_config), true)) {
                $config->union($config_extend); 
//                print_r($config->get('options'));
            }
        }
    }

    function totalProfit($history, $baseList=["BNB"]) {
        GLOBAL $sender;
        $allprofit = [];

        if ($history) {
            foreach ($history as $pair=>$item) 
                if ($pair && (strpos($pair, '_') !== false)) {
                    $ap = explode('_', $pair); $pix = $ap[1];
                    if (!isset($allprofit[$pix])) $allprofit[$pix] = 0;
                    $allprofit[$pix] += $item['profit'];
                }
        }

        $sender->resetPrices();
        $result = "TOTAL PROFIT: ".print_r($allprofit, true)."\n";

        foreach ($baseList as $currency)
            $result .= "BALANCE TO {$currency}: ".sprintf(NFRM, $sender->calcBalance($currency))."\n"; 
        
        return $result;
    }

    // Паническая продажа символов
    function panikSell($sender, $symbol, $purchaseList) {
        foreach ($purchaseList as $purcashe) {

            //Если есть лимитный ордер на продажу, тогда отменяем его
            if (isset($purcashe['sale_order'])) $sender->cancelOrder($purcashe['sale_order']);

            $sell_order = sellPurchase($sender, $symbol, $purcashe);
            echo "-----PANIK SELL {$symbol}-----\n";
        }
    }

    $senderName = $market_symbol.'Sender';
    $sender = new $senderName(json_decode(file_get_contents(APIKEYPATH.'apikey_'.$market_symbol.'.json'), true));

    $account = $sender->getAccount();
    
    $tradeClass     = new Trades();
    $prevPrice      = 0;
    $checkList      = [];

    readConfig($config, @$params['config']);
    $general        = $config->get('general');

    $crawlerName = $market_symbol.'Crawler';
    $crawler    = new $crawlerName([]);//$symbols);
    $tradeView  = new tradeView();
    $gsdata     = $general['GSYMBOL'];

    if (!$symbols) {
        $symbols = [];
        if ($general['CHECKFORECAST']['SYMBOL']) 
            $data = $tradeView->recommend('BINANCE', $general['CHECKFORECAST']['SYMBOL'], $general['CHECKFORECAST']['PERIOD']); 
        else $data = null;

        if (!$data || ($data['Recommend.All'] > 0)) { // Если хороший прогноз для биткоина, на ближайшие 4 часа
            $topList = $crawler->getTop($general['ASSET'], $general['MAXSYMBOLS'], $general['STEPSIZE']); // Выбираем лучшие символы

            foreach ($topList as $symbol) { 
                $data = $tradeView->recommend('BINANCE', strtoupper(str_replace('_', '', $symbol)), $general['RECOMINTERVAL']);
                if ($data['Recommend.All'] > 0) $symbols[] = $symbol; // Если прогноз для символа хороший
            }
        } else {
            echo "Bad forecast BTC\n";
            exit;
        }
    }

    $cur_index      = 0;
    $cur_count      = count($symbols);
    if ($cur_count == 0) {
        echo "No trade symbols\n";
        exit;
    }

    $defhistory     = [];
    foreach ($symbols as $symbol) $defhistory[$symbol] = $def_coininfo;
//    $allcoinInfo    = readFileData('allcoin', ['history'=>$defhistory, 'state'=>[]]);
    $history        = readFileData('rade', $defhistory);

    if ($history) {
        foreach ($history as $pair=>$item) {
            if ((count($item['list']) > 0) && (array_search($pair, $symbols) === false)) $symbols[] = $pair;
        }
    }

    $prev_time = 0;
    $delta_time = 0;
    $gcandle = null;
    print_r($symbols);
    echo totalProfit($history, $general['ASSET']);

    startScript($dbp, $scriptID, $scriptCode, $WAITTIME, '', $is_dev);
    console::log('START '.$scriptID);

/*
    print_r($sender->exchangeInfo('ONT_BTC'));

    if ($order = $sender->buy('ONT_BTC', 4)) {
        $sender->cancelOrder($order);
        echo "OK RUN BINANCE BUY ORDER!!!!!\n";
    }
*/    

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
        if ($general['PANIK']) {

            foreach ($history as $pair=>$item) {
                if ($istrade && (count($item['list']) > 0)) panikSell($sedner, $pair, $item['list']);
                $item['list'] = [];
            }

            echo "END PANIK SELL\n";
            writeFileData('rade', $history);
            break;
        }
        $WAITTIME       = $general['WAITTIME'];

/*
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
*/        

        $symbol         = $symbols[$cur_index];
        $coins          = explode('_', $symbol);
        $baseCur        = $coins[1];
        $trade_options  = readOptions($symbol);
        $commission     = $config->get("commission");
        $komsa          = floatval($commission[$baseCur]);
        $isecho         = isset($params['echo'])?$params['echo']:$trade_options['ECHO'];
        $sender->test   = $trade_options['MODE'] == 'TEST';
        $GPriceDirect   = 0;

        if (($cur_index == 0) && $gsdata) {
            if (!$gcandle) $gcandle = new Candles($crawler, $gsdata['NAME'], 
                                                $gsdata['CANDLEINTERVAL'] * 60, $time, 
                                    $time - 60 * $gsdata['CANDLEINTERVAL'] * $gsdata['CANDLECOUNT']);
            $gcandle->update($time);

            $gc_data = $gcandle->getData();
            $candle = $gc_data[count($gc_data) - 1];
            $GPriceDirect = ($candle[4] - $candle[1]) / $candle[4] * 100;
        }

        if (!isset($history[$symbol])) $history[$symbol] = $def_coininfo;
        else if ($history[$symbol]['skip'] > 0) $history[$symbol]['skip'] = max($history[$symbol]['skip'] - $delta_time, 0);

        $histsymb = $history[$symbol];
        $isPurchase = count($histsymb['list']) > 0;

        $skip = ($histsymb['skip'] > 0);
        if (($histsymb['profit'] < 0) && isset($history[$symbol]['last_stop_loss'])) {
            $delta_time = $time - strtotime($history[$symbol]['last_stop_loss']);
            if ($skip = $delta_time <= $general['LASTLOSSWAIT']) {
                if ($isecho > 1) echo "LAST LOSS {$history[$symbol]['last_stop_loss']}\n";
            }
        }

        if (($isecho > 1) && $skip) echo "SKIP {$history[$symbol]['skip']} SEC\n";

        if (!isset($checkList[$symbol])) $checkList[$symbol] = new checkPair($symbol, $tradeClass, $crawler, $trade_options);

        if (!$isPurchase && !$skip && is_string($result = $checkList[$symbol]->checkMACD_BB(false, $komsa * 2))) {

            if ($isecho > 1) {
                echo "MACD, Bollinger bands does not correspond to the condition\n{$result}";
            }
            $history[$symbol]['skip'] = $general['SKIPTIME'];
        } else if ($isPurchase || (!$skip)) {
            // Если есть покупки или нет пропуска

            if ($trades = $crawler->getTradeList([$symbol])) 
                $orders = $crawler->getOrderList([$symbol]);

            if ($trades && $orders) {

                if (isset($trades['error'])) {
                    console::log($trades['error']);
                    sleep(WAITAFTERERROR);
                } else {
                    //$sender->useServerTime();

                    $tradeClass->addHistory($trades);

                    $prices = $tradeClass->lastPrice($symbol);

                    // Блок продаж
                    foreach ($histsymb['list'] as $i=>$purchase) {
                        $order = $purchase['order'];
                        $filled = true;                        

                        if (!isset($purchase['verified'])) {
                            if (!$purchase['test']) {
                                    
                                // Проверяем исполение ордера на покупку
                                $state_order    = $sender->checkOrder($purchase['order']);
                                $status         = @$state_order['status'];
                                if ($filled = ($status == 'FILLED')) {
                                    if (($trade_options['MANAGER']['STOPLOSSORDER'] == 1) && !isset($purchase['stoploss_order'])) {

                                        // Если в опциях включено STOPLOSSORDER и нет ордера на продажу по цене stop_loss
                                        // тогда сразу выставляем лимитный ордер на продажду по цене stop_loss

                                        if ($sale_order = sellPurchase($sender, $symbol, $purchase, $purchase['stop_loss'])) {
                                            $history[$symbol]['list'][$i]['stoploss_order'] = $sale_order;
                                        }
                                    } else if (($trade_options['MANAGER']['TAKEPROFITORDER'] == 1) && !isset($purchase['sale_order'])) {

                                        // Если в опциях включено TAKEPROFITORDER и нет ордера на продажу по цене take_profit
                                        // тогда сразу выставляем лимитный ордер на продажду по цене take_profit

                                        if ($sale_order = sellPurchase($sender, $symbol, $purchase, $purchase['take_profit'])) {
                                            $history[$symbol]['list'][$i]['sale_order'] = $sale_order;
                                        }
                                    }

                                    //$sender->addBalance($baseCur, -$purchase['price'] * $order['executedQty']);
                                    $sender->resetAccount();
                                    $history[$symbol]['list'][$i]['verified'] = 1;
                                } else {
                                    // Если ордер на покупку еще не сработал
                                    $deltaTime = round(($sender->serverTime() - $purchase['time']) / 1000);
                                    if ($deltaTime > $trade_options['BUYORDERLIVE']) {
                                        if ($status == "PARTIALLY_FILLED") { // Если частично исполнен
                                            /*
                                            echo "ERASE PURCHASE, ORDER STATUS: '{$status}'\n";
                                            if (!isset($history[$symbol]['past_parts'])) $history[$symbol]['past_parts'] = [];
                                            $history[$symbol]['past_parts'][] = $purchase;
                                            unset($history[$symbol]['list'][$i]);
                                            */
                                            $history[$symbol]['skip'] = $general['SKIPTIME_CHECK'];
                                            $sender->resetAccount();
                                        } else {
                                            if ($sender->cancelOrder($purchase['order'])) {
                                                echo "CANCEL ORDER\n";
                                                print_r($purchase['order']);
                                                array_splice($history[$symbol]['list'], $i, 1);
                                            }
                                        }
                                    }
                                }
                            } else {
                                $history[$symbol]['list'][$i]['sale_order'] = $trade_options['MANAGER']['TAKEPROFITORDER'] == 1;
                                $history[$symbol]['list'][$i]['verified']   = 1;
                            }
                        }

                        if ($filled && !$skip) { // Если ордер на покупку уже сработал тогда начинаем ослеживать момент продажи

                            $profit = ($purchase['take_profit'] - $purchase['price']) * $purchase['volume'];
                            $profit = $profit - $profit * $komsa;
                            $loss = ($purchase['price'] - $purchase['stop_loss']) * $purchase['volume'];
                            $loss = $loss + $loss * $komsa;

                            // Наличие лимитного ордера на продажу этой покупки
                            $isSaleOrder = (@$purchase['sale_order']) && ($purchase['test'] || (@$purchase['sale_order']["orderId"])); 

                            if ($isecho > 1) 
                                echo "CHECK take profit: ".sprintf(NFRM, $purchase['take_profit']).
                                        ", stop loss: ".sprintf(NFRM, $purchase['stop_loss']).", cur buy price: {$prices['buy']}\n";

                            // tp_area - Индикатор того что цена продаж уже зашла в TAKEPROFIT зону
                            $buy_trade = '';
                            if (isset($history[$symbol]['list'][$i]['tp_area']) || 
                                $tradeClass->isPriceMore($symbol, $purchase['time'], $purchase['take_profit'], $buy_trade)) {

                                if (!$isSaleOrder) {// Если нет лимитного ордера на продажу, тогда отслеживаем момент продажи
                                    $data = $checkList[$symbol]->glassCheck($orders[$symbol]);
                                    if ($isSell = $data['isSell']) {
                                        $profit = ($prices['buy'] - $purchase['price']) * $purchase['volume'];
                                        $profit = $profit - $profit * $komsa;                                    
                                        echo $data['msg']; 
                                    }
                                } else if (!$purchase['test']) {
                                    $state_order = $sender->checkOrder($purchase['sale_order']);
                                    $st = @$state_order['status'];
                                    
                                    $isSell      = ($st == 'FILLED') || ($st == 'CANCELED') || ($state_order == null);
                                } else $isSell = true;

                                if ($isSell) { // Если продано или можно продавать
                                    if (isset($purchase['stoploss_order']) && !$purchase['test']) { // Отменяем стоп-лосс ордер если он есть
                                        $result = $sender->cancelOrder($purchase['stoploss_order']);
                                    }
                                    if ($isSaleOrder || sellPurchase($sender, $symbol, $purchase)) { // Если нет ордера продажи тогда продаем и очищаем информацию о попупке
                                        echo "SELL PURCHASE IN: {$buy_trade}\n";
                                        echo "TAKE PROFIT, price: {$prices['buy']}, PROFIT: {$profit}\n";

                                        array_splice($history[$symbol]['list'], $i, 1);

                                        $history[$symbol]['profit'] += $profit;
                                        $history[$symbol]['profit_total'] += $profit;
                                        $history[$symbol]['profit_count']++;

                                        if (!$purchase['test']) {
                                            $vol = $purchase['take_profit'] * $purchase['volume'];
                                            $sender->resetAccount();

                                            // Проверяем рекомендацию о покупке этого символа
                                            $data = $tradeView->recommend('BINANCE', strtoupper(str_replace('_', '', $symbol)), $general['RECOMINTERVAL']);
                                            if ($data['Recommend.All'] > 0) array_splice($symbols, $cur_index, 1); // Если нет рекомендации тога удаляем из списка

//                                            $sender->addBalance($baseCur, $vol - $vol * $komsa);
                                        }
                                        echo totalProfit($history, $general['ASSET']);
                                    }
                                } else {
                                    $history[$symbol]['list'][$i]['tp_area'] = 1;
                                    $history[$symbol]['skip'] = $general['SKIPTIME_CHECK'];
                                }

                            } else if ($tradeClass->isPriceBelow($symbol, $purchase['time'], $purchase['stop_loss'])) {
                                if ($trade_options['INGNORELOSS'] == 1) {
                                    if ($isecho > 1) echo "INGNORELOSS\n";
                                    $history[$symbol]['skip'] = $trade_options['SKIPIGNORELOSS'];
                                } else {
                                    $data = $checkList[$symbol]->glassCheck($orders[$symbol]);

                                    if ($isecho > 1) echo $data['msg'];
                                    if ($data['isSell']) {

                                        if ($isSaleOrder && !$purchase['test']) {
                                            if ($sender->cancelOrder($purchase['sale_order'])) {
                                                $sell_order = sellPurchase($sender, $symbol, $purchase);
                                                print_r($sell_order);
                                            }
                                        } else $sell_order = true;

                                        if ($sell_order) {
                                            if (is_object($sell_order)) {
                                                $price = $sell_order['price'];
                                                $loss = $purchase['base_volume'] - $sell_order['executedQty'] * $price;
                                            } else $price = $purchase['stop_loss'];

                                            echo "STOP LOSS orderId: {$order['orderId']}, price: {$price}, LOSS {$loss}\n";
                                            echo $data['msg'];

                                            array_splice($history[$symbol]['list'], $i, 1);

                                            $history[$symbol]['profit'] -= $loss;
                                            $history[$symbol]['loss_total'] += $loss;
                                            $history[$symbol]['loss_count']++;
                                            $history[$symbol]['last_stop_loss'] = $stime;
                                            if (!$purchase['test']) $sender->resetAccount();

                                            echo totalProfit($history, $general['ASSET']);
                                        } else echo "FAIL STOP LOSS!!!";

                                        $history[$symbol]['skip'] = $trade_options['SKIPAFTERLOSS'] * $history[$symbol]['loss_count']; // Если
                                    }
                                }
                            }
                        }
                    }

                    $countPurchase = count($histsymb['list']);
                    // Блок покупок
                    // Если тестируем или недостаточно покупок этого символа
                    if (!$skip && $trade_options['CANBUY'] && (($countPurchase < $trade_options['MAXPURCHASESYMBOL']) || !$istrade)) {
                        $is_buy = $istrade && (!$gsdata || ($GPriceDirect >= $gsdata['MINDIRECT'])); // Если основная пара, BTCUSD в плюсе 
                        if ($is_buy) {
                            $buy_volume      = floatval($trade_options['BUYMINVOLS']);
                            $balance         = $sender->balance($baseCur);
                            $use_percent     = 1 - $balance/$sender->calcBalance($baseCur); // Процент использования депозита
                            $use_require     = $trade_options['USEDEPOSIT'][$countPurchase];

                            $is_buy = $use_percent < $use_require; 
                            if ($countPurchase > 0) {
                                // Если цена просела ниже REBUYDRAWDOWN - процент просадки, то определяем возможность дозакупки монеты

                                $min_price = getMinPrice($histsymb['list']);
                                $is_buy = $is_buy && ($min_price - $min_price * $trade_options['REBUYDRAWDOWN'] > $prices['sell']);
                                $buy_volume = $histsymb['list'][0]['base_volume'] * 1.5; // Увеличиваем объем дозакупа в 1.5 раз
                            }

                            if (!$is_buy) {
                                if ($isecho > 1) echo "DEPOSIT USE {$use_percent}, REQUIRE LESS {$use_require}\n";
                                $history[$symbol]['skip'] = $general['SKIPTIME'];
                            }

                            $data = $checkList[$symbol]->glassCheck($orders[$symbol]);
                            if ($isecho > 1) echo $data['msg'];
                            $is_buy = $is_buy && $data['isBuy'];

                            if ($is_buy) {
                                if (($buyvol = $sender->volumeFromBuy($symbol, $data['price'], $buy_volume, $komsa * 2)) > 0) { 
                                    $require = $buyvol * $data['price'];

                                    if (!$sender->test && ($balance < $require)) {// && ($balance < $trade_options['MANAGER']['reserve'])) {
                                        echo "Not enough balance. Require: {$require}, available: {$balance}\n";
                                        $history[$symbol]['skip'] = $general['SKIPTIME'];
                                    } else {

                                        // Если у нас уже есть покупка
                                        if ($countPurchase > 0) {
                                            $lastpur    = $histsymb['list'][0];
                                            $allVolume  = $lastpur['volume'] + $buyvol;         // Совокупный объем покупки
                                            $allRequire    = $lastpur['base_volume'] + $require;   // Совокупный объем покупки в базовой валюте

                                            //Взвешанная средняя цена для расчета цены продажи
                                            $data_price  = ($lastpur['price'] * $lastpur['volume'] + $data['price'] * $buyvol) / $allVolume;

                                            // Если есть ордер продажи тогда отменяем его
                                            if (isset($lastpur['sale_order']) && !$sender->cancelOrder($lastpur['sale_order'])) {
                                                echo "ERROR CANCEL SALE ORDER\n";
                                                print_r($lastpur['sale_order']);
                                            }
                                            array_splice($history[$symbol]['list'], 0, 1); // Стираем данные о старом ордере продажи
                                        } else {
                                            $data_price  = $data['price'];
                                            $allVolume   = $buyvol;
                                            $allRequire  = $require;
                                        }

                                        $tmp_price = $data_price + $data_price * (floatval($trade_options['MANAGER']['min_percent']) + $komsa * 2);

                                        if (($trade_options['MANAGER']['take_profit_bb'] != 0) && ($lastBB = $checkList[$symbol]->getLastBBChannel())) {
                                            $tmp_price = max($lastBB[1] + $lastBB[1] * $trade_options['MANAGER']['take_profit_bb'], $tmp_price);
                                        }

                                        $take_profit = $sender->roundPrice($symbol, $tmp_price);
                                        $stop_loss = $sender->roundPrice($symbol, $data_price - $data_price * 
                                                    floatval($trade_options['MANAGER']['stop_loss_indent'])) ;

                                        $order = $sender->buy($symbol, $buyvol, $data['price']);//DEV

                                        if ($order && !isset($order['code'])) {
                                            $purchase = ['date'=>$stime, 'time'=>$sender->serverTime(), 'symbol'=>$symbol, 
                                                    'take_profit'=>$take_profit, 'price'=>$data_price, 'stop_loss'=>$stop_loss,
                                                    'base_volume'=>$allRequire, 'volume'=>$allVolume, 'order'=>$order, 'test'=>$sender->test];
                                            echo "\n\n---------BUY----------\n";
                                            echo json_encode($purchase)."\n";
                                            echo "VOLUMES: [".implode(',', $checkList[$symbol]->lastVolumes())."]\n";
                                            echo $data['msg'];

                                            $history[$symbol]['list'][] = $purchase;

                                        } else {
                                            if ($order['code'] == -2010) {
                                                $history[$symbol]['skip'] = $general['SKIPTIME'];
                                                echo $order['msg'];
                                            } else echo "Error when creating an order buy, result: ".print_r($order, true)."\n";
                                        }
                                    }
                                } else if ($isecho > 1) echo "Does not comply with the rule of trade\n";
                            } else if ($isecho > 1) echo "Does not comply with the rule of trade\n"; 
                        } else {
                            if ($isecho > 1) {
                                if ($gsdata) echo "GPriceDirect: {$GPriceDirect}<{$gsdata['MINDIRECT']}\n";
                                $data = $checkList[$symbol]->glassCheck($orders[$symbol]);
                                echo "VOLUMES: [".implode(',', $checkList[$symbol]->lastVolumes())."]\n";
                                echo $data['msg'];
                            }
                        }
                    } else if ($isecho > 1) echo "skip buy section, SKIP: {$skip} OR Count purchase: {$countPurchase} < MAXPURCHASESYMBOL: {$trade_options['MAXPURCHASESYMBOL']}\n";
                }
            } else console::log("Empty trade or order list");

        }
        writeFileData('rade', $history);

        $all_skip = 0;
        if ($cur_index==$cur_count - 1) { // Под конец перечисляния всех символов, определяем необходимость задержки
            for ($i=0; $i<$cur_count;$i++)
                if ($history[$symbols[$i]]['skip'] > 0) {
                    $all_skip++;   
                }
        }
        $cur_index = ($cur_index + 1) % $cur_count;

        $echo = ob_get_contents();
        ob_end_clean();

        if ($isecho && $echo) {
            echo "\n---{$stime}---{$symbol}-----------------\n";
            echo $echo;
        }

        if (!$skip || ($all_skip == $cur_count)) {

            cronReport($dbp, $scriptID, null);
            if (isStopScript($dbp, $scriptID, $scriptCode)) break;
            if (($dtime = $time + $WAITTIME - time()) > 0) sleep($dtime);
        }
    }
    console::log('STOP '.$scriptID);

    $dbp->close();
?>