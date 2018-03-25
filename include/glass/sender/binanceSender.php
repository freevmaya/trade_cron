<?
class binanceSender extends baseSender {
	private $api;
	protected function init() {
		$this->api = new Binance\API();	
	}

	public function buy($symbol, $volume, $price=0) {

	}

	public function sell($symbol, $volume, $price=0) {
		
	}
}
?>