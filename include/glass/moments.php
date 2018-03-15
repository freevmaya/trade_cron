<?
class Moments {
	protected $top;
	protected $down;
	protected $prevPrice;
	function __construct($tops=[], $downs=[]) {
		$this->top = new Queue(count($tops));
		$this->down = new Queue(count($downs));
		foreach ($tops as $top) $this->top->push($top);
		foreach ($downs as $down) $this->down->push($down);
	}

	public function pushPrice($price) {
		$res = 0;
		if ($this->prevPrice != $price) {
			$res = $this->prevPrice - $price;
			if ($res > 0) $this->top->push($res);
			else if ($res < 0) $this->down->push($res);

			$this->prevPrice = $price;
		}

		return $res;
	}

	public function top() {
		return $this->top->weighedAvg();
	}

	public function down() {
		return $this->down->weighedAvg();
	}

	public function value() {
		return $this->top() + $this->down();
	}
}
?>