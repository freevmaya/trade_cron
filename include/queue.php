<?
class Queue {
	private $size;
	private $list;
	function __construct($size) {
		$this->size = $size;
		$this->list = [];
	}

	public function push($val) {
		$this->list[] = $val;
		if (count($this->list) > $this->size)
			$this->list = array_slice($this->list, 1);
	}

	public function weighedAvg() {
		return (count($this->list)>1)?varavg(array_reverse($this->list), 1):(@$this->list[0]);
	}

	public function isFull() {
		return $this->size <= count($this->list);
	}

	public function size() {
		return $this->size;
	}
}
?>