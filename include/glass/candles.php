<?
class Candles {
	protected $data;
	protected $crawler;
	protected $symbol;
	protected $startTime=0;
	function __construct($crawler, $symbol, $intervalSec, $time, $startTime=0) {
		$this->crawler = $crawler;
		$this->interval	= $intervalSec;
		$this->time = $this->cnvTime($time);
		$this->data = null;
		$this->symbol = $symbol;

		$this->start($this->cnvTime($startTime));
	}

	public function cnvTime($time) {
		return floor($time / $this->interval) * $this->interval;
	}

	protected function ivStr() {
		$ivStr = '1m';
		if (($min = round($this->interval / 60)) <= 30) $ivStr = $min.'m';
		else if (($h = round($this->interval / (60 * 60))) <= 12) $ivStr = $h.'h';
		else if (($d = round($this->interval / (60 * 60 * 24))) <= 12) $ivStr = $d.'d';
		else if (($w = round($this->interval / (60 * 60 * 24 * 7))) <= 12) $ivStr = $w.'w';
		else if (($m = round($this->interval / (60 * 60 * 24 * 30))) <= 12) $ivStr = $m.'m';		
		return $ivStr;
	}

	public function getTime() {
		return $this->time;
	}

	public function getData($data_index=-1) {
		if ($data_index == -1) return $this->data;
		else if ($this->data) {
			$res = [];
			foreach ($this->data as $itm) $res[] = $itm[$data_index];
			return $res;
		}
	} 

	public function start($startTime=0) {
		if ($startTime > 0) $this->startTime = $startTime;
		if ($this->startTime > 0) {
			$this->data = $this->crawler->candles($this->symbol, $this->ivStr(), $this->startTime);
		}
	}

	public function update($time) {
		$ntime = $this->cnvTime($time);
		if ($ntime != $this->time) {
			if ($addData = $this->crawler->candles($this->symbol, $this->ivStr(), $this->time, $ntime)) {
				$this->data = array_merge($this->data, $addData);
				$this->time = $ntime;
			}
		} else {
			/*
			if ($updateData = $this->crawler->candles($this->symbol, $this->ivStr(), $this->time, $this->time, 1))
				$this->data[count($this->data) - 1] = $updateData[0];
			*/
		}
	}

	public function ema($smoonInterval, $start=0, $data_index=4) {
		return Math::ema($this->getData($data_index), $smoonInterval, $start);
	}

	public function getVolumes() {
		return $this->getData(5);
	} 

	public function volumeExtreme() {

        $volumes = $this->getVolumes();
        $buyVol = $this->getData(9);                 // Список объемов покупок   
        $sellVol = Math::suba($volumes, $buyVol);    // Список объемов продаж

        return ['buy'=>max($buyVol), 'sell'=>max($sellVol)];     // Корридор объемов
	}

	public function macd($emaSm1, $emaSm2, $emaSm, $data_index=4) {
		$ema1 = $this->ema($emaSm1, 0, $data_index);
		$ema2 = $this->ema($emaSm2, 0, $data_index);

		$macd = Math::suba($ema1, $ema2);
		$signal = Math::ema($macd, $emaSm);
		$histogram = Math::suba($macd, $signal);

		//$this->print($histogram, "%01.10f");
		return [$macd, $signal, $histogram, $ema1, $ema2];
	}

	public function buyCheck($macdConf, $maxHState=0, $minDirect=0) {
		$macd 		= $this->macd($macdConf[0], $macdConf[1], $macdConf[2], $macdConf[3]);

		$index 		= count($macd[2]) - 1;
		$hisState 	= $macd[2][$index];
		$direct 	= $hisState - $macd[2][$index - 1];

		$result 	= false;
//		print_r($macd[2]);
//		echo "MACD: ($hisState <= $maxHState) && ($direct >= $minDirect)\n";
		$result = ($hisState <= $maxHState) && ($direct >= $minDirect);
		if (!$result) $result = "check MACD require: ($hisState <= $maxHState) && ($direct >= $minDirect)\n";
		foreach ($macd as $item) unset($item);
		unset($macd);
		
		return $result;
	}

	public function sellCheck($macdConf, $minHState=0) {
		$macd = $this->macd($macdConf[0], $macdConf[1], $macdConf[2], $macdConf[3]);

		$index = count($macd[2]) - 1;
		$hisState = $macd[2][$index];
		if ($hisState >= $minHState) {
			return $macd[2][$index - 1] > $hisState;
		}
	}

	public function updateCurPrices($prices) {
		$end = count($this->data) - 1;
		$this->data[$end][2] = max($this->data[$end][2], $prices['buy']); // Обновляем максимум
		$this->data[$end][3] = min($this->data[$end][3], $prices['sell']); // Обновляем минимум
	}

	public function dDate($date) {
		return date('d.m.Y H:i:s', ceil($date / 1000) + 2 * 60 * 60);
	}

	public function printInv($list, $format="%01.8f") {
		for ($i=count($list)-1; $i>=0; $i--) {
			$itm = $list[$i];
			if (is_array($itm)) {
				echo $this->dDate($itm[0])."\n";
				print_r($itm);
			} else echo sprintf($format, $itm)."\n";
		}

		echo "----\n";
	}

	public function print($list, $format="%01.8f") {
		for ($i=0; $i<count($list); $i++) {
			$itm = $list[$i];
			if (is_array($itm)) {
				echo $this->dDate($itm[0])."\n";
				print_r($itm);
			} else echo sprintf($format, $itm)."\n";
		}

		echo "----\n";
	}

	public function dispose() {
		unset($this->data);
	}
}
?>