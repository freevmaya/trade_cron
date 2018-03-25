<?
class Glass extends timeObject {
	protected $orders;
	function __construct($a_orders) {
        parent::__construct(time());

        $this->history = new orderHistory(5);
		$this->setOrders($a_orders);        
	}


    public function setOrders($a_orders) {
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
        if ($volume == 0) trace("volume=0\n", 'disp');
        else {
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

    public function calcStep($type) {
        $price = $this->orders[$type][0][0];

        for ($i=0; $i<10; $i++) {
            $pp = $price * pow(10, $i);
            $v = round($pp) * $pp;
            if ($v >= 10) {
                return 1 / pow(10, $i);
            }
        }

        return 0;
    }

    public function speedHist($type, $step=0) {

        for ($i=0; $i<$this->history->getSize(); $i++) {
            $hist = $this->histogramTypeA($this->history->get($i), $type, $step);

        }
    }
/*
    $orders - список ордеров в стакане
    $type - asks или bids, предложение или спрос
    $step - шаг или размерность гистограммы
    $minPrice - Выбирать начиная с этой минимальной цены 
    $maxPrice - Выбирать заканчивая этой максимальной ценой 
*/
    protected function histogramTypeA($orders, $type, $step=0, $minPrice=0, $maxPrice=0) {
        $step = ($step==0)?$this->calcStep($type):$step;
        $ip = 0; $lip = 0; $res = [];

        foreach ($orders[$type] as $item) {
            if ($minPrice == 0) $minPrice = $item[0];
            else $minPrice = min($item[0], $minPrice);
            if ($maxPrice == 0) $maxPrice = $item[0];
            else $maxPrice = max($item[0], $maxPrice);
        }
//          print_r($orders[$type]);
        foreach ($orders[$type] as $item) { 
            $price = $item[0];
            if (($price >= $minPrice) && ($price <= $maxPrice)) { 
                if (abs($price - $lip) >= 0) {
                    $ip  = $price;
                    $lip = $price + $step;
                    $res[] = [$price, 0];
                    $id = count($res) - 1;
                }
            } else break;

            $res[$id][1] += $item[1];
        }

        return $res;
    }

    public function histogramType($type, $step=0, $minPrice=0, $maxPrice=0) {
    	return $this->histogramTypeA($this->orders, $type, $step, $minPrice, $maxPrice);
    }

    public function histogram($step=0, $minPrice=0, $maxPrice=0)  {
    	return ['ask'=>$this->histogramType('asks', $step, $minPrice, $maxPrice), 'bid'=>$this->histogramType('bids', -$step, $minPrice, $maxPrice)];
    }

    public function nearWall($his, $hearVolume, $minVolume=0) {
        $walls = $this->walls($his, $minVolume);
        foreach ($walls as $wall) 
        if ($wall[2] >= $hearVolume) return $wall;
        return null;
    }

	// Возвращает стенки более $minVolume.
    // [Цена, Объем стенки, Объем до стенки]
    public function walls($his, $minVolume=0) {
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