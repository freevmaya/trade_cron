<?php
/*
    Параметры
        m - маркет
*/

    set_time_limit(0);
    
    include_once('/home/cron_engine_trade.php');
    define('WAITTIME', 60);
    define('WAITAFTERERROR', WAITTIME * 5);
    define('REMOVEINTERVAL', '1 WEEK');
    define('PURCHASE_FILE', 'data/trade_pair.json');
    define('DBPREF', '');
    define('DATEFORMAT', 'Y-m-d H:i:s');
    define('MAINDIR', dirname(__FILE__).'/');
    define('NFRM', "%01.8f");
    define('NFRMS', "%01.2f");
    define('MAXWAITTRADE', 60 * 5);
    define('PAIRFILEDATA', 'data/auto_trade_pairs.json');
    define('DEFAULTMARKET', 'binance');

    $params = [];
    for ($i=1;$i<count($argv);$i++) {
        $a = explode('=', $argv[$i]);
        $params[$a[0]] = isset($a[1])?$a[1]:true;
    }

    $market_symbol  = isset($params['m'])?$params['m']:DEFAULTMARKET;                 // Маркет

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
    include_once(MAINDIR.'include/glass/orderHistory.php');
    include_once(MAINDIR.'include/glass/checkPair.php');
    include_once(MAINDIR.'include/glass/candles.php');
    include_once(MAINDIR.'include/glass/math.php');
    include_once(MAINDIR.'include/glass/sender/baseSender.php');
    include_once(MAINDIR.'include/glass/sender/'.$market_symbol.'Sender.php');
    include_once(MAINDIR.'include/crawlers/baseCrawler.php');
    include_once(MAINDIR.'include/crawlers/'.$market_symbol.'Crawler.php');   

    define('CONFIGFILE', 'data/check_pairs.json');

    GLOBAL $volumes;
    
    $dbname = 'trade';
    $config = new tradeConfig();

    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';
    $dbp = new mySQLProvider('localhost', $dbname, $user, $password);
    $scriptID = basename(__FILE__).($is_dev?'dev':'');
    $scriptCode = md5(time());
    $WAITTIME = WAITTIME;

    startScript($dbp, $scriptID, $scriptCode, $WAITTIME, '', $is_dev);
    $FDBGLogFile = (__FILE__).'.log';
    new console($is_dev);
    
    $startTime = strtotime('NOW');
    $crawlerName = $market_symbol.'Crawler';
    $crawler = new $crawlerName();

    $options = json_decode(file_get_contents(CONFIGFILE), true);

    console::log('START '.$scriptID);

    $crawler->refreshExchangeInfo();

    $stdK = 0.0023;

    while (true) {
        $time     = time();
        $tikerBTC = $crawler->ticker('BTCUSDT');
        $tikerBNB = $crawler->ticker('BNBUSDT');

        $k = ($tikerBNB['bidPrice'] + $tikerBNB['askPrice'])/($tikerBTC['bidPrice'] + $tikerBTC['askPrice']);


        echo sprintf(NFRMS, $k/$stdK),"\n";

        cronReport($dbp, $scriptID, null);
        if (isStopScript($dbp, $scriptID, $scriptCode)) break;
        if (($dtime = $time + $WAITTIME - time()) > 0) sleep($dtime);
    }

    console::log('STOP '.$scriptID);
    $dbp->close();
?>