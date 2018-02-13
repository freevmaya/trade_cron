<?

define('WORKDIR', '/home/cron/currency');

class exmoSender extends Sender {
	protected $user_info;

	public function api_query($api_name, array $req = array()) 	{
	    $url = "http://api.exmo.me/v1/$api_name";
		$dec = null;
	    $mt = explode(' ', microtime());
	    $NONCE = $mt[1] . substr($mt[0], 2, 6);

	    $req['nonce'] = $NONCE;

	    // generate the POST data string
	    static $ch = null;
	    if (is_null($ch)) {
	        $ch = curl_init();
	        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; PHP client; ' . php_uname('s') . '; PHP/' . phpversion() . ')');
	    }

	    $post_data = http_build_query($req, '', '&');
		if ($this->checkApiKey()) {
		    $sign = hash_hmac('sha512', $post_data, $this->user['secretApi']);
		    $headers = array(
		        'Sign: ' . $sign,
		        'Key: ' . $this->user['keyApi'],
		    );
		    //print_r($this->user);
		    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}

	    curl_setopt($ch, CURLOPT_URL, $url);
	    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

	    // run the query
	    $res = curl_exec($ch);
	    if ($res === false) throw console::log('Could not get reply:'. curl_error($ch), 'error');
	   
	    $dec = json_decode($res, true);

	    if ($this->isErrorResponse($dec)) {
	    	if (isset($dec['error'])) {
	    		console::log('api_name: '.$api_name.', response error: '.$dec['error'], 'error');
	    	}
	    	if (strpos($res, '502 Bad Gateway') > -1) {
	    		if ($this->attempts < 10) {
	    			usleep(100000);
	    			$this->attempts++;
	    			$dec = $this->api_query($api_name, $req);
	    		}
	    	}
	    	return null;
	    } else {
	    	usleep(100000);
	    	$this->attempts = 0;
	    }

	    return $dec;
	}

	protected function order_create_tester($params) {
		console::log('CREATE ORDER TO TESTER:', 'msg');
		console::log($params, 'info');
		return $params;
	}

	protected function isTester() {
		return in_array('tester', $this->user['states']);
	}

	protected function order_create($pair, $volume, $type, $price=0) {
		$params = array(
				'pair'=>$pair,
				"price"=>$price,
				"quantity"=>$volume, 
				"type"=>$type);
		if ($this->isTester()) $result = $this->order_create_tester($params);
		else $result = $this->api_query('order_create', $params);
		//console::log($result);		

		return !$this->isErrorResponse($result)?$params:null;
	} 

	protected function refreshUserInfo() {
		return $this->user_info = $this->api_query('user_info', array());
	}

	protected function notenough($cur, $require) {
		console::log("Not enough balance {$cur}, require: {$require}", 'error');
	}

	protected function checkBalanceForSell($curA, $data, $curPrice) {
		$cur = $curA[0];
		$vola = explode('/', $data['volume']);

		if (isset($vola[1]) && ($curA[1] == $vola[1])) {
			$cur = $curA[1];
			$balance = $this->user_info['balances'][$cur];
			$volume = cnvValue($vola[0], $balance) / $curPrice;
		} else {
			$balance = $this->user_info['balances'][$cur];
			$volume= cnvValue($vola[0], $balance);
		}

		if ($balance > 0) {
			if (((!isset($data['min']) || ($volume >= $data['min'])) && 
				 (!isset($data['max']) || ($volume <= $data['max']))) && ($balance >= $volume)) {
				return $volume;
			} else $this->notenough($cur, $volume);
		} else $this->notenough($cur, $vola[0]);
		return 0;
	}

	protected function checkBalanceForBuy($curA, $data, $curPrice) {
		
		$vola = explode('/', $data['volume']);
		$cur = $curA[1];
		$balance = $this->user_info['balances'][$cur];
		$volume = cnvValue($vola[0], $balance) / $curPrice;

		if ($balance > 0) {
			if (((!isset($data['min']) || ($volume >= $data['min'])) && 
				 (!isset($data['max']) || ($volume <= $data['max']))) && ($balance >= $volume)) {
				return $volume;
			} else $this->notenough($cur, $volume);
		} else $this->notenough($cur, $vola[0]);
		return 0;
	}

	public function sell($pair, $data, $time, $top_order) {
		$result = null;
		$currency = explode('_', $pair);
		$time = date('d H:i:s', $time);
		console::log("ACTION_SELL {$pair}", 'info');

		if ($this->checkApiKey()) {
			if ($this->refreshUserInfo()) {
				if ($volume = $this->checkBalanceForSell($currency, $data, $top_order['bid_top'])) {
					$result = $this->order_create($pair, $volume, "market_sell");
				}
			}
		} else if ($this->isTester()) {
			$result = $this->order_create($pair, $data['volume'], "market_sell");
		} else {
			console::log("NOAPIKEY user: {$this->user['uid']}", 'error');
		}

		return $result;
	}

	public function buy($pair, $data, $time, $top_order) {
		$result = null;
		$currency = explode('_', $pair);
		$time = date('d H:i:s', $time);
		console::log("ACTION_BUY {$pair}");

		if ($this->checkApiKey()) {
			if ($this->refreshUserInfo()) {
				if ($volume = $this->checkBalanceForBuy($currency, $data, $top_order['ask_top'])) {
					$result = $this->order_create($pair, $volume, "market_buy");
				}
			}
		} else if ($this->isTester()) {
			$result = $this->order_create($pair, $data['volume'], "market_buy");
		} else {
			console::log("NOAPIKEY user: {$this->user['uid']}", 'error');
		}

		return $result;
	} 

	public function sell_test($pair, $data, $time, $top_order) {   
		return array(
				'pair'=>$pair,
				"price"=>$top_order['bid_top'],
				"quantity"=>$data['volume']);
	}

	public function buy_test($pair, $data, $time, $top_order) {
		return array(
				'pair'=>$pair,
				"price"=>$top_order['ask_top'],
				"quantity"=>$data['volume']);
	}
}

?>