<?
class timeTrigger extends baseTrigger {
	public function check($cur_in_id, $cur_out_id) {
		$start_time = strtotime(date('Y-m-d '.$this->data['start']));
		$end_time = strtotime(date('Y-m-d '.$this->data['end']));

		console::log(date('Y-m-d '.$this->data['start']).' - '.date('Y-m-d '.$this->data['end']));
		$result = ($start_time <= $this->time) && ($this->time <= $end_time);
		return $result;	
	}
}
?>