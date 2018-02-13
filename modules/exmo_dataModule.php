<?
class exmoDataModule extends dataModule {
	protected $pairs;
    protected $exmo_api;
    protected $mid;
    protected $orders;

	function __construct($exmo_api) {
		parent::__construct();
        $this->exmo_api = $exmo_api;
        $this->mid = $this->marketID('exmo');
        $this->resetActualPairs();
	}

    public function resetActualPairs() {
        $pairs = $this->getActualPairs($this->mid, ['active', 'process']);
        $this->pairs = implode(',', $pairs);
    }

    public function getActualOrders() {
        return $this->getOrders($this->mid, ['active', 'process'], 'active');
    }

	public function getCurrentOrder($cur_in_id, $cur_out_id) {
        $cache_index = $cur_in_id.'_'.$cur_out_id.'_'.$this->time;

        if (!isset($this->recCache[$cache_index])) {
            
            $list = $this->exmo_api->api_query('order_book', array(
                "pair"=>$this->pairs,
                "limit"=>1
            ));

            if ($list) {
                foreach ($list as $pair=>$data) {
                    $pairA   = explode('_', $pair);
                    $cur_in_id  = curID($pairA[0]);
                    $cur_out_id = curID($pairA[1]);

                    $ci = $cur_in_id.'_'.$cur_out_id.'_'.$this->time;
                    $query = "SELECT MAX(id) as id FROM _orders WHERE cur_in={$cur_in_id} AND cur_out={$cur_out_id}";
                    if ($id = DB::line($query)) // Получае id последней записи, это нужно для расчета объемов, если потребуется
                        $data['id'] = $id['id'];
                    $this->recCache[$ci] = $data;
                }
            } else return null;

            //print_r($this->recCache);
        }

        return isset($this->recCache[$cache_index])?$this->recCache[$cache_index]:null;
    }  
}
?>