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

    protected function getPriceFields() {
        return array('buy'=>'buy_price', 'sell'=>'sell_price', 'message'=>'avg_price');
    }

	public function check($cur_in_id, $cur_out_id) {
		$result = false;

		if (!$this->getStateVar('complete')) {
			$item = $this->dm->getCurrentTrade($cur_in_id, $cur_out_id);

			$price = $item[$this->priceType];
			$prev_price = $this->getStateVar('prev_price');
			$direct = $price - $prev_price;

			if ($direct != 0) {
				//$this->trace("$prev_price, $this->priceType $price, direct: $direct");
				if ($gate_direct = $this->getStateVar('gate_direct')) { // Если уже заходили на этот уровень
					$time_delta = $this->time - $this->getStateVar('in_time');
					if ($time_delta > 60 * 2) {// Если пересекали более 2 мин. назад

						if ($time_delta < 60 * 60) {// Если менее 1 часа назад

							if ($this->inContain($price, $prev_price)) {
								if (($gate_direct > 0) && ($direct < 0)) $result = true;
								else if (($gate_direct < 0) && ($direct > 0)) $result = true;
							}

							if ($result) $this->setStateVar('complete', 1);
						} else $this->setStateVar('prev_price', 0); 

						// Если пересекали линюю более часа назад, то сбрасываем в исходное состояние
					}
				} else {
					if ($this->inContain($price, $prev_price)) {
						$this->setStateVar('gate_direct', $direct);
						$this->setStateVar('in_time', $this->time);
					}
				}
				$this->setStateVar('prev_price', $price);
			}
		}
		return $result;		
	}
}
?>