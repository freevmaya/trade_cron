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
    $symbols = explode(',', isset($argv[2])?$argv[2]:'GAS_BTC');
    $isecho = isset($argv[3]);

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

    console::log('START '.$scriptID);

    $tradeClass     = new Trades();
    $prevPrice      = 0;
    
    $checkList = [];
    $purchase = null;
    $profit = 0;
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
                        $options = ['state'=>$purchase?'buy':'sell'];
                        $data = $checkList[$symbol]->check($orders[$symbol], $options);

                        if (!$purchase) {
                            if ($data['state'] == 'buy') {
                                $purchase = ['symbol'=>$symbol, 'price'=>$data['price']];
                                $echo .= "BUY!!!\n";
                                $echo .= $data['msg'];
                            }
                        } else {
                            if ($data['state'] == 'sell') {
                                $t_prefit = $data['price'] * (1 - $komsa) - $purchase['price'];
                                if ($t_prefit >= 0) {
                                    $profit += $t_prefit;
                                    $purchase = null;
                                    $echo .= "SELL PROFIT: $profit\n";
                                    $echo .= $data['msg'];
                                }
                            }
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