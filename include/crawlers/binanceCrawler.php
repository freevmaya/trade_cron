<?
define("BINANCEURL", 'https://www.binance.com/');

class binanceCrawler extends baseCrawler {
	protected $prevTrades;
	protected $symbols;
	protected $list;

	function __construct() {
		$this->prevTrades = [];
		if ($this->list = $this->getExchangeInfo()) {
			foreach ($this->list['symbols'] as $item) {
				$this->symbols[] = $item['symbol'];
			}
		}
	}

	protected function getExchangeInfo() {
		return json_decode(file_get_contents(BINANCEURL."api/v1/exchangeInfo"), true);
	}

	public function getOrders() {
		$result = ['ask', 'bid'];
	    foreach ($this->symbols as $symbol) {
		    $queryURL = BINANCEURL.'api/v1/depth?symbol='.$symbol.'&limit=500';
		    $data = json_decode(file_get_contents($queryURL), true);
		    $item = [/*'ask'=>$data['asks'], 'bid'=>$data['bids'], */'ask_top'=>0, 'bid_top'=>100000000];

	    	foreach ($data['asks'] as $ai) {
	    		$item['ask_top'] = max($ai[0], $item['ask_top']);
	    		$item['bid_top'] = min($ai[1], $item['bid_top']);
	    	}

	    	$result[$symbol] = $item;
		}

	    print_r($result);
	    exit;
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