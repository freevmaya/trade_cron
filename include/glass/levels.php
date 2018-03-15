<?
class Levels {
	protected $data;
	protected $pow;
	function __construct($data=null, $pow=2) {
		$this->pow = $pow;
		$this->setData($data);
		$this->top = new Queue(10);
		$this->down = new Queue(10);
	}	

	public function setData($a_data) {
		if (!function_exists('levels_sort')) {
			function levels_sort($itm1, $itm2) {
				return $itm2[0] - $itm1[1];
			}
		}

		$this->data = $a_data;
		for ($i=0;$i<count($this->data);$i++)
			if (isset($this->data[$i])) uasort($this->data[$i], 'levels_sort');
	}

	public function checkData() {
		return isset($this->data[0]) && isset($this->data[1]);
	}

	public function checkSupport($price) {
		$react = [];
		for ($i=0; $i<count($this->data[0]); $i++) {
			$item = $this->data[0][$i];
			if (($item[1] >= $price) && ($item[0] <= $price)) {
				$s = $item[1] - $item[0];
				$react[] = pow(1 - ($price - $item[0]) / $s, $this->pow);
			}
		}

		//print_r($react);
		$count = count($react);
		return $count?(array_sum($react) / $count):0;
	}

	public function checkResist($price) {
		$react = [];
		for ($i=0; $i<count($this->data[1]); $i++) {
			$item = $this->data[1][$i];
			if (($item[0] >= $price) && ($item[1] <= $price)) {
				$s = $item[0] - $item[1];
				$react[] = -pow(1 - ($item[0] - $price) / $s, $this->pow);
			}
		}

		//print_r($this->data[1]);

		$count = count($react);
		return $count?(array_sum($react) / $count):0;
	}

	public function check($price) {
		//echo $price.' '.$this->checkSupport($price).' '.$this->checkResist($price)."\n";
		return $this->checkResist($price) + $this->checkSupport($price);
	}
}
?>