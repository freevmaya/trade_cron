<?
class baseSender {
	protected $config;
	public $test = false;
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

	public function buy($pair, $volume, $price=0, $take_profit=0, $stop_loss=0) {
		return ['orderId'=>rand(0, 10000), 'symbol'=>$pair, 'success'=>1, 'executedQty'=>$volume, 'price'=>$price, 'status'=>'FILLED'];
	}

	public function sell($pair, $volume, $price=0) {
		return ['orderId'=>rand(0, 10000), 'symbol'=>$pair, 'success'=>1, 'executedQty'=>$volume, 'price'=>$price, 'status'=>'FILLED'];
	}

	public function checkOrder($order) {
		return ['status'=>'FILLED'];
	}

	public function cancelOrder($order) {
		return true;
	}
}
?>