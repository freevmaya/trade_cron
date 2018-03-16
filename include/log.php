<?
class Log {
	protected $fileName;
	function __construct($fileName) {
		$this->fileName = $fileName;
	}

	public function log($msg) {
		fdbg::trace($msg, $topCalled=2, $this->fileName);
	}
}
?>