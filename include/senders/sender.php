<?

class Sender {
	protected $user;
	protected $attempts;
	protected $log;

	function __construct() {
		$this->user = null;
		$this->attempts = 0;
    }

	protected function isErrorResponse($dec) {
		return (($dec == null) || (isset($dec['error']) && ($dec['error'])));
	}

	public function setUser($user) {
		$this->user = $user;
	}

	public function setLog($log) {
		$this->log = $log;
	}

	public function checkApiKey() {
		return ($this->user != null) && (@$this->user['secretApi']) && (@$this->user['keyApi']);
	}

	public function sell($pair, $data, $time, $top_order) {
		$time = date('d H:i:s', $time);
		$this->log->logRecord("ACTION_SELL {$pair}");
		return array(
				'pair'=>$pair,
				"price"=>$top_order['bid_top'],
				"quantity"=>$data['volume']);
	}

	public function buy($pair, $data, $time, $top_order) {
		$time = date('d H:i:s', $time);
		$this->log->logRecord("ACTION_BUY {$pair}");
		return array(
				'pair'=>$pair,
				"price"=>$top_order['ask_top'],
				"quantity"=>$data['volume']);
	}

	public function message($pair, $data, $time, $top_order) {
		$time = date('d H:i:s', $time);
		$this->log->logRecord("ACTION_MESSAGE {$pair}");
		return array(
				'error'=>'test error',
				'pair'=>$pair,
				"price"=>($top_order['ask_top'] + $top_order['bid_top']) / 2,
				"quantity"=>$data['volume']);
	}  

	public function sell_test($pair, $data, $time, $top_order) {   
		return $this->sell($pair, $data, $time, $top_order);
	}

	public function buy_test($pair, $data, $time, $top_order) {
		return $this->buy($pair, $data, $time, $top_order);
	}

	public function message_test($pair, $data, $time, $top_order) {
		return $this->message($pair, $data, $time, $top_order);
	}
}

?>