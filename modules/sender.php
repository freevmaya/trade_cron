<?

class Sender {
	protected $user;
	protected $attempts;

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

	public function checkApiKey() {
		return ($this->user != null) && (@$this->user['secretApi']) && (@$this->user['keyApi']);
	}

	public function sell($pair, $data, $time) {
		$time = date('d H:i:s', $time);
		console::log("ACTION_SELL {$pair}");
		//print_r($data);
		return true;
	}

	public function buy($pair, $data, $time) {
		$time = date('d H:i:s', $time);
		console::log("ACTION_BUY {$pair}");
		//print_r($data);
		return true;
	}
}

?>