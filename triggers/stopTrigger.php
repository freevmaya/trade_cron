<?
class stopTrigger extends baseTrigger {

	public function check($cur_in_id, $cur_out_id) {
		$result = false;

		$item = $this->dm->getCurrentOrder($cur_in_id, $cur_out_id);
		$price = @$item[$this->priceType];
		$prev_price = $this->getStateVar('prev_price');

		if ($price != $prev_price) {
			//echo "INTERVAL: $price - $prev_price, VALUE: {$this->data['value']}\n";
			if ($prev_price && $price) {
				if ($price > $prev_price)
					$result = ($prev_price <= $this->data['value']) && ($price >= $this->data['value']); 
				else $result = ($price <= $this->data['value']) && ($prev_price >= $this->data['value']); 
			}

			$this->setStateVar('prev_price', $price);
		}
		return $result;		
	}
}
?>