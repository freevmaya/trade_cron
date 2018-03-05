<?
define("BINANCEURL", 'https://www.binance.com/');

class binanceCrawler extends baseCrawler {
	protected $prevTrades;
	protected $symbols;
	protected $list;

	function __construct() {
		$this->prevTrades = [];
	}

	protected function getActualPairs() {
		$symbols = [];
		if ($this->list = $this->getExchangeInfo()) {
			foreach ($this->list['symbols'] as $item) {
				$len = strlen($item['baseAsset']);
				$right = substr($item['symbol'], $len);
				if ($right == 'BTC') {
					$symbols[$item['symbol']] = $item['baseAsset'].'_'.$right;
				}
			}
		}

		return $symbols;
	}

	protected function getExchangeInfo() {
		return json_decode(file_get_contents(BINANCEURL."api/v1/exchangeInfo"), true);
	}

	protected function sum($arr) {
		$sum = [0, 0];
		$count = count($arr);
		for ($i=0;$i<$count;$i++) {
			$sum[0] += $arr[$i][0] * $arr[$i][1];
			$sum[1] += $arr[$i][1];
		}
		return $sum;
	}

	public function getOrders() {
		include(MAINDIR.'data/binance_pairs.php');
		$result = [];
		$pairs = explode(',', $pairs);

	    foreach ($pairs as $pair) {
		    $queryURL = BINANCEURL.'api/v1/depth?symbol='.str_replace('_', '', $pair).'&limit=100';
		    $data = json_decode(file_get_contents($queryURL), true);

		    $sumAsks = $this->sum($data['asks']);
		    $sumBids = $this->sum($data['bids']);

		    $item = ['ask'=>$data['asks'], 'bid'=>$data['bids'], 
		    		'ask_top'=>$data['asks'][0][0], 'bid_top'=>$data['bids'][0][0],
		    		'ask_quantity'=>$sumAsks[0], 'bid_quantity'=>$sumBids[0],
		    		'ask_amount'=>$sumAsks[1], 'bid_amount'=>$sumBids[1]];
	    	$result[$pair] = $item;
		}


	    return $result;
	}

	public function getTrades() {
    	include(MAINDIR.'data/exmo_pairs.php');

    	$pairs_a = explode(',', $pairs);
    	$queryURL = BINANCEURL.'://api.exmo.me/v1/trades/?pair=';
    	$result = [];
	    return $result;
	}
}
?>