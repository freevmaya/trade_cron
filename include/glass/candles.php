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

	protected function cnvTime($time) {
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
		if ($this->startTime > 0)
			$this->data = $this->crawler->candles($this->symbol, $this->ivStr(), $this->startTime);
	}

	public function update($time) {
		$ntime = $this->cnvTime($time);
		if ($ntime != $this->time) {
			if ($addData = $this->crawler->candles($this->symbol, $this->ivStr(), $this->time, $ntime)) {
				$this->data = array_merge($this->data, $addData);
				$this->time = $ntime;
			}
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

		$macd = Math::suba($ema1, $ema2, 0, count($ema1) - count($ema2));

		$signal = Math::ema($macd, $emaSm);
		$histogram = Math::suba($macd, $signal, 0, count($macd) - count($signal));;

		//$this->print($histogram, "%01.10f");
		return [$macd, $signal, $histogram];
	}

	public function buyCheck($macdConf, $maxHState=0) {
		$macd = $this->macd($macdConf[0], $macdConf[1], $macdConf[2], $macdConf[3]);

		$index = count($macd[2]) - 1;
		$hisState = $macd[2][$index];
		if ($hisState <= $maxHState) {
			return $macd[2][$index - 1] < $hisState;
		}
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

	public function print($list, $format="%01.8f") {
		$time = $this->time;		

		for ($i=count($list)-1; $i>=0; $i--) {
			$itm = $list[$i];
			if (is_array($itm)) {
				echo date('d:m H:i:s', ceil($itm[0] / 1000) + 2 * 60 * 60)."\n";
				print_r($itm);
			} else echo sprintf($format, $itm)."\n";

			$time -= $this->interval;
		}

		echo "----\n";
	}
}
?>