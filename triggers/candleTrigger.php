<?
class candleTrigger extends baseTrigger {

    public function setData($a_data) {
        if (is_string($a_data)) {
        	$a = explode(',', $a_data);
        	$a_data = array(
        		'range'=>array('min'=>$a[1], 'max'=>$a[2]),
        		'time'=>'`time` >= %TIME% - INTERVAL '.$a[3].' AND `time` <= %TIME%'
        	);
        } else $a_data['qtime'] = '`time` >= %TIME% - INTERVAL '.$a_data['time'].' SECOND AND `time` <= %TIME%';
        parent::setData($a_data);
    }

	public function check($cur_in_id, $cur_out_id) {
		$field = isset($this->data['field'])?$this->data['field']:'ask_top';

		if (isset($this->data['back']))
			$time = "`time` >= '{$this->stime}' - INTERVAL {$this->data['back']} AND `time` <= '{$this->stime}'";
		else $time = str_replace('%TIME%', "'{$this->stime}'", $this->data['qtime']);

		if ($candle = $this->dm->candle_data($cur_in_id, $cur_out_id, $time, $field)) {

			$max = max($candle['close'], $candle['open']);
			$min = min($candle['close'], $candle['open']);

	       	$range = $candle['close'] - $candle['open'];
	       	$ddata = $this->data['range'];
	       	$result = false;
	       	if (is_array($ddata)) {
		       	$min_a = cnvValue($ddata['min'], $max);
		       	$max_a = cnvValue($ddata['max']?$ddata['max']:'100%', $max);

		       	$ddata['min'] = min($min_a, $max_a);
		       	$ddata['max'] = max($min_a, $max_a);

				$result = ($range >= $ddata['min']) && ($range <= $ddata['max']);
			} else $result = $range <= cnvValue($ddata, $max);

			if ($result && isset($this->data['value'])) {
				console::log($this->stime." DELTA: ".$range);
				$v = $this->data['value'];
				$result = ($v >= $min) && ($v <= $max);
			}

			if (isState($this->data, 'test')) {
				$result = array(
					'range'=>$range,
					'range_percent'=>round($range/$max * 100, 2).'%',
					'candle'=>$candle,
					'result'=>$result?1:0,
					'ddata'=>$ddata
				);
			}

			return $result;
		}
		return false;
	}

    public function timePeriod() {
        return $this->data['time'];
    }
}
?>