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
		$this->time = round($a_time / WAITTIME) * WAITTIME;
		$this->stime = date(DATEFORMAT, $this->time);
	}	

	public function serverTime() {
		return timeObject::sTime();
	}	

	public static function sTime() {
		return round(time() / WAITTIME) * WAITTIME;
	}
}
?>