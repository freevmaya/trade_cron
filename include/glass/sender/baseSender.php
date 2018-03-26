<?
class baseSender {
	protected $config;
	function __construct($config) {
		$this->config = $config;
		$this->init();
	}

	protected function init() {

	}

	public function buy($symbol, $volume, $price=0) {
		return 1;
	}

	public function sell($symbol, $volume, $price=0) {
		return 1;
	}

	public function checkOrder($order_id) {
		return 0;
	}
}
?>