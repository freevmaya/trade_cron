<?
class obalanceTrigger extends baseTrigger {
	public function check($cur_in_id, $cur_out_id) {
		$result = false;
		if ($item = $this->dm->getCurrentOrder($cur_in_id, $cur_out_id)) {
			$all		= $item['ask_glass'] + $item['bid_glass'];
			$ba_ratio	= $item['ask_glass']/$all - $item['bid_glass']/$all;


			$result 	= (($ba_ratio >= $this->data['range']['min']) && ($ba_ratio <= $this->data['range']['max']));
		}
		return $result;
	}
}
?>