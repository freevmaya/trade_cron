<?
define("BINANCEURL", 'https://www.binance.com/');
define("BINANCETRADELIMITS", 100);

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
		include(TRADEPATH.'data/binance_pairs.php');
		$result = [];
		$pairs = explode(',', $pairs);

	    foreach ($pairs as $pair) {
		    $queryURL = BINANCEURL.'api/v1/depth?symbol='.str_replace('_', '', $pair).'&limit='.BINANCETRADELIMITS;
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
		include(TRADEPATH.'data/binance_pairs.php');
    	$result = [];
		$pairs = explode(',', $pairs);

	    foreach ($pairs as $pair) {
		    $queryURL = BINANCEURL.'api/v1/trades?symbol='.str_replace('_', '', $pair).'&limit='.BINANCETRADELIMITS;
		    $data = json_decode(file_get_contents($queryURL), true);

		    $result[$pair] = $this->parseTrades($data, $pair);

		    if ($result[$pair]) {
		    	$pairA      				= explode('_', $pair);
                $result[$pair]['cur_in']  	= curID($pairA[0]);
                $result[$pair]['cur_out']  	= curID($pairA[1]);
				$this->prevTrades[$pair] 	= $result[$pair];
            }
		}
	    return $result;
	}	

	public function parseTrades($data, $pair) {
		$result = null;
		if ($data) {
			$result = isset($this->prevTrades[$pair])?$this->prevTrades[$pair]:['buy_price'=>0, 'sell_price'=>0, 'buy_volumes'=>0, 'sell_volumes'=>0];
	        $a_buy_price    = 0;
	        $a_sell_price   = 0;
	        $a_buy_volumes  = 0;
	        $a_sell_volumes = 0;
	        $sell_count     = 0;
	        $buy_count      = 0;
	        foreach ($data as $i=>$item) {
	            if ($item['isBuyerMaker']) {
	                if (($i == 0) || ($a_buy_price < $item['price'])) $a_buy_price = $item['price'];
	                $a_buy_volumes += $item['qty'];
	                $buy_count++;
	            } else {
	                if (($i == 0) || ($a_sell_price < $item['price'])) $a_sell_price = $item['price'];
	                $a_sell_volumes += $item['qty'];
	                $sell_count++;
	            }
	        }
	        $result['buy_price']    = ($buy_count>0)?$a_buy_price:$result['buy_price'];
	        $result['sell_price']   = ($sell_count>0)?$a_sell_price:$result['sell_price'];
	        $result['buy_volumes']  = ($buy_count>0)?$a_buy_volumes:$result['buy_volumes'];
	        $result['sell_volumes'] = ($sell_count>0)?$a_sell_volumes:$result['sell_volumes'];
	    }
	    return $result;
	}
}
?>