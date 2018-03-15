<?
class Queue {
	private $size;
	private $list;
	function __construct($size) {
		$this->size = $size;
		$this->list = array_fill(0, $this->size, 0);
	}

	public function push($val) {
		$this->list[] = $val;
		$this->list = array_slice($this->list, 1);
	}

	public function weighedAvg() {
		return varavg(array_reverse($this->list), 1);
	}

	public function size() {
		return $this->size;
	}
}
?>