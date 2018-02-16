<?
class floatLossTrigger extends baseTrigger {

	public function check($cur_in_id, $cur_out_id) {
		$result = false;

		if ($item = $this->dm->getCurrentOrder($cur_in_id, $cur_out_id)) {
			$price 			= @$item[$this->priceType];
			$low_price 		= $this->getStateVar('low_price');
			$cur_low_price 	= $price - $price * $this->data['value']/100;

			if ($low_price) {

				if ($low_price < $cur_low_price) {
					$this->setStateVar('low_price', $cur_low_price);
				}
				$result = $low_price >= $price;
			} else $this->setStateVar('low_price', $cur_low_price);
		}

		return $result;	
	}
}
?>