<?php
    set_time_limit(0);
    
    include_once('/home/cron_engine.php');
    include_once('modules/trade_diff_step.php');
    include_once('modules/sender.php');
    define('DBPREF', '');
    define('DEBUG', false);
    define('DATEFORMAT', 'H:i:s');
    define('HOUR', 60 * 60);
    
    
    include_once('/home/vmaya/trade/include/utils.php');
    
    $dbname = '_math';
    
    //$list = json_decode(file_get_contents('list.json'));
    $pairs = 'ETH_USD';
    
    
    $pairs_list = explode(',', $pairs);
    
    function minmax($list, $field) {
        $result = array('min'=>0, 'max'=>0); 
        if ($list) {
            $result = array('min'=>$list[0][$field], 'max'=>$list[0][$field]);
            $avg_accum = 0;
            foreach ($list as $item) {
                if ($item[$field] < $result['min']) $result['min'] = $item[$field];
                if ($item[$field] > $result['max']) $result['max'] = $item[$field];
                
                $avg_accum += $result['min'] + $result['max']; 
            }
            
            $result['avg'] = $avg_accum / (count($list) * 2);
            $result['variation'] = ($result['max'] - $result['min']) / $result['max'];
        } 
        return $result;
    }
    
    function mtrace($val) {
        if (DEBUG) {
            print_r($val);
            echo "\n";
        }
    }
    


    $sender = new base_sender();
    
    $diff = new trade_diff($sender, array(
        'WAITTIME'=>120,
        'ORDERSINTERVAL'=>'8 MINUTE',
        'MINVARIATIN'=>0.006,
        'MINDIFF'=>0.0001,
        'MINPREDIFF'=>0.001
    ));
    
    
    $cur_time = strtotime('2017-11-29 16:32:00');       //TEST
    $end_time = strtotime('2017-11-29 17:29:00');
    $step_time = 60;
    
    
/*    
    $diff->fullDEV(array(1, 5));
    $v = $diff->varavg(2, -1);
    echo $v."\n";
*/    
    
    foreach ($pairs_list as $pair) {
        while ($cur_time < $end_time) {
            //echo "time: ".date(DATEFORMAT, $cur_time)."\n";
            $diff->execute($pair, $cur_time);
            $cur_time += $step_time;
            sleep(1);
        }
    }
/*     
    $startTime = time();
    $cur_time = time();
    while ($startTime + HOUR > $cur_time) {
        foreach ($pairs_list as $pair) {
            $diff->execute($pair, $cur_time);
        }
        $cur_time = time();
        sleep(1);
    }
*/    
    if ($db) mysql_close($db);
?>