<?
class Glass {
	protected $orders;
	function __construct($a_orders) {
		$this->orders = $a_orders;
	}

	public function getWalls($minPower, $maxPower) {
		return ['ask'=>$this->getTypeWalls('asks', $minPower, $maxPower), 
				'bid'=>$this->getTypeWalls('bids', $minPower, $maxPower)];
	}

	public function getTypeWalls($type, $minPower, $maxPower) {
		$result = [];
		foreach ($this->orders[$type] as $order) {
			$vol = $order[1];
			//echo "$vol $maxPow\n";
			if ($vol > $minPower) {
				$result[] = ['price'=>$order[0], 'vol'=>$vol, 
							'pow'=>($vol - $minPower)/($maxPower - $minPower)];
			}
		}

		return $result;
	}

	public function curPrice($type='ask') {
		return $this->orders[$type.'s'][0][0];
	}

	public function extraType($type, $volume) {
		$end = count($this->orders[$type]) - 1;
		$prev_vol 	= 0;
		$acc_vol 	= 0;
		$cur_volume = $volume;
        foreach ($this->orders[$type] as $i=>$item) {
    		$slope = pow($item[1] / $volume, 2);
    		$kslope = (1 - $slope);
        	
        	//echo "$kslope, vol: {$item[1]}\n";

            if (($item[1] < $cur_volume) && ($i < $end)) $cur_volume -= $item[1];
            else {
		      	//echo "---\n";
                return $item[0];
                break;
            }
            $prev_vol = $acc_vol;
            $acc_vol += $item[1];
            $cur_volume *= $kslope;
        }

        return false;
	}



/*
    Расчитывает прогнозную цену через timeSecCount сек.
    Результат
        ask
            price - цена предложения через timeSecCount сек
            speed - текущая скороть приращения цены 
        bid
            price - цена спроса через timeSecCount сек
            speed - текущая скороть приращения цены 

        avg_price - усредненая цена через timeSecCount сек
*/
    public function extrapolate($buy_speed, $sell_speed, $toTimeSec, $buy_break=0, $sell_break=0) {
        $buy_vol 	= $buy_speed * $toTimeSec;
        $sell_vol 	= $sell_speed * $toTimeSec;
        $stop = [
        	'ask'=>['price'=>$this->extraType('asks', $buy_vol, $buy_break), 'speed'=>$buy_speed], 
        	'bid'=>['price'=>$this->extraType('bids', $sell_vol, $sell_break), 'speed'=>$sell_speed]
        ];

        if (($stop['ask']['price'] !== false) && 
        	($stop['bid']['price'] !== false))
            $stop['avg_price'] = ($stop['ask']['price'] + $stop['bid']['price']) / 2;
        return $stop;
    }

    public function histogramType($type, $step) {
  		$ip = 0; $lip = 0; $res = [];

//  		print_r($this->orders[$type]);
		foreach ($this->orders[$type] as $item) { 
			$price = $item[0];
			if (abs($price - $lip) >= 0) {
				$ip  = $price;
				$lip = $price + $step;
				$res[] = [$price, 0];
				$id = count($res) - 1;
			}

			$res[$id][1] += $item[1];
		}

    	return $res;
    }

    public function histogram($step)  {
    	return ['ask'=>$this->histogramType('asks', $step), 'bid'=>$this->histogramType('bids', -$step)];
    }

	// Возвращает самую стенки более $minVolume.
    // [Цена, Объем стенки, Объем до стенки]
    public function walls($his, $minVolume) {
    	$res = [];
    	$vol = 0;
    	foreach ($his as $itm) {
    		if ($itm[1] >= $minVolume) $res[] = [$itm[0], $itm[1], $vol];
    		$vol += $itm[1];
    	}
    	return $res;
    }

    // Возвращает самую большую стенку.
    // [Цена, Объем стенки, Объем до стенки]
    public function maxWall($his) {
    	$vol = 0;
    	$max = 0;
    	$price = 0;
    	foreach ($his as $itm) {
    		if ($max < $itm[1]) {
    			$max = $itm[1];
    			$price = $itm[0];
    		}
    		$vol += $itm[1];
    	}
    	return [$price, $max, $vol];
    }
}
?>