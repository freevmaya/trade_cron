<?
define('VOLSTEPS', 10);

class Volumes {
	protected $bids;
	protected $asks;
	protected $askvol;
	protected $bidvol;
    protected $parent_id;

	function __construct($a_asks=null, $a_bids=null) {
        if ($a_bids && $a_asks) {
            $this->bids = $this->parseList($a_bids);
            $this->asks = $this->parseList($a_asks);
            $this->bidvol = $this->varavg($this->bids, 1);
            $this->askvol = $this->varavg($this->asks, 1);
/*
            print_r($this->bids);
            print_r($this->asks);
*/        
        } else if (is_numeric($a_bids)) { // Если передается номер записи
            //echo "$a_bids\n";
            $this->parent_id = $a_bids;
            $this->bids = $this->readVolumes($a_bids, '_bid');
            $this->asks = $this->readVolumes($a_bids, '_ask');
            $this->bidvol = $this->varavg($this->bids, 1);
            $this->askvol = $this->varavg($this->asks, 1);
       }

/*
		print_r($this->bids);
		print_r($this->asks);
*/        
		//$asks = $this->parseList($a_asks);
    }

    public function debugInfo() {
        return "ID: {$this->parent_id} BID: {$this->bidvol} / ASK: {$this->askvol}\n";
    }

    public function baRatio() {
        return $this->bidvol / $this->askvol;
    }

    public function getBidvol() {
        return $this->bidvol;
    }

    public function getAskvol() {
        return $this->askvol;
    }

    private function readVolumes($parent_id, $table) {
        $result = array();
        $list = DB::asArray("SELECT * FROM {$table} WHERE parent_id={$parent_id}"); 
        foreach ($list as $item)
            $result[] = array($item['price'], $item['volume']);
        return $result;
    }

	private function insertVolumes($parent_id, $list, $table) {
        if (count($list) > 0) {
        	$query = "INSERT INTO {$table} (parent_id, price, volume) VALUES ";
        	$values = '';
        	foreach ($list as $item) 
            	$values .= ($values?',':'')."({$parent_id}, {$item[0]}, {$item[1]})";
        
        	$query .= $values;    
        	return DB::query($query);
        } else return false;
	}

    public function save($parent_id) {
    	$this->insertVolumes($parent_id, $this->bids, '_bid');
    	$this->insertVolumes($parent_id, $this->asks, '_ask');
    } 

    protected function parseList($arr) {
    	$acum = array();
    	$count = count($arr);
    	$min = $arr[0][0];
    	$max = $arr[$count - 1][0];
    	$inv = $min > $max;
    	$p_step = ($max - $min) / (VOLSTEPS + 1);
    	$price = $min;
    	$result = array();

    	$i = 0;
    	$pi = 0;
    	while ($i < $count) {
    		$p = $arr[$i][0];
    		$nprice = $price + $p_step;
    		if ((($p < $nprice) && !$inv) || (($p > $nprice) && $inv)) {
    			if (!isset($result[$pi])) $result[$pi] = array($price, 0);

    			$result[$pi][1] += $arr[$i][1];
    		} else {
    			$price = $nprice;
                if (isset($result[$pi])) $pi++;
    		}

    		$i++;
    	}
    	return $result;
    }
        

    public static function varavg($list, $count_k, $index=1) {
        $inc    = ($count_k>0)?1:-1;
        $countl = count($list);
        $i      = ($count_k>0)?0:(count($list)-1);
        $count  = $countl * $count_k;
        $accum  = 0;
        if ($countl > 1) { 
            $n      = abs($count) + 1;
            $an     = 2 / $n;
            $d      = $an / ($n - 1);
            while (($i >= 0) && ($i < $countl)) {
                if (!isset($list[$i])) {
                    print_r($list);
                }

                if ($index < count($list[$i]))
                    $accum += $list[$i][$index] * $an;
                
                $i += $inc;
                $an -= $d;
            }
        } else if ($countl > 0) $accum = $list[0][$index]; 
        return $accum;
    }   
}
?>