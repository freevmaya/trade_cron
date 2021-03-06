<?
    set_time_limit(0);
    
    include_once('/home/cron_engine_trade.php');
    define('WAITTIME', 30);
    define('REMOVEINTERVAL', '7 DAY');
    define('REMOVEINTERVALORDERS', '1 DAY');
    define('LIMIT', 50);
    define('DBPREF', '');
    define('DATEFORMAT', 'Y-m-d H:i:s');
    define('TRADEPATH', '/home/vmaya/trade/');
    define('MAINDIR', dirname(__FILE__).'/');

    include_once(MAINDIR.'modules/timeObject.php');
    include_once(MAINDIR.'include/utils.php');
    include_once(MAINDIR.'modules/cur_watch.php');
    include_once(MAINDIR.'modules/volumes.php');
    include_once(MAINDIR.'data/exmo_pairs.php');
    include_once(TRADEPATH.'include/events.php');
    include_once(INCLUDE_PATH.'fdbg.php');
    include_once(INCLUDE_PATH.'_dbu.php');
    include_once(INCLUDE_PATH.'_edbu2.php');
    include_once(MAINDIR.'include/console.php');
    include_once(MAINDIR.'include/db/mySQLProvider.php');

    $url = 'https://api.coinmarketcap.com/v1/ticker/?limit='.LIMIT.'&start=';
    
    $dbname = 'trade';

    $dbp = new mySQLProvider('localhost', $dbname, $user, $password);
    $isdea = explode('_', dirname(__FILE__));
    $is_dev = $isdea[count($isdea) - 1] == 'dev';

    $scriptID = basename(__FILE__);
    $scriptCode = md5(time());
    $crec = startScript($dbp, $scriptID, $scriptCode, WAITTIME);
    $FDBGLogFile = (__FILE__).'.log';
    new console($is_dev);

    $start = $crec?$crec['data']['start']:0;
    console::log('START '.$scriptID.', '.$start);

    $fields = ['symbol', 'name', 'price_usd', 'price_btc', '24h_volume_usd', 'market_cap_usd', 'available_supply', 'total_supply', 'percent_change_1h', 'last_updated'];

    $fieldsStr = '`'.implode('`,`', $fields).'`';

    while (true) {
        $time = time();
        $full_url = $url.$start;
        if ($str_data = @file_get_contents($full_url)) {
            $data = json_decode($str_data, true);

            $count = count($data);
            if ($count > 0) {
                foreach ($data as $coin) {
                    $vals = '';
                    foreach ($fields as $field)
                        $vals .= ($vals?",":"")."'".$dbp->safeVal($coin[$field])."'";

                    $query = "REPLACE _coinmarket ({$fieldsStr}) VALUES ({$vals})";
                    $dbp->query($query);
                }
                $start += $count;
            } else $start = 0;
        } else {
            //console::log('ERROR URL '.$full_url);
            $start = 0;
        }
       
        cronReport($dbp, $scriptID, ['start'=>$start]);
        if (isStopScript($dbp, $scriptID, $scriptCode)) break;
        if (($dtime = $time + WAITTIME - time()) > 0) sleep($dtime);
    }

    console::log('STOP '.$scriptID);
    $dbp->close();
    if ($db) $db->close();
?>