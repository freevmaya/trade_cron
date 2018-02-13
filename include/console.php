<?
GLOBAL $console;

class console {
	protected $display;
	protected $uid;
	protected $dm;
	function __construct($to_display=true, $dm=null) {
		GLOBAL $console;
		$console = $this;
		$this->display = $to_display;
		$this->dm = $dm;
    }

	public static function log($data, $type='info') {
		GLOBAL $console;
		$console->ouput($data, $type);		
	}

	public static function clearUID() {
		GLOBAL $console;
		$console->uid = false;
	}

    public static function setUID($uid) {
    	GLOBAL $console;
    	$console->uid = $uid;
    }

	protected function ouput($data, $type='info') {
		if ($this->display) {
			print_r($data);
			echo "\n";
		} 
		trace($data, 'file', 4);
		if (!is_string($data)) $data = json_encode($data);
		$this->sendAsEvent($data, $type);
	}

	protected function sendAsEvent($data_str, $type='info') {
		if ($this->uid && $this->dm) 
			$this->dm->addUserEvent($this->uid, 'CONSOLE', $data_str, $type);
	}
}
?>