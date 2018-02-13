<?
class dataModule extends timeObject {
	protected $recCache;
	protected $events;

	function __construct($fullData=null) {
		parent::__construct();
		$this->recCache = array();
		$this->events = $this->createEvents();
		if ($fullData) $this->fullCache($fullData);
	}

	protected function getCurrentOrderDB($cur_in_id, $cur_out_id) {
		$where = "`cur_in`={$cur_in_id} AND `cur_out`={$cur_out_id}";
		if ($this->serverTime() != $this->stime) $where .= " AND `time`<='{$this->stime}'";

		$query = "SELECT * FROM _orders WHERE {$where} ORDER BY id DESC LIMIT 0, 1";
		return DB::line($query);
	}

	protected function getCurrentTradeDB($cur_in_id, $cur_out_id) {
		$where = "`cur_in`={$cur_in_id} AND `cur_out`={$cur_out_id}";
		if ($this->serverTime() != $this->stime) $where .= " AND `time`<='{$this->stime}'";

		$query = "SELECT * FROM _trades WHERE {$where} ORDER BY id DESC LIMIT 0, 1";
		return DB::line($query);
	}

	protected function fullCache($fullData) {
        $pair       = explode('_', $fullData['pair']);
        $cur_in     = curID($pair[0]);
        $cur_out    = curID($pair[1]);
        $start_time = $fullData['start_time'];
        $end_time 	= $fullData['end_time'];

		$where = "`cur_in`={$cur_in} AND `cur_out`={$cur_out} AND `time`>='{$start_time}' AND  `time`<='{$end_time}'";
		$query = "SELECT * FROM _orders WHERE {$where}";
		$list = DB::asArray($query);
		foreach ($list as $order) {
			$cache_index = $cur_in.'_'.$cur_out.'_od_'.strtotime($order['time']);
			$this->recCache[$cache_index] = $order;
		}
	}

	protected function createEvents() {
		return new Events();
	}

	public function candle_data($cur_in_id, $cur_out_id, $time_inverval, $field='ask_top') {
		$query = "SELECT id, {$field}, UNIX_TIMESTAMP(`time`) AS `unix_time`, `time` FROM _orders WHERE `cur_in`={$cur_in_id} AND `cur_out`={$cur_out_id} AND {$time_inverval}";
		$list = DB::asArray($query);
		$result = null;

		if (($count = count($list)) > 1) {
			$result = array();
			$result['open_time'] = date('d.m.Y H:i:s', $list[0]['unix_time']);
			$result['close_time'] = date('d.m.Y H:i:s', $list[$count - 1]['unix_time']);
			$result['min'] = $result['max'] = $result['open'] = $list[0][$field];
			$result['close'] = $list[$count - 1][$field];
			foreach ($list as $item) {
				if ($item[$field] > $result['max'])
					$result['max'] = $item[$field];
				else if ($result['min'] > $item[$field]) $result['min'] = $item[$field];
			}
		}
		return $result;
	}

	public function getCurrentOrder($cur_in_id, $cur_out_id) {
		$cache_index = $cur_in_id.'_'.$cur_out_id.'_od_'.$this->time;

		if (!isset($this->recCache[$cache_index])) {
			$this->recCache[$cache_index] = $result = $this->getCurrentOrderDB($cur_in_id, $cur_out_id);
		} else $result = $this->recCache[$cache_index];

		return $result;
	}

	public function getCurrentTrade($cur_in_id, $cur_out_id) {
		$cache_index = $cur_in_id.'_'.$cur_out_id.'_td_'.$this->time;

		if (!isset($this->recCache[$cache_index])) {
			$this->recCache[$cache_index] = $result = $this->getCurrentTradeDB($cur_in_id, $cur_out_id);
		} else $result = $this->recCache[$cache_index];

		return $result;
	}

	public function getWatchOrders($uid, $market_id, $states=null, $pairs=null) {
		$stateFilter = '';
		if ($states) $stateFilter .= " AND `state` IN ('".implode("','", $states)."')";
		if ($pairs) $stateFilter .= " AND `pair` IN ('".implode("','", $pairs)."')";
		$query = "SELECT * FROM _watch_orders WHERE uid={$uid} AND market_id={$market_id} {$stateFilter}";
		$list = DB::asArray($query);

		foreach ($list as $key=>$item) {
			$list[$key]['action'] = array(
				"type"=>$item['action'],
            	"volume"=>$item['volume'],
            	"state"=>$item['state']
			);

			$list[$key]['triggers'] = json_decode($list[$key]['triggers'], true);
		}

		return $list;
	}

	public function resetWatchOrder($order) {
		if (($id = $order['id']) && ($rec = DB::line("SELECT * FROM _watch_orders WHERE id={$id}"))) {
			if ($rec['state'] != $order['state']) {
				$query = "UPDATE _watch_orders SET `state`='{$order['state']}', `state_time`=NOW() WHERE id={$id}";
				DB::query($query);
			}
		}
		return $order;
	}

	public function marketID($market_name) {
		if ($rec = DB::line("SELECT id FROM _markets WHERE `name`='{$market_name}'")) 
			return $rec['id'];
		else return 0;
	}

	public function getActualPairs($market_id, $states) {
		$states_str = "'".implode("','", $states)."'";
		$query = "SELECT pair FROM _watch_orders WHERE `state` IN ($states_str) AND market_id={$market_id} GROUP BY `pair`";
		$recs = DB::asArray($query);
		$result = [];
		foreach ($recs as $rec) $result[] = $rec['pair'];
		return $result;
	}

	public function getOrders($market_id, $states, $action_state_set='inactive') {
		$states_str = "'".implode("','", $states)."'";
		$query = "SELECT wo.* FROM _watch_orders wo INNER JOIN _users u ON wo.uid = u.uid WHERE wo.`state` IN ($states_str) AND FIND_IN_SET('active', u.states) > 0 AND market_id={$market_id}";
		$orders = DB::asArray($query);

		foreach ($orders as $i=>$order) {
			$orders[$i]['action'] = array(
				'type'=>$order['action'],
				'volume'=>$order['volume'],
				'state'=>$action_state_set
			);

			$orders[$i]['triggers'] = json_decode($order['triggers'], true);
		}
		return $orders;
	}

	public function setOrderState($id, $state, $unixtime=null) {
		if ($unixtime) $str_time = date(DATEFORMAT, $unixtime);
		else $str_time = date(DATEFORMAT);
		$query = "UPDATE _watch_orders SET state='{$state}', state_time='{$str_time}' WHERE id={$id}";
		return DB::query($query);
	}

	public function saveTriggerState($id_order, $data) {
		if (!is_string($data)) $data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$query = "UPDATE _watch_orders SET triggers_state='{$data}' WHERE id={$id_order}";
		return DB::query($query);
	}

	public function addUserEvent($uid, $event, $data, $type='info') {
    	$this->events->send($uid, $event, $data, $type);

    	$data_str = $data?(!is_string($data)?json_encode($data):$data):'';
		DB::query("INSERT INTO _user_events (`uid`, `type`, `event`, `data`) VALUES ({$uid}, '{$type}', '{$event}', '{$data_str}')");
	}

	public function getUser($uid, $market_id) {
		$query = "SELECT u.*, k.keyApi, k.secretApi FROM _users u LEFT JOIN _apikey k ON u.uid = k.uid AND k.market_id={$market_id} WHERE u.uid={$uid}";
		if ($user = DB::line($query)) {
			$user['states'] = explode(',', $user['states']);
		}
		return $user;
	}
}
?>