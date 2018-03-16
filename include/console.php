<?
GLOBAL $console;

class console {
	protected $display;
	protected static $instance;
	protected static $prevMsg;
	protected static $norepeat;

	function __construct($to_display=true, $norepeat=true) {
		GLOBAL $console;
		console::$instance = $this;
		console::$norepeat = $norepeat;
		$this->display = $to_display;
    }

	public static function log($data, $type='info') {
		if (!console::$norepeat || (console::$prevMsg != $data)) {
			if (console::$instance) console::$instance->ouput($data, $type);		
			else {
				print_r($data);
				echo "\n";
			}
			console::$prevMsg = $data;
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