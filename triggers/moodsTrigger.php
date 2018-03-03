<?
class moodsTrigger extends baseTrigger {
	public function check($cur_in_id, $cur_out_id) {
		$result = false;
		if ($item = $this->dm->getCurrentTrade($cur_in_id, $cur_out_id)) {
			$ddata = $this->data['range_percent'];
	       	if (is_array($ddata)) {
		       	$min_a = $ddata['min'];
		       	$max_a = $ddata['max'];

		       	$ddata['min'] = min($min_a, $max_a);
		       	$ddata['max'] = max($min_a, $max_a);

		       	$all = $item['buy_volumes'] + $item['sell_volumes'];
		       	$balance = $item['buy_volumes']/$all - $item['sell_volumes']/$all;

				$result = ($balance >= $ddata['min']) && ($balance <= $ddata['max']);
			}
			
		}
		return $result;		
	}
}
?>