<?
class exmoCrawler extends baseCrawler {
	protected $prevTrades;

	function __construct() {
		$this->prevTrades = [];
	}

	public function getOrders() {
    	include(MAINDIR.'data/exmo_pairs.php');
	    $queryURL = 'https://api.exmo.me/v1/order_book?limit=100&pair='.$pairs;
	    return @json_decode(file_get_contents($queryURL), true);
	}

	public function getTrades() {
    	include(MAINDIR.'data/exmo_pairs.php');

    	$pairs_a = explode(',', $pairs);
    	$queryURL = 'https://api.exmo.me/v1/trades/?pair=';
    	$result = [];

    	foreach ($pairs_a as $pair) {
	        if ($data = @json_decode(file_get_contents($queryURL.$pair), true)) {
	            if (isset($data['error']) && $data['error']) {
	                console::log($data['error']);
	            } else {
	                if ($result[$pair] = $this->parseExmoTrades($data, $pair)) {
	                    $pairA      				= explode('_', $pair);
	                    $result[$pair]['cur_in']  	= curID($pairA[0]);
	                    $result[$pair]['cur_out']  	= curID($pairA[1]);
						$this->prevTrades[$pair] 	= $result[$pair];
	                }
	            }
	        }
	    }

	    return $result;
	}	

	public function parseExmoTrades($data, $pair) {
		$result = null;
		if (isset($data[$pair]) && (is_array($data[$pair]))) {
			$result = isset($this->prevTrades[$pair])?$this->prevTrades[$pair]:['buy_price'=>0, 'sell_price'=>0, 'buy_volumes'=>0, 'sell_volumes'=>0];
	        $a_buy_price    = 0;
	        $a_sell_price   = 0;
	        $a_buy_volumes  = 0;
	        $a_sell_volumes = 0;
	        $sell_count     = 0;
	        $buy_count      = 0;
	        foreach ($data[$pair] as $i=>$item) {
	            $t = $item['type']; 
	            if ($t == 'sell') {
	                if (($i == 0) || ($a_sell_price < $item['price'])) $a_sell_price = $item['price'];
	                $a_sell_volumes += $item['quantity'];
	                $sell_count++;
	            } else {
	                if (($i == 0) || ($a_buy_price < $item['price'])) $a_buy_price = $item['price'];
	                $a_buy_volumes += $item['quantity'];
	                $buy_count++;
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