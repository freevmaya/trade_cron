<?
class limitTrigger extends baseTrigger {
	private function inContain($price, $prev_price) {
		$result = false;
		if ($prev_price && $price) {
			if ($price > $prev_price)
				$result = ($prev_price <= $this->data['value']) && ($price >= $this->data['value']); 
			else $result = ($price <= $this->data['value']) && ($prev_price >= $this->data['value']); 
		}
		return $result;
	}

	public function check($cur_in_id, $cur_out_id) {
		$result = false;
		$item = $this->dm->getCurrentOrder($cur_in_id, $cur_out_id);


		$price = @$item[$this->priceType];
		$prev_price = $this->getStateVar('prev_price');
		$direct = $price - $prev_price;
		if ($direct != 0) {
			if ($gate_direct = $this->getStateVar('gate_direct')) { // Если уже заходили на этот уровень
				if ($this->inContain($price, $prev_price)) {
					if (($gate_direct > 0) && ($direct < 0)) $result = true;
					else if (($gate_direct < 0) && ($direct > 0)) $result = true;
				}
			} else {
				$result = $this->inContain($price, $prev_price);

				$this->setStateVar('prev_price', $price);
				if ($result) $this->setStateVar('gate_direct', $direct);
			}
		}
		return $result;		
	}
}
?>