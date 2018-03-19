<?
class tradeManager {
	protected $candles;
	protected $config;
	protected $date_format = 'd.m H:i';

	function __construct($candles, $config) {
		$this->candles = $candles;
		$this->config = $config;
	}

	protected function date($dateUnix) {
		return date($this->date_format, ceil($dateUnix / 1000));
	}

	public function analizer() {
		$result = null;
		$emas = $this->getEmas();
		$height = $this->minmaxHeight($emas);

		$min_height = $this->lastPrice() * $this->config['min_percent'];
		$min_profit = $height[0] - $min_height;

		$result = [
			'min'=>$height[0],
			'max'=>$height[1],
			'min_profit'=>$min_profit
		];

		if ($min_profit > 0) {
			if (($test = $this->test($emas)) && (count($test) > 0)) {
				$cans = $this->candles->getData();
				$accProfit = 0;
				foreach ($test as $itm) $accProfit += $itm['profit'];

				$result['test_result'] = [
					'list'=>$test,
					'profit'=>$accProfit,
					'count'=>count($test),
					'perod_start'=>$this->date($cans[$this->config['ema_interval']][0]),
					'perod_stop'=>$this->date($cans[count($cans) - 1][0])
				];
			}	
		}

		return $result;
	}

	public function test($emas) {
		$list = [];
		$cans = $this->candles->getData();
 		$offset = count($cans) - count($emas[0]);

		$buy = null;
 		$sell_price = 0;

		foreach ($emas[0] as $i=>$bottomPrice) {
			$candle = $cans[$i + $offset];
			// Если нет текущих покупок
			if (!$buy) { 
				// Выставляем ордер по цене EMAS1
				if ($bottomPrice >= $candle[3]) { // Если цена опустилась ниже цены ордера
					// Срабатывает ордер на покупку 
					$buy = [$candle[0], 'price'=>$bottomPrice]; // Цена ордера покупки
				}
			} else {
				$buy_price = $buy['price'];
				$sell_price = 0;

				// Выставляем цену ордера
				$order_price = max($emas[1][$i], $buy_price + $buy_price * $this->config['min_percent']);


				// Если расчетная цена продажи корректна и цена поднялась выше цены ордера
				if ($order_price <= $candle[2]) 
					$sell_price = $order_price; // Цена продажи

				if ($sell_price > 0) { 
					$profitPercent = (($sell_price - $buy_price)/$sell_price) * 100;
					$profit = $sell_price - $buy_price;
					$list[] = [$this->date($buy[0]), $this->date($candle[0]),
								'buy'=>$buy_price, 'sell'=>$sell_price, 'percent'=>$profitPercent, 'profit'=>$profit];
					$buy = null;
				}
			}
		}

		return $list; 
	}

	public function macdTest($index=0) {
		$result = ['buy'=>0, 'sell'=>0];

		if (intval($this->config['no_macd']) == 1) {
			return ['buy'=>1, 'sell'=>1];
		}

	    $macd = $this->candles->macd($this->config['MACD'][0], $this->config['MACD'][1], $this->config['MACD'][2]);
/*
	    $this->candles->print($macd[0]);
	    $this->candles->print($macd[1]);
	    $this->candles->print($macd[2]);

	    $time = $this->candles->cnvTime(time()) + 2 * 60 * 60;
	    $si = 2;
	    for ($i=0;$i<40;$i++) {
	    	$idx = count($macd[$si]) - 1 - $i;
	    	echo $this->date($time * 1000)." ".sprintf("%01.8f", $macd[$si][$idx])."\n";
	    	$time -= 60 * 15;
	    }
	    exit;
*/	    

	    $hist = $macd[2];

//	Тестирование вариантов MACD
//	    $hist = [-0.00011, -0.00012, -0.00013, -0.00014, -0.00015, -0.00016, 0.00011, 0.00012, 0.00013, 0.00014, 0.00015, 0.00016]; // Ничего
//	    $hist = [-0.00011, -0.00012, -0.00013, -0.00014, -0.00015, -0.00016, 0.00011, 0.00020, 0.00013, 0.00012, 0.00011, 0.00010]; // Продажа
//	    $hist = [0.00011, 0.00020, 0.00013, 0.00012, 0.00011, 0.00010, -0.00011, -0.00012, -0.00013, -0.00014, -0.00015, -0.00016]; // Ничего
//	    $hist = [0.00011, 0.00020, 0.00013, 0.00012, 0.00011, 0.00010, -0.00011, -0.00020, -0.00013, -0.00012, -0.00011, -0.00010]; // Покупка

    	$min = abs(min($hist));
    	$max = max($hist);

    	$cur = new Queue(3);
    	$end = count($hist) - $index - 1; 
    	$v = $hist[$end];

    	for ($i=1;$i<=$cur->size();$i++) $cur->push($hist[$end - $i]);
    	$avg	= $cur->weighedAvg();
    	$bdirect = ($v - $avg) / $min;
    	$sdirect = ($v - $avg) / $max;
    	$bmv = $v / $min;
    	$smv = $v / $max;

    	//echo $bmv.' '.$smv."\n";

    	if ($bmv <= $this->config['buy_macd_value'])
    		$result['buy'] = ($bdirect > $this->config['buy_macd_direct'])?1:0;

    	if ($smv >= $this->config['sell_macd_value']) 
    		$result['sell'] = ($sdirect < $this->config['sell_macd_direct'])?1:0;

    	$result['avg'] = $avg;
    	$result['buy_macd_value'] = $bmv;
    	$result['sell_macd_value'] = $smv;
    	$result['buy_macd_direct'] = $bdirect;
    	$result['sell_macd_direct'] = $sdirect;

    	return $result;
	}

	public function getEmas() {
		$ema_iv = $this->config['ema_interval'];
		return [$this->candles->ema($ema_iv, 0, 3), $this->candles->ema($ema_iv, 0, 2)];
	}

	public function tradeRequired($purchase) {
		$emas = $this->getEmas();
		$end = count($emas[0]) - 1;
		$result = $this->macdTest();
		$result['buy_price']=$emas[0][$end];
		if ($purchase) {
			$result['sell_price'] = max($emas[1][$end], $purchase['price'] + $purchase['price'] * $this->config['min_percent']);
		}

		return $result;
	}

	public function lastCap() {
		$emas = $this->getEmas();
		$end = count($emas[0]) - 1;
		return [$emas[0][$end], $emas[1][$end]];
	}

	public function minmaxHeight($emas) {
		$height = [$emas[1][0] - $emas[0][0], $emas[1][0] - $emas[0][0]];
		for ($i=0;$i<count($emas[0]);$i++) {
			$height[0] = min($height[0], $emas[1][$i] - $emas[0][$i]);
			$height[1] = max($height[1], $emas[1][$i] - $emas[0][$i]);
		}
		return $height;
	}

	protected function minHeight() {
		$list = $this->candles->getData();
		$minh = $list[0][2] - $list[0][3];
		for ($i=1;$i<count($list); $i++) {
			$minh = min($list[$i][2] - $list[$i][3], $minh);
		}
		return $minh;
	}

	public function lastPrice() {
		$list = $this->candles->getData();
		return ($list[0][1] + $list[0][4]) / 2;
	}

}
?>