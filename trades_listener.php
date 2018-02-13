<?
    set_time_limit(0);
    
    include_once('/home/cron_engine.php');
    include('/home/vmaya/trade/include/utils.php');
    include('/home/vmaya/trade/include/courses.php');
    include('/home/vmaya/trade/include/orderBook.php');
    include('trades_pairs.php');
    include('trades_config.php');
    
    define('ANALITIC', false);
    define('DATEFORMAT', 'd.m.Y H:i:s');
    
    $dbname = '_math';
    
    $query = 'https://api.exmo.com/v1/trades/?pair=';
    
    function distribute($v1, $v2, $p) {
        return $v1 * $p + $v2 * (1 - $p);
    }

    function orderPairs($i1, $i2) {
        return ($i1['density']/$i2['density']);// * ($i2['speedUp']/$i1['speedUp']);
    }    
    
    function tradesProc($pairListA, $filterType='buy') {
        $filter = array();
        
        foreach ($pairListA as $pair=>$pairList) {
            $allCount = count($pairList); 
            if ($allCount > 0) {
                $countBuy       = 0;
                $countSell      = 0;
                $minmax         = array(1000000000, 0);
                $density        = $pairList[0]['date'] - $pairList[$allCount - 1]['date'];
                $amountBuyK     = 0;     
                $amountSellK    = 0;     
                $startTime      = $pairList[0]['date'];
                
                
                foreach ($pairList as $trade) {
                    $time = $trade['date']; 
                    $pow = pow(0.3 + (1 - ($startTime - $time)/$density) * 0.7, POW);
                    
                    if ($trade['type'] == $filterType) { 
                        $countBuy += $pow;
                        $amountBuyK += $trade['amount'] * $pow; 
                        $minmax[0] = min($minmax[0], $trade['price']);
                        $minmax[1] = max($minmax[1], $trade['price']);
                    } else {
                        $amountSellK += $trade['amount'] * $pow; 
                        $countSell  += $pow;
                    }
                    
                    //echo date('H:i:s', $time)."  {$trade['type']} $amountBuyK, $amountSellK $pow\n";
                }
                
                $rmm = $minmax[1] - $minmax[0];
                if (($minmax[1] > 0) && ($rmm > 0)) {
                    //Расчитываем скорость поднятия цены
                    $pk = $rmm/$minmax[1];
                    $i = 0; 
                    
                    $pairListR = array_reverse($pairList, true);
                    $si = $pairListR[0]['price'] - $minmax[0];
                    foreach ($pairListR as $trade) {
                        if ($trade['type'] == 'buy') {
                            $rprice = $trade['price'] - $minmax[0];
                            $si += ($rprice - $si) * 0.2;
//                                echo date('H:i:s', $trade['date'])." $pair $rprice $si\n";
                        }
                        $i++; 
                    }
                    //------
                    //echo date('H:i:s', $time)." $pair $si\n";
                
                
                    $percentA = $amountBuyK/($amountSellK + $amountBuyK);
                    $percentB = $countBuy/($countSell + $countBuy);
                    
                    $percent = distribute($percentA, $percentB, 0.7);
                    $speedUp = $si / $minmax[0] * 100;//($minmax[1] - $minmax[0])/$minmax[1];
                    
                    if (($percent >= LIMITFROMTIMEPERCENT) && ($density <= LIMITDENSITY)) {// && ($speedUp >= LIMITSPEEDUP)) {
                        $result = array(
                            'pair'=>$pair,
                            'buyPercent'=>$percent,
                            'density'=>$density,
                            'speedUp'=>$speedUp  
                        );
                        $filter[] = $result;
                        
                        if (ANALITIC) {
                            $inputStr = '';//json_encode($pairList);
                            $resultStr = json_encode($result); 
                            DB::query("INSERT INTO _forecast (`date`, `pair`, `input`, `result`) VALUE (NOW(), '{$pair}', '{$inputStr}', '{$resultStr}')");
                        }
                    }
                }
            }     
        }
        
        uasort($filter, 'orderPairs');
        
        if (count($filter) > 0) {
            return $filter;
        } else return false;
    }
    
    $orderBook = new orderBook();

    $startScriptTime = strtotime('NOW');    
    
    echo 'START-'.date(DATEFORMAT, $startScriptTime)."\n";
    
    while (strtotime('NOW -'.RUNINTERVAL) < $startScriptTime) {
        $cycleTime = time();
        
        $orders = $orderBook->exec($pairs, array('bid'=>0.1/*, 'ask'=>-0.1*/), 10, 8/10);
        //print_r($orders);
        $o_pairs = array_keys($orders);
        
        if ($str_data = @file_get_contents($query.implode(',', $o_pairs))) {
            $list = json_decode($str_data, true);
            if ($filter = tradesProc($list)) {
                foreach ($filter as $key=>$item) {
                    if (isset($orders[$item['pair']])) {
                        $filter[$key]['orders'] = $orders[$item['pair']];
                    } 
                }
            }
            
            echo 'I-'.date(DATEFORMAT)."--------------------------------------------\n";
            if ($filter) {
                print_r($filter);
            }
        }
        
        while ($cycleTime + WAITTIME > time()) {usleep(100);};
    }
    if ($db) mysql_close($db);
    echo 'STOP-'.date(DATEFORMAT, $startScriptTime)."\n";
?>