<?

class api_sender extends Sender {
	protected $user_info;

//Эти методы переопределяются в потомках
	
	public function api_query($api_name, array $req = array()) 	{
	    return null;
	}

	public function order_create_api($params) {
		return null;
	}

//-----------------------------------------	

	protected function order_create_tester($params) {
		$this->log->logRecord('CREATE ORDER TO TESTER:', 'msg');
		$this->log->logRecord($params, 'info');
		return $params;
	}

	protected function isTester() {
		return in_array('tester', object_to_array($this->user['states']));
	}

	protected function order_create($pair, $volume, $type, $price=0) {
		$params = array(
				'pair'=>$pair,
				"price"=>$price,
				"quantity"=>$volume, 
				"type"=>$type);
		if ($this->isTester()) $result = $this->order_create_tester($params);
		else $result = $this->order_create_api($params);

		if (!isset($result['error'])) {
			$result['quantity'] = $volume;
			$result['price'] = (!isset($result['price']) || !$result['price'])?$price:$result['price'];
		}
		//$this->log->logRecord($result);		


		return $result;
	} 

	protected function refreshUserInfo() {
		return $this->user_info = $this->api_query('user_info', array());
	}

	protected function notenough($cur, $require) {
		return ['error'=>"Not enough balance {$cur}, require: {$require}"];
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

		$result = false;

		if ($balance > 0) {
			if (((!isset($data['min']) || ($volume >= $data['min'])) && 
				 (!isset($data['max']) || ($volume <= $data['max']))) && ($balance >= $volume)) {
				$result = $volume;
			} else $result = $this->notenough($cur, $volume);
		} else $result = $this->notenough($cur, $vola[0]);
		return $result;
	}

	protected function checkBalanceForBuy($curA, $data, $curPrice) {
		
		$vola = explode('/', $data['volume']);
		$cur = $curA[1];
		$balance = $this->user_info['balances'][$cur];
		$volume = cnvValue($vola[0], $balance) / $curPrice;

		$result = false;

		if ($balance > 0) {
			if (((!isset($data['min']) || ($volume >= $data['min'])) && 
				 (!isset($data['max']) || ($volume <= $data['max']))) && ($balance >= $volume)) {
				$result = $volume;
			} else $result = $this->notenough($cur, $volume);
		} else $result = $this->notenough($cur, $vola[0]);

		return $result;
	}

	public function sell($pair, $data, $time, $top_order) {
		$result = null;
		$currency = explode('_', $pair);
		$time = date('d H:i:s', $time);
		$this->log->logRecord("ACTION_SELL {$pair}", 'info');

		if ($this->checkApiKey()) {
			if ($this->refreshUserInfo()) {
				$def_price = $top_order['bid_top'];
				if (is_numeric($volume = $this->checkBalanceForSell($currency, $data, $def_price))) {
					$result = $this->order_create($pair, $volume, "market_sell");
					if (!$result['price']) $result['price'] = $def_price;
				} else $result = $volume;
			}
		} else if ($this->isTester()) {
			$result = $this->order_create($pair, $data['volume'], "market_sell");
		} else {
			$this->log->logRecord("NOAPIKEY user: {$this->user['uid']}", 'error');
		}

		return $result;
	}

	public function buy($pair, $data, $time, $top_order) {
		$result = null;
		$currency = explode('_', $pair);
		$time = date('d H:i:s', $time);
		$this->log->logRecord("ACTION_BUY {$pair}");

		if ($this->checkApiKey()) {
			if ($this->refreshUserInfo()) {
				$def_price = $top_order['ask_top'];
				if (is_numeric($volume = $this->checkBalanceForBuy($currency, $data, $def_price))) {
					$result = $this->order_create($pair, $volume, "market_buy");
					if (!$result['price']) $result['price'] = $def_price;
				} else $result = $volume;
			}
		} else if ($this->isTester()) {
			$result = $this->order_create($pair, $data['volume'], "market_buy");
		} else {
			$this->log->logRecord("NOAPIKEY user: {$this->user['uid']}", 'error');
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