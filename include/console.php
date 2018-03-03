<?
GLOBAL $console;

class console {
	protected $display;
	protected static $instance;

	function __construct($to_display=true, $dm=null) {
		GLOBAL $console;
		console::$instance = $this;
		$this->display = $to_display;
		$this->dm = $dm;
    }

	public static function log($data, $type='info') {
		if (console::$instance) console::$instance->ouput($data, $type);		
		else {
			print_r($data);
			echo "\n";
		}
	}

	protected function ouput($data, $type='info') {
		if ($this->display) {
			print_r($data);
			echo "\n";
		} 
		trace($data, 'file', 4);
	}
}
?>