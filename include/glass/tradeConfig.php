<?
class tradeConfig {
	protected $fileName;
	protected $data;
	function __construct($fileName) {
		$this->fileName = $fileName;
		$this->readFile();
	}

	public function readFile() {
		$this->data = (file_exists($this->fileName))?json_decode(file_get_contents($this->fileName), true):[];
	}

	public function saveFile() {
		file_put_contents($this->fileName, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	public function get($paramName, $defValue=null) {
		return isset($this->data[$paramName])?$this->data[$paramName]:$defValue;
	}

	public function set($paramName, $value) {
		if ($value !== null) $this->data[$paramName] = $value;
		else unset($this->data[$paramName]);
		$this->saveFile();
	}
}
?>