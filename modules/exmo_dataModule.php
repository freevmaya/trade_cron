<?
class exmoDataModule extends dataModule {
	protected $pairs;
    protected $exmo_api;
    protected $mid;
    protected $orders;

	function __construct($dbProvider, $exmo_api, $cacheObject=null) {
		parent::__construct($dbProvider, null, $cacheObject);
        $this->exmo_api = $exmo_api;
        $this->mid      = $this->market_id;
        $this->resetActualPairs();
	}

    //Overrideable
    protected function getMarketSymbol($market_symbol='') {
        return 'exmo';
    }

    protected function correctTime($a_time) {
        return ceil($a_time / CYCLETIME) * CYCLETIME;
    }

    public function resetActualPairs() {
        $pairs = $this->getActualPairs($this->mid, ['active', 'process']);
        $this->pairs = implode(',', $pairs);
    }

    public function getActualOrders() {
        return $this->getOrders($this->mid, ['active', 'process'], 'active');
    }

    public function getCurrentTrade($cur_in_id, $cur_out_id) {
        $cache_index = $cur_in_id.'_'.$cur_out_id.'_td_'.$this->time;

        //$this->trace($cache_index );

        if (!($result = $this->recCache->get($cache_index))) {

            $pairs_a = explode(',', $this->pairs); 

            foreach ($pairs_a as $pair) {
                if ($data = $this->exmo_api->api_query('trades', array(
                        "pair"=>$pair
                    ))) {
                    if ($result = parseExmoTrades($data, $pair)) {

                        $item = ['time'=>$this->time, 'buy_price'=>$result['buy_price'], 'sell_price'=>$result['sell_price'],
                                'buy_volumes'=>$result['buy_volumes'], 'sell_volumes'=>$result['sell_volumes'],
                                'avg_price'=>($result['buy_price'] + $result['sell_price']) / 2];

                        $pairA   = explode('_', $pair);
                        $item['cur_in'] = $cin  = $this->curID($pairA[0]);
                        $item['cur_out'] = $cout = $this->curID($pairA[1]);

                        $this->events->pairdata('exmotrades', $pair, $item);

                        $lci = $item['cur_in'].'_'.$item['cur_out'].'_td_'.$this->time;
                        $this->recCache->set($lci, $item);
                    }
                }
            }
            $result = $this->recCache->get($cache_index);
            //$this->trace($result);
        }

        return $result;
    }

	public function getCurrentOrder($cur_in_id, $cur_out_id) {
        $cache_index = $cur_in_id.'_'.$cur_out_id.'_od_'.$this->time;

        //$this->trace("cache_index: $cache_index");

        if (!($result = $this->recCache->get($cache_index))) {

            //$this->trace($result);

            $list = $this->exmo_api->api_query('order_book', array(
                "pair"=>$this->pairs,
                "limit"=>100
            ));

            if ($list) {
                foreach ($list as $pair=>$data) {
                    $pairA   = explode('_', $pair);
                    $data['cur_in'] = $cin  = $this->curID($pairA[0]);
                    $data['cur_out'] = $cout = $this->curID($pairA[1]);

                    $ci = $cin.'_'.$cout.'_od_'.$this->time;

                    $data['avg_price'] = ($data['ask_top'] + $data['bid_top']) / 2;
                    $volumes = new Volumes($data['ask'], $data['bid']);
                    unset($data['ask']);
                    unset($data['bid']);
                    $data['ask_glass'] = $volumes->getAskvol();
                    $data['bid_glass'] = $volumes->getBidvol();

                    $this->recCache->set($ci, $data);
                    $this->events->pairdata('exmoorders', $pair, $data);
                }

                $result = $this->recCache->get($cache_index);
            } else {
                trace('empty order list');
            }

        }

        return $result;
    }  
}
?>