<?
class hignerTrigger extends baseTrigger {
    public function setData($a_data) {
        if (is_string($a_data)) {
        	$a = explode(',', $a_data);
        	$a_data = array('value'=>$a[1]);
        }
        parent::setData($a_data);
    }

	public function check($cur_in_id, $cur_out_id) {
		$result = false;

		if ($item = $this->dm->getCurrentOrder($cur_in_id, $cur_out_id)) {
			//print_r($item[$this->priceType]);
			$result = $this->data['value'] <= $item[$this->priceType]; 
		}
		return $result;	
	}
}
?>