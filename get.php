<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine.php');
    define('SSPATH', '/home/vmaya/ssd2/');
    define('WAITTIME', 15);
    define('RUNINTERVAL', '1 HOUR');
    define('DBPREF', '');
    
    
    //$list = json_decode(file_get_contents('list.json'));
    $queryURL = 'https://query.yahooapis.com/v1/public/yql?q=select+*+from+yahoo.finance.xchange+where+pair+=+"{CURSE}"&format=json&env=store%3A%2F%2Fdatatables.org%2Falltableswithkeys&callback=';
    
    $cur_in = array('ETH', 'BTC', 'EUR', 'USD', 'LTC');
    $cur_out = 'RUB';
    $dbname = "_math";
    
    $currs = '';
    foreach ($cur_in as $cur) {
        $currs .= ($currs?',':'').$cur.$cur_out;
    }
    
    function curID($cur_sign) {
        if ($cur_rec = DB::line("SELECT * FROM ".DBPREF."_currency WHERE `sign`='$cur_sign'")) return $cur_rec['cur_id'];
        else {
            DB::query("INSERT INTO ".DBPREF."_currency (`sign`, `name`) VALUES ('{$cur_sign}', '{$cur_sign}')");
            return DB::lastID();
        }        
    }
    
    
    $startTime = strtotime('NOW');
    
    while (strtotime('NOW -'.RUNINTERVAL) < $startTime) {
        $queryURL = str_replace('{CURSE}', $currs, $queryURL);
        $data = json_decode(file_get_contents($queryURL));
        
        $rates = $data->query->results->rate;
        for ($i=0;$i<$data->query->count;$i++) {
            $rate = $rates[$i];
             
            $cur_sign   = explode('/', $rate->Name);
            $cur_in_id  = curID($cur_sign[0]);
            $cur_out_id = curID($cur_sign[1]);
            
            $date = date('Y-m-d', strtotime($rate->Date));
            $time = date('H:i:00', strtotime($rate->Time));
            
            $ask = floatval($rate->Ask);
            $bid = floatval($rate->Bid);
            
            DB::query("REPLACE ".DBPREF."_course (cur_in_id, cur_out_id, `date`, `time`, ask, bid) ".
            "VALUES ({$cur_in_id}, {$cur_out_id}, '{$date}', '{$time}', {$ask}, {$bid})");
        }
        
        sleep(WAITTIME);
    }
    if ($db) mysql_close($db);
?>