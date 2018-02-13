<?

define('ROUNDNUM', 8);

class trade_diff {

    protected $options;
    protected $sender;
    protected $list; 
    function __construct($a_sender, $a_options) {
        $this->options = $a_options;
        $this->sender = $a_sender;
    }
    
    public function resetList($pair, $cur_time) {
        $pairA   = explode('_', $pair);
        $cur_in_id  = curID($pairA[0]);
        $cur_out_id = curID($pairA[1]);
    
        $cur_timeMysql = date('Y-m-d H:i:s', $cur_time);
        
        $query = "SELECT * FROM ".DBPREF."_orders WHERE time >= '".$cur_timeMysql."' - INTERVAL ".$this->options['ORDERSINTERVAL']." AND time <= '".$cur_timeMysql
            ."' AND cur_in={$cur_in_id} AND cur_out={$cur_out_id}";
        
        mtrace($query);
        $this->list = DB::asArray($query);
        return count($this->list) > 1;
    }
    
    public function fullDEV($vals, $field='ask_top') {
        $this->list = array();
        foreach ($vals as $val) $this->list[] = array($field=>$val);
    }
    
    //$count_k от -1 до 1
    public function varavg($startIndex, $count_k, $field='ask_top') {
        $inc    = ($count_k>0)?1:-1;
        $countl = $this->count();
        $i      = min($startIndex, $countl - 1);
        $count  = $countl * $count_k;
        if ($countl > 1) { 
            $accum  = 0;
            $n      = abs($count) + 1;
            $an     = 2 / $n;
            $d      = $an / ($n - 1);
            while (($i >= 0) && ($i < $countl)) {
                $accum += $this->list[$i][$field] * $an;
                $i += $inc;
                $an -= $d;
            }
        } else $accum = $this->list[0][$field]; 
        return $accum;
    }    
    
    public function count() {
        return count($this->list);
    }
    
    public function execute($pair, $cur_time) {
        if ($this->resetList($pair, $cur_time)) {
            //print_r($this->list);
            $last_index = count($this->list) - 1; 
            $last = $this->list[$last_index];
            
            $last_time = strtotime($last['time']);
            
            if ($last_time >= $cur_time - $this->options['WAITTIME']) {
            
                $ask = minmax($this->list, 'ask_top');
                $cur_diff = ($this->varavg($this->count(), -0.5, 'ask_top') - $this->varavg($this->count(), -0.5, 'bid_top')) / $ask['max'];
                
                unset($this->list[$last_index]);
                $last_index--;
                $bid = minmax($this->list, 'bid_top');
                
                $bid_var = $bid['variation'];
                $ask_var = $ask['variation'];
                $in_diff = ($this->varavg(0, 0.5, 'ask_top') - $this->varavg(0, 0.5, 'bid_top')) / $ask['max'];
                $avg_diff = ($ask['avg'] - $bid['avg']) / $ask['avg'];
                
                //print_r("{$this->list[0]['time']}-{$this->list[$last_index]['time']}\n");
                //print_r($last['time']."\n");
                echo "{$last['time']};".round($bid_var, ROUNDNUM).";".round($ask_var, ROUNDNUM).";".round($in_diff, ROUNDNUM).";".round($cur_diff, ROUNDNUM).";".round($avg_diff, ROUNDNUM)."\n";
                
                //echo "bid_var: ".round($bid_var, ROUNDNUM).", ask_var: ".round($ask_var, ROUNDNUM).", in_diff: ".round($in_diff, ROUNDNUM).", cur_diff: ".round($cur_diff, ROUNDNUM).", avg_diff: ".round($avg_diff, ROUNDNUM)."\n";
                //print_r('PRICE: '.$last['ask_top']."\n");
                //print_r($ask);
                //print_r($bid);
                //$this->sender->buy($pair, $last['ask_top'], $last['time']);
                /*
                mtrace($ask);
                mtrace($bid);
                */
            } else mtrace("NO ACTUAL DATA {$last['time']}");
        }
    
    }
}
?>