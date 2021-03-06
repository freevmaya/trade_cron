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
    $symbols        = explode(',', $params['s']);   // Пары
    $isecho         = $params['e'];                 // echo
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
    include_once(MAINDIR.'include/glass/sender/baseSender.php');
    include_once(MAINDIR.'include/glass/sender/'.$market_symbol.'Sender.php');
    include_once(MAINDIR.'include/crawlers/baseCrawler.php');
    include_once(MAINDIR.'include/crawlers/'.$market_symbol.'Crawler.php');   

    GLOBAL $volumes;
    
    $dbname = 'trade';
    $config = new tradeConfig('data/'.$market_symbol.'_trade.json');

    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';
    $dbp = new mySQLProvider('localhost', $dbname, $user, $password);

    $scriptID = basename(__FILE__).implode("_", $symbols);
    $scriptCode = md5(time());

    startScript($dbp, $scriptID, $scriptCode, WAITTIME, '', $is_dev);
    $FDBGLogFile = (__FILE__).'.log';
    new console($is_dev);
    
    $startTime = strtotime('NOW');
    $crawlerName = $market_symbol.'Crawler';
    $crawler = new $crawlerName($symbols);

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

    console::log('START '.$scriptID);

    $tradeClass     = new Trades();
    $prevPrice      = 0;
    $checkList = [];
    $komsa = 0.002;

    while (true) {
        $time = time();
        if ($trades = $crawler->getTradeList()) {
            $orders = $crawler->getOrderList();

            if (isset($trades['error'])) {
                console::log($trades['error']);
                sleep(WAITAFTERERROR);
            } else {

                $tradeClass->addHistory($trades);
                $echo = '';
                foreach ($trades as $symbol=>$list) {

                    if (!isset($checkList[$symbol])) 
                        $checkList[$symbol] = new checkPair($symbol, $tradeClass);
                    else {
                        if ($istrade) {

                            $file_name = str_replace('pair', $symbol, PURCHASE_FILE);
                            if (file_exists($file_name)) $file_data = json_decode(file_get_contents($file_name), true);
                            else $file_data = ['purchase'=>null, 'profit'=>0];

                            $options = [
                                'state'=>$file_data['purchase']?'buy':'sell'
                            ];

                            $data = $checkList[$symbol]->check($orders[$symbol], $options);

                            if (!$file_data['purchase']) {
                                if ($data['state'] == 'buy') {
                                    $file_data['purchase'] = ['symbol'=>$symbol, 'price'=>$data['price']]; 
                                    $echo .= date(DATEFORMAT, $time)." BUY!!!\n";
                                    $echo .= $data['msg'];
                                    file_put_contents($file_name, json_encode($file_data));
                                }
                            } else {
                                if ($data['state'] == 'sell') {
                                    $t_prefit = $data['price'] - $data['price'] * $komsa - $file_data['purchase']['price'];
                                    if ($t_prefit >= 0) {
                                        $file_data['profit'] += $t_prefit;
                                        $file_data['purchase'] = null;
                                        $echo .= date(DATEFORMAT, $time)." SELL PROFIT: {$file_data['profit']}\n";
                                        $echo .= $data['msg'];
                                        file_put_contents($file_name, json_encode($file_data));
                                    }
                                }
                            }
                        } else {
                            $data = $checkList[$symbol]->check($orders[$symbol], ['state'=>'buy']);
                            $echo .= $data['msg'];
                        }
                    }
                }

                if ($isecho && $echo) {
                    echo "\n\n";
                    echo $echo;
                }
                $echo = '';
            }
            cronReport($dbp, $scriptID, ['is_response'=>is_array($trades)]);
        } else console::log('Empty trade list');

        if (isStopScript($dbp, $scriptID, $scriptCode)) break;
        if (($dtime = $time + WAITTIME - time()) > 0) sleep($dtime);
    }
    console::log('STOP '.$scriptID);

    $dbp->close();
?>