<?
class windowTrigger extends baseTrigger {
    protected function getPriceFields() {
        return array('buy'=>'buy_price', 'sell'=>'sell_price', 'message'=>'avg_price');
    }
    
	public function check($cur_in_id, $cur_out_id) {
		$result = false;
		if ($item = $this->dm->getCurrentTrade($cur_in_id, $cur_out_id)) {
			$ddata = $this->data['cur_range'];
			$price 	= $item[$this->priceType];
	       	if (is_array($ddata)) {
				$result = ($price >= $ddata['min']) && ($price <= $ddata['max']);
			}
			
		}
		return $result;		
	}
}
?>