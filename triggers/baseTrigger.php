<?
include_once(MAINDIR.'modules/timeObject.php');

class baseTrigger extends timeObject {
	protected $data;
	protected $state;
	protected $state_modify;
    protected $priceType;
	protected $dm;

	function __construct($a_dataModule, $a_data, $time=0, $state=null, $checkPriceType='ask_top') {
		parent::__construct($time);
		$this->dm = $a_dataModule;
		$this->state = $state;
        $this->state_modify = false;
        $this->priceType = $checkPriceType;
		$this->setData($a_data);
    }

    public function setData($a_data) {
        $this->data = $a_data;
    }

    public function getData() {
    	return $this->data;
    }

    public function getState() {
    	return $this->state;
    }

    public function getStateVar($varName) {
    	return isset($this->state[$varName])?$this->state[$varName]:null;
    }

    public function setStateVar($varName, $value) {
    	if ($this->state == null) $this->state = array($varName=>0);
    	$this->state[$varName] = $value;	
		$this->state_modify = true;
    }

    public function isModified() {
    	return $this->state_modify;
    }

	protected function avg($item) {
		return ($item['bid_top'] + $item['ask_top']) / 2;
	}

	public function check($cur_in_id, $cur_out_id) {
		return false;
	}

    public function timePeriod() {
        return 0;
    }
}
?>