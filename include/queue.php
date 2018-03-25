<?
class Queue {
	protected $size;
	protected $list;
	function __construct($size) {
		$this->size = $size;
		$this->list = [];
	}

	public function push($val) {
		$this->list[] = $val;
		if (count($this->list) > $this->size)
			$this->list = array_slice($this->list, 1);
	}

	public function trends() {
		$last = varavg(array_reverse($this->list), 1);
		$prev = varavg(array_reverse($this->list), -1);

		return $last - $prev;
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

	public function get($index) {
		return $this->list[$index];
	}
}
?>