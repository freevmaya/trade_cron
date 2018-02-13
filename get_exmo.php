<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine.php');
    define('SSPATH', '/home/vmaya/ssd2/');
    define('WAITTIME', 120);
    define('RUNINTERVAL', '1 HOUR');
    define('DBPREF', '');
    include_once('/home/vmaya/trade/include/utils.php');
    
    $dbname = 'trade';
    
    
    //$list = json_decode(file_get_contents('list.json'));
    $queryURL = 'https://api.exmo.com/v1/ticker';
    
    $startTime = strtotime('NOW');
    
    while (strtotime('NOW -'.RUNINTERVAL) < $startTime) {
        $time = time();
        $data = json_decode(file_get_contents($queryURL), true);
        
        foreach ($data as $pair=>$item) {
             
            $pairA   = explode('_', $pair);
            $cur_in_id  = curID($pairA[0]);
            $cur_out_id = curID($pairA[1]);
            
            $time = $item['updated'];  
            $date = date('Y-m-d', $item['updated']);
            
            $sell_price = floatval($item['sell_price']);
            $buy_price = floatval($item['buy_price']);
            
            $vol = floatval($item['vol']);
            $vol_curr = floatval($item['vol_curr']);
             
            $extD = floatval($item['last_trade']);
            
            if (!DB::one("SELECT time FROM ".DBPREF."_exmo WHERE `time`={$time} AND cur_in={$cur_in_id} AND cur_out={$cur_out_id}"))
                DB::query("INSERT INTO ".DBPREF."_exmo (cur_in, cur_out, buy_price, sell_price, `time`, `date`, vol, vol_curr, extD) ".
                                            "VALUES ({$cur_in_id}, {$cur_out_id}, {$buy_price}, {$sell_price}, {$time}, '{$date}', {$vol}, {$vol_curr}, {$extD})");
            //else echo "record has already\n";
        }
        
        while ($time + WAITTIME > time()) sleep(5);
    }
    if ($db) $db->close();
?>