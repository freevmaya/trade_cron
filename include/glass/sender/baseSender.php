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

	}

	public function sell($symbol, $volume, $price=0) {
		
	}
}
?>