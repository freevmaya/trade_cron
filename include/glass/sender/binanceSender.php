<?

function binanceCallback($api, $balances) {
			print_r($balances);
}

class binanceSender extends baseSender {
	private $api;
	private $account;
	private $info;
	private $lastPrices;
	protected function init() {
		$this->api = new Binance\API($this->config['APIKEY'], $this->config['APISECRET'], ['useServerTime'=>true]);
		$this->resetAccount();
		$this->info = $this->api->exchangeInfo();
	}

	public function resetAccount() {
		$account = $this->api->account();
		if (!isset($account['balances'])) {
			if ($this->account) {
				echo "ERROR Account response\n";
				print_r($account);
			} else throw new Exception("ERROR Account response ".(@$account['code']), 1);
		} else $this->account = $account;
	}

	public function resetPrices() {
		$this->lastPrices = $this->api->prices();
	}

	public function calcBalance($currency='BTC') {
		$balance = 0;
		if (isset($this->account['balances'])) {
			if (!$this->lastPrices) $this->resetPrices();
			
			foreach ($this->account['balances'] as $item) {
				if (($vol = $item['free'] + $item['locked']) > 0) {
					$asset = $item['asset'];
					$pair = $asset.$currency;
					if ($asset == $currency) $balance += $vol;
					else if (isset($this->lastPrices[$pair])) {
						$balance += $this->lastPrices[$pair] * $vol;
					} else {
						$pair = $currency.$asset;
						if (isset($this->lastPrices[$pair])) {
							$balance += $vol / $this->lastPrices[$pair];
						}
					}
				}
			}
		}
		return $balance;
	}

	public function getAccount() {
		return $this->account;
	}

	protected function useServerTime() {
		$this->api->useServerTime();
	}

	public function serverTime() {
		return $this->api->serverTime();
	}

	public function getApi() {
		return $this->api;
	}


	public function balance($currency) {
		if (!isset($this->account['balances'])) $this->resetAccount();

		foreach ($this->account['balances'] as $item)
			if ($item['asset'] == $currency) return floatval($item['free']);
		return 0;
	}

	public function addBalance($currency, $addValue) {
		foreach ($this->account['balances'] as $item)
			if ($item['asset'] == $currency) {
				$item['free'] = floatval($item['free']) + $addValue;
				break;
			}
	}

	public function exchangeInfo($pair) {
		$symbol = str_replace('_', '', $pair);
		foreach ($this->info['symbols'] as $item)
			if ($item['symbol'] == $symbol) {
				foreach ($item['filters'] as $filter) {
					$item['filters'][$filter['filterType']] = $filter;
				}
				return $item;
			}
		return null;
	}

	public function volumeFromBuy($pair, $price, $minVolumes, $komsa=0) {
		if ($minVolumes > 0) {
			$pairA = explode('_', $pair);
			$info = $this->exchangeInfo($pair);
			if ($info && ($info['status'] == 'TRADING')) {
				$lotsize = $info['filters']['LOT_SIZE'];
				$minnot = $info['filters']['MIN_NOTIONAL']; 
				$stepSize = floatval($lotsize['stepSize']);

				$volume = floatval($minnot['minNotional']) / $price * $minVolumes;
				$volume = floor($volume / $stepSize) * $stepSize;
				if ($komsa) $volume += ceil(($volume * $komsa) / $stepSize) * $stepSize;

				if (($volume >= floatval($lotsize['minQty'])) && ($volume <= floatval($lotsize['maxQty']))) return $volume;
				else echo "Require {$lotsize['minQty']} > volume < {$lotsize['maxQty']}\n";//print_r($info['filters']);
			}
		}
		return 0;
	}

	public function volumeFromSell($pair, $price, $volume) {
		return $volume;
	}

	public function roundPrice($pair, $price) {
		if ($price > 0) {
			$info = $this->exchangeInfo($pair);
			if ($info['filters']['PRICE_FILTER']) {
				$ts = $info['filters']['PRICE_FILTER']['tickSize'];
				return round($price / $ts) * $ts;
			}
		}
		return $price;
	}

	public function buy($pair, $volume, $price=0, $take_profit=0, $stop_loss=0) {
		$symbol = str_replace('_', '', $pair);
		if ($this->test) {
			//$this->api->buyTest($symbol, $volume, $price, ($price==0)?'MARKET':'LIMIT');

			$result = parent::buy($symbol, $volume, $price, $take_profit, $stop_loss);
		} else {
			if (($price > 0) && ($take_profit > 0)) {
				$result = $this->api->buy($symbol, $volume, 0, 'TAKE_PROFIT', [
					'stopPrice'=>$take_profit
				]);
			} else {
				$result = $this->api->buy($symbol, $volume, $this->roundPrice($symbol, $price), ($price==0)?'MARKET':'LIMIT');//, ["icebergQty"=>0]);
			}
		}

		return $result;
	}

	public function sell($pair, $volume, $price=0) {
		$symbol = str_replace('_', '', $pair);
		if ($this->test) {
			//$this->api->sellTest($symbol, $volume, $price, ($price==0)?'MARKET':'LIMIT');
			$result 	= parent::sell($symbol, $volume, $price);
		} else {
			$result 	= $this->api->sell($symbol, $volume, $this->roundPrice($symbol, $price), ($price==0)?'MARKET':'LIMIT');
		}

		return $result;
	}

	public function checkOrder($order) {
		return $this->api->orderStatus($order['symbol'], $order['orderId']);
	}

	public function cancelOrder($order) {
		if ($this->test) return true;
		$result = false;
		if (isset($order['symbol'])) {
			$cancelResult = $this->api->cancel($order['symbol'], $order['orderId']);
			if (!isset($cancelResult['code'])) {
        		$this->resetAccount();
        		$result = true;
        	}
    	}
        return $result;
	}
}
?>