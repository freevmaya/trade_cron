<?
//include_once('moments.php');
class Trades {
	private $history;
	private $maxHistoryLen = 10000;

	function __construct() {
		$this->history = [];
	}

	public function addHistory($trades) {
		foreach ($trades as $pair=>$list) {
			if (!isset($this->history[$pair])) {
				$this->history[$pair] = $list;
			} else {
				/*
				if (count($this->history[$pair]) > $this->maxHistoryLen) 
					$this->history[$pair] = array_slice($this->history[$pair], 1);
				*/
				$count  	= count($this->history[$pair]);
				$last 		= $this->history[$pair][$count - 1];
				$last_time 	= $last['time'];

				for ($i = count($list)-1; $i>0; $i--) {
					if ($list[$i]['time'] > $last['time'])
						$this->history[$pair][] = $list[$i];
					else break;
				}
			}

			if (!function_exists('Trades_cmd')) {
				function Trades_cmd($i1, $i2) {
					return $i1['time'] > $i2['time'];
				}
			}
			usort($this->history[$pair], 'Trades_cmd');
		}

	}

	public function ph($pair) {
		return $this->history[$pair];
	}

	public function timeCountSec($pair) {
		if (count($this->history[$pair]) > 1) {
			$last 		= count($this->history[$pair]) - 1;
			$lastItm	= $this->history[$pair][$last];
			$firstItm  	= $this->history[$pair][0];
			return ($lastItm['time'] - $firstItm['time']) / 1000;
		} else return 0;
	}
	
	public function lastPrice($pair) {
		$result = ['buy'=>0, 'sell'=>0];
		if (($lastIndex = count($this->history[$pair]) - 1) > 0) {
			for ($i=$lastIndex; $i>=0; $i--) {
				$itm = $this->history[$pair][$i];
				$id = ($itm['isBuyerMaker']==1)?'sell':'buy';
				if ($result[$id] == 0) $result[$id] = $itm['price'];

				if ($result['buy'] && $result['sell']) break;
			}
		}
		return $result;
	}
	
	public function isPriceMore($pair, $time /* Server time */, $price, &$report) {
		if (($lastIndex = count($this->history[$pair]) - 1) > 0) {
			for ($i=$lastIndex; $i>=0; $i--) {
				$itm = $this->history[$pair][$i];
				if ($itm['time'] >= $time) {
					$tmp_report = date(DATEFORMAT, $itm['time'] / 1000).' '.$itm['qty'].' '.$itm['price'];
					if (($itm['isBuyerMaker']==0) && ($itm['price'] >= $price)) {
						$report = $tmp_report;
						return true;
					}
				}
			}
		}
		return false;
	}
	
	public function isPriceBelow($pair, $time /* Server time */, $price) {
		if (($lastIndex = count($this->history[$pair]) - 1) > 0) {
			for ($i=$lastIndex; $i>=0; $i--) {
				$itm = $this->history[$pair][$i];
				if ($itm['time'] >= $time) {
					if (($itm['isBuyerMaker']==0) && ($itm['price'] <= $price)) return true;
				}
			}
		}
		return false;
	}
	
	/*

		$maxTimeCount - SEC
	*/
	public function lastVolumes($pair, $minCount=10, $maxTimeCount=60) {
		$volume = ['buy'=>0, 'sell'=>0, 'buy_persec'=>0, 'sell_persec'=>0, 'time_delta'=>0];
		$ids = [0=>'buy', 1=>'sell'];
		$vols = [0=>[], 1=>[]];
		$delta = 0;
		$mlsTimeCount = $maxTimeCount * 1000;

		if (($lastIndex = count($this->history[$pair]) - 1) > 0) {
			$lastTime = $this->history[$pair][$lastIndex]['time'];
			for ($i=$lastIndex; $i>=0; $i--) {
				$itm = $this->history[$pair][$i];

				$delta = $lastTime - $itm['time'];

				if (((count($vols[0]) < $minCount) || (count($vols[1]) < $minCount)) || ($delta < $mlsTimeCount)) {
					$isbm = $itm['isBuyerMaker'];
					$id = $ids[$isbm];
					$volume[$id] += $itm['qty'];
					$vols[$isbm][] = $itm['qty'];
					$vols[($isbm + 1) % 2][] = 0;
				} else break;
			}
		}

		$sec_delta = $delta / 1000;

		if (($volume['time_delta'] = $sec_delta) > 0) {
			$volume['sell_persec'] = $volume['sell']/$sec_delta;
			$volume['buy_persec'] = $volume['buy']/$sec_delta;
		}
		$volume['buy_wgt'] = varavg($vols[0], 1);
		$volume['sell_wgt'] = varavg($vols[1], 1);

		return $volume;
	}
}
?>