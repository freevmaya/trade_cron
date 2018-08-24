<?
class tradeView extends restClient {
	public function __construct() {
		parent::__construct('https://scanner.tradingview.com/crypto/');
   	}

	public function recommend($market, $pair, $interval="240") {
	    $columns = [
	        "Recommend.Other",
	        "Recommend.All",
	        "Recommend.MA"
	    ];

	    $colsTmp = [];
	    foreach ($columns as $column) {
	        $colsTmp[] = $column.'|'.$interval;
	    }

	    $query = '{"symbols":{"tickers":["'.$market.':'.$pair.'"],"query":{"types":[]}},"columns":'.json_encode($colsTmp).'}';

	    $data = [];

	    $result = $this->httpRequest('scan', "POST", $query);
	    if (isset($result['data']) && isset($result['data'][0])) {
	    	foreach ($columns as $i=>$column) $data[$column] = $result['data'][0]['d'][$i];
	    } else {
	    	echo "ERROR received data tradingview.com from {$pair}\n";
	    }

	    return $data;
	}
}
?>