<?
class timeObject {
	protected $time;
	protected $stime;	

	function __construct($time=0) {
		$this->setTime($time);
	}

	public function setTime($time=0) {
		if ($time == 0) $a_time = time();
		else $a_time = is_string($time)?strtotime($time):$time; 
		$this->time = $this->correctTime($a_time);
		$this->stime = date(DATEFORMAT, $this->time);
	}

	protected function correctTime($a_time) {
		return ceil($a_time / WAITTIME) * WAITTIME;
	}

	public function serverTime() {
		return timeObject::sTime();
	}	

	public static function sTime() {
		return ceil(time() / WAITTIME) * WAITTIME;
	}

	public function trace($obj) {
		echo $this->stime.' ';
		if ($obj) {
			if (is_numeric($obj) || is_string($obj)) echo $obj."\n";
			else print_r($obj);
		} else echo "empty or 0\n";
	}
}
?>