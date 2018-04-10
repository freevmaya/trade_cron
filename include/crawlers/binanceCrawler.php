<?
define("BINANCEURL", 'https://www.binance.com/');
define("BINANCETRADELIMITS", 200);
define("BINANCEORDERLIMITS", 100);
include_once(MAINDIR.'include/php-binance-api.php');

class binanceCrawler extends baseCrawler {
	protected $prevTrades;
	protected $symbols;
	protected $info;
	protected $pairs;
	protected $api;

	function __construct($a_pairs = null) {
		$this->api = new Binance\API();	

		$this->prevTrades = [];
		//$this->info = $this->getExchangeInfo();

		if (!$a_pairs) {
			include(TRADEPATH.'data/binance_pairs.php');
			$this->pairs = explode(',', $pairs);
		} else $this->pairs = $a_pairs;
	}

	public function refreshExchangeInfo() {
		$this->info = $this->api->exchangeInfo();
	}

	public function getActualPairs($rightCyrrency='BTC') {
		$symbols = [];
		if ($this->info) {
			foreach ($this->info['symbols'] as $item) {
				$len = strlen($item['baseAsset']);
				$right = substr($item['symbol'], $len);
				if ($right == $rightCyrrency) {
					$symbols[$item['baseAsset']] = $item['baseAsset'].'_'.$right;
				}
			}
		}

		return $symbols;
	}

	public function getInfo($symbol) {
		foreach ($this->info['symbols'] as $item) {
			if ($item['symbol'] == $symbol) {
				foreach ($item['filters'] as $filter) {
					$item['filters'][$filter['filterType']] = $filter;
				}
				return $item;
			}
		}
	}

	public function ticker($symbol) {
		return $this->api->prevDay($symbol);
	}

	public function getTradedWith($baseCurrencyList) {
		$this->info = $this->api->exchangeInfo();
		$list = [];
		$result = [];
		$curs = [];

		foreach ($baseCurrencyList as $right) {
			$list[$right] = $this->getActualPairs($right);
			$curs = array_merge(array_keys($list[$right]));
		}

		$curs = array_unique($curs);

		foreach ($curs as $cur) {
			$count = 0;
			foreach ($baseCurrencyList as $right) {
				if (isset($list[$right][$cur])) $count++;
			}
			if ($count == count($list)) $result[] = $cur;
		}

		return $result;
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
		$result = [];

		if (($list = $this->getOrderList()) && !isset($list['error'])) {
		    foreach ($list as $pair=>$data) {
			    $sumAsks = $this->sum($data['asks']);
			    $sumBids = $this->sum($data['bids']);

			    $item = ['ask'=>$data['asks'], 'bid'=>$data['bids'], 
			    		'ask_top'=>$data['asks'][0][0], 'bid_top'=>$data['bids'][0][0],
			    		'ask_quantity'=>$sumAsks[0], 'bid_quantity'=>$sumBids[0],
			    		'ask_amount'=>$sumAsks[1], 'bid_amount'=>$sumBids[1]];
		    	$result[$pair] = $item;
			}
		}

	    return $result;
	}

	public function getOrderList($pairs=null) {
		$result = [];

		$pairs = $pairs?$pairs:$this->pairs;

	    foreach ($pairs as $pair) {
//		    $queryURL = BINANCEURL.'api/v1/depth?symbol='.str_replace('_', '', $pair).'&limit='.BINANCEORDERLIMITS;
		    if (($item = $this->api->depthRequest($this->paitToSymbol($pair)))) {
		    	$result[$pair] = $item;
		    } else return $item; 
		}

	    return $result;
	}

	protected function checkError($data) {
		if (isset($data['code'])) {
			return ['error'=>$data['msg'], 'error_code'=>$data['code']];
		} else return null;
	}

	public function getTrades($pairs=null) {
    	$result = [];
    	$list = $this->getTradeList($pairs);

	    foreach ($list as $pair=>$item) {
		    $result[$pair] = $this->parseTrades($item, $pair);
		    if ($result[$pair]) $this->prevTrades[$pair] = $result[$pair];
		}

	    return $result;
	}

	protected function paitToSymbol($pair) {
		return str_replace('_', '', $pair);
	}

	public function getTradeList($pairs=null) {
		$result = [];

		$pairs = $pairs?$pairs:$this->pairs;

	    foreach ($pairs as $pair) {
		    //$queryURL = BINANCEURL.'api/v1/trades?symbol='.$this->paitToSymbol($pair).'&limit='.BINANCETRADELIMITS;

		    if (($data = $this->api->getTrades($this->paitToSymbol($pair), BINANCETRADELIMITS))) {
			    $result[$pair] = $data;
	        } else return $data;
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

	/* Response
	[
	  [
	    1499040000000,      // Open time
	    "0.01634790",       // Open
	    "0.80000000",       // High
	    "0.01575800",       // Low
	    "0.01577100",       // Close
	    "148976.11427815",  // Volume
	    1499644799999,      // Close time
	    "2434.19055334",    // Quote asset volume
	    308,                // Number of trades
	    "1756.87402397",    // Taker buy base asset volume
	    "28.46694368",      // Taker buy quote asset volume
	    "17928899.62484339" // Ignore
	  ]
	]
	*/
	public function candles($pair, $interval='30m', $startTime=0, $endTime=0, $limit=0) {
		return $this->api->candles($this->paitToSymbol($pair), $interval, $limit, $startTime * 1000, $endTime * 1000);
	}

	/*
	Response:

	{
	  "symbol": "BNBBTC",
	  "priceChange": "-94.99999800",
	  "priceChangePercent": "-95.960",
	  "weightedAvgPrice": "0.29628482",
	  "prevClosePrice": "0.10002000",
	  "lastPrice": "4.00000200",
	  "lastQty": "200.00000000",
	  "bidPrice": "4.00000000",
	  "askPrice": "4.00000200",
	  "openPrice": "99.00000000",
	  "highPrice": "100.00000000",
	  "lowPrice": "0.10000000",
	  "volume": "8913.30000000",
	  "quoteVolume": "15.30000000",
	  "openTime": 1499783499040,
	  "closeTime": 1499869899040,
	  "fristId": 28385,   // First tradeId
	  "lastId": 28460,    // Last tradeId
	  "count": 76         // Trade count
	}
	*/
	public function changeStat($pair) {
		return $this->api->prevDay($this->paitToSymbol($pair));
	}
}
?>