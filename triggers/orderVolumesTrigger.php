<?
class orderVolumesTrigger extends baseTrigger {
	protected function check($cur_in_id, $cur_out_id) {
		$result = false;
		if ($item = $this->getCurrentTrade($cur_in_id, $cur_out_id)) {
			$volumes = new Volumes($item['id']);
			$ba_ratio = $volumes->baRatio();
			$result = (($ba_ratio >= $this->data['baRatio']['min']) && ($ba_ratio <= $this->data['baRatio']['max']));

			if (isState($this->data, 'snapshot')) {
				$result = array(
					'ba_ratio'=>$ba_ratio,
					'debugInfo'=>$volumes->debugInfo(),
					'result'=>$result?1:0
				);
			}
		}
		return $result;

}
?>