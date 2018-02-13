<?
include(dirname(__FILE__).'/cur_watch.php');

class watchTrade extends cur_watch {
    protected $pairs;
    function __construct($a_data, $pairs, $time=0) {
        parent::__construct($a_data, $time);
        $this->pairs = $pairs;
    }

    protected function getCurrentTrade($cur_in_id, $cur_out_id) {
        GLOBAL $recCache;
        $cache_index = $cur_in_id.'_'.$cur_out_id.'_'.$this->time;

        if (!isset($recCache[$cache_index])) {
            
            $list = $this->sender->api_query('order_book', array(
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
                    $recCache[$ci] = $data;
                }
            } else return null;
        }
        return $recCache[$cache_index];
    }        
}
?>