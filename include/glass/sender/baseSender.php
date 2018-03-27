<?
class baseSender {
	protected $config;
	function __construct($config) {
		$this->config = $config;
		$this->init();
	}

	protected function init() {
	}

	public function balance($currency) {
		return 10000;
	}

	public function exchangeInfo($pair) {
		return null;
	}

	public function volumeFromBuy($pair, $price, $minVolumes, $komsa=0) {
		return $minVolumes;
	}

	public function volumeFromSell($pair, $price, $volume) {
		return $volume;
	}

	public function buy($pair, $volume, $price=0) {
		return ['success'=>1, 'executedQty'=>$volume, 'price'=>$price];
	}

	public function sell($pair, $volume, $price=0) {
		return ['success'=>1, 'executedQty'=>$volume, 'price'=>$price];
	}

	public function checkOrder($order) {
		return ['status'=>'FILLED'];
	}

	public function cancelOrder($order) {
		return true;
	}
}
?>