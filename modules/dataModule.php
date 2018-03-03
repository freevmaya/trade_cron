<?
class dataModule extends timeObject {
	protected $recCache;
	protected $events;
	protected $market_id;
	protected $dbp;
	protected $cur_ids;

	function __construct($dbProvider, $fullData=null, $cacheObject=null, $market_symbol='') {
		parent::__construct();
		$this->dbp 				= $dbProvider;
		$this->recCache 		= $cacheObject;
		$this->market_symbol 	= $this->getMarketSymbol($market_symbol);
		$this->market_id 		= $this->getMarketId();
		$this->events 			= $this->createEvents();
		$this->cur_ids 			= [];
		if ($fullData) $this->fullCache($fullData);
	}

	public function dbProvider() {
		return $this->dbp;
	}

	//Overrideable
	protected function getMarketSymbol($market_symbol='') {
		return $market_symbol;
	}

    protected function getMarketId() {
        return $this->getMarketNameId($this->market_symbol);
    }

    public function curID($cur_sign) {
        if (!isset($this->cur_ids[$cur_sign])) {
            $cur_rec = $this->dbp->asArray("SELECT * FROM ".DBPREF."_currency");

            foreach ($cur_rec as $item)
                $this->cur_ids[$item['sign']] = $item['cur_id'];
        }

        if (isset($this->cur_ids[$cur_sign])) return $this->cur_ids[$cur_sign];
        else {
            $this->dbp->query("INSERT INTO ".DBPREF."_currency (`sign`, `name`) VALUES ('{$cur_sign}', '{$cur_sign}')");
            return $this->cur_ids[$cur_sign] = $this->dbp->lastID();
        }
    }

    public function getMarketNameId($marketSymbol) {
        if ($res = $this->dbp->line("SELECT * FROM _markets WHERE name='{$marketSymbol}'")) {
            return $res['id'];
        } else {
            $this->dbp->query("INSERT INTO _markets (`name`) VALUES ('$marketSymbol')");
            return $this->dbp->lastID();
        }
    } 

	public function marketID($market_name) {
		if ($rec = $this->dbp->line("SELECT id FROM _markets WHERE `name`='{$market_name}'")) 
			return $rec['id'];
		else return 0;
	}

	protected function getCurrentOrderDB($cur_in_id, $cur_out_id) {
		$where = "`cur_in`={$cur_in_id} AND `cur_out`={$cur_out_id}";
		if ($this->serverTime() != $this->stime) $where .= " AND `time`<='{$this->stime}'";

		$query = "SELECT *, (ask_top + bid_top) / 2 AS avg_price FROM _orders_".$this->market_symbol." WHERE {$where} ORDER BY id DESC LIMIT 0, 1";
		return $this->dbp->line($query);
	}

	protected function getCurrentTradeDB($cur_in_id, $cur_out_id) {
		$where = "`cur_in`={$cur_in_id} AND `cur_out`={$cur_out_id}";
		if ($this->serverTime() != $this->stime) $where .= " AND `time`<='{$this->stime}'";

		$query = "SELECT *, (buy_price + sell_price) / 2 AS avg_price FROM _trades_".$this->market_symbol." WHERE {$where} ORDER BY id DESC LIMIT 0, 1";
		return $this->dbp->line($query);
	}

	protected function fullCache($fullData) {
        $pair       = explode('_', $fullData['pair']);
        $cur_in     = $this->curID($pair[0]);
        $cur_out    = $this->curID($pair[1]);
        $start_time = $fullData['start_time'];
        $end_time 	= $fullData['end_time'];

		$where = "`cur_in`={$cur_in} AND `cur_out`={$cur_out} AND `time`>='{$start_time}' AND  `time`<='{$end_time}'";
		$query = "SELECT *, UNIX_TIMESTAMP(`time`) AS `unix_time`, (ask_top + bid_top) / 2 AS avg_price FROM _orders_".$this->market_symbol." WHERE {$where}";
		$listOrder = $this->dbp->asArray($query);

		$query = "SELECT *, UNIX_TIMESTAMP(`time`) AS `unix_time`, (buy_price + sell_price) / 2 AS avg_price FROM _trades_".$this->market_symbol." WHERE {$where}";
		$listTrade = $this->dbp->asArray($query);

		foreach ($listOrder as $order) {
			$cache_index = $cur_in.'_'.$cur_out.'_od_'.strtotime($order['time']);
			$this->recCache->set($cache_index, $order);
		}

		foreach ($listTrade as $trade) {
			$cache_index = $cur_in.'_'.$cur_out.'_td_'.strtotime($trade['time']);
			$this->recCache->set($cache_index, $trade);
		}
	}

	protected function createEvents() {
		return new Events();
	}

	public function candle_data($cur_in_id, $cur_out_id, $start_time, $end_time, $field='ask_top') {
		$cache_index = $cur_in_id.'_'.$cur_out_id.'_s'.$start_time.'_e'.$end_time;

		if (!($result = $this->recCache->get($cache_index))) {
			$sstart_time = date(DATEFORMAT, $start_time);
			$send_time = date(DATEFORMAT, $end_time);
			$where = "(`cur_in`={$cur_in_id} AND `cur_out`={$cur_out_id}) AND (`time`>='{$sstart_time}' AND `time`<='{$send_time}')";
			$query = "SELECT id, {$field}, UNIX_TIMESTAMP(`time`) AS `unix_time`, `time` FROM _orders_".$this->market_symbol." WHERE {$where}";
			$list = $this->dbp->asArray($query);
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

				$this->recCache->set($cache_index, $result);
			}
		}
		return $result;
	}

	protected function candleInCache($cur_in_id, $cur_out_id, $start_time, $end_time) {
		$start_cache_index = $cur_in_id.'_'.$cur_out_id.'_od_'.$start_time;
		$end_cache_index = $cur_in_id.'_'.$cur_out_id.'_od_'.$end_time;
		$list = null;

		if ($this->recCache->get($start_cache_index) && $this->recCache->get($end_cache_index)) {
			$list = [];
			for ($i = $start_time; $i<=$end_time; $i += WAITTIME) 
				$list[] = $this->recCache->get($cur_in_id.'_'.$cur_out_id.'_od_'.$i);
		}

		return $list;
	}

	public function getCurrentOrder($cur_in_id, $cur_out_id) {
		$cache_index = $cur_in_id.'_'.$cur_out_id.'_od_'.$this->time;

		if ($result = $this->recCache->get($cache_index)) {
			$this->recCache->set($cache_index, $result = $this->getCurrentOrderDB($cur_in_id, $cur_out_id));
		}

		return $result;
	}

	public function getCurrentTrade($cur_in_id, $cur_out_id) {
		$cache_index = $cur_in_id.'_'.$cur_out_id.'_td_'.$this->time;

		if ($result = $this->recCache->get($cache_index)) {
			$this->recCache->set($cache_index, $result = $this->getCurrentTradeDB($cur_in_id, $cur_out_id));
		}

		return $result;
	}

	public function resetWOTriggerStates($uid, $market_id, $states=null, $pairs=null) {
		$stateFilter = '';
		if ($states) $stateFilter .= " AND `state` IN ('".implode("','", $states)."')";
		if ($pairs) $stateFilter .= " AND `pair` IN ('".implode("','", $pairs)."')";
		$query = "UPDATE _watch_orders  SET `triggers_state`='' WHERE uid={$uid} AND market_id={$market_id} {$stateFilter}";
		return $this->dbp->query($query);
	}

	public function getWatchOrders($uid, $market_id, $states=null, $pairs=null) {
		$stateFilter = '';
		if ($states) $stateFilter .= " AND `state` IN ('".implode("','", $states)."')";
		if ($pairs) $stateFilter .= " AND `pair` IN ('".implode("','", $pairs)."')";
		$query = "SELECT * FROM _watch_orders WHERE uid={$uid} AND market_id={$market_id} {$stateFilter}";
		$list = $this->dbp->asArray($query);

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

	public function getWatchOrderIds($uid, $market_id, $states=null, $pairs=null) {
		$stateFilter = '';
		if ($states) $stateFilter .= " AND `state` IN ('".implode("','", $states)."')";
		if ($pairs) $stateFilter .= " AND `pair` IN ('".implode("','", $pairs)."')";
		$query = "SELECT id FROM _watch_orders WHERE uid={$uid} AND market_id={$market_id} {$stateFilter}";
		$list = $this->dbp->asArray($query);
		return $list;
	}

	public function resetTriggerStates($order, $value='') {
		$query = "UPDATE _watch_orders SET `triggers_state`='{$value}' WHERE id={$order['id']}";
		return $this->dbp->query($query);
	}

	public function resetWatchOrder($order) {
		if (($id = $order['id']) && ($rec = $this->dbp->line("SELECT * FROM _watch_orders WHERE id={$id}"))) {
			if ($rec['state'] != $order['state']) {
				$query = "UPDATE _watch_orders SET `state`='{$order['state']}', `state_time`=NOW() WHERE id={$id}";
				$this->dbp->query($query);
			}
		}
		return $order;
	}

	public function getActualPairs($market_id, $states) {
		$states_str = "'".implode("','", $states)."'";
		$query = "SELECT pair FROM _watch_orders WHERE `state` IN ($states_str) AND market_id={$market_id} GROUP BY `pair`";
		$recs = $this->dbp->asArray($query);
		$result = [];
		foreach ($recs as $rec) $result[] = $rec['pair'];
		return $result;
	}

	public static function actionObject($actionName, $volume, $actionState) {
		return array(
				'type'=>$actionName,
				'volume'=>$volume,
				'state'=>$actionState
			);
	}

	public function getOrder($id, $action_state_set='active') {
		$query = "SELECT * FROM _watch_orders WHERE id=$id";
		if ($order = $this->dbp->line($query)) {
			$order['action'] = dataModule::actionObject($order['action'], $order['volume'], $action_state_set);
			$order['triggers'] = json_decode($order['triggers'], true);
		}
		return $order;
	}

	public function getOrders($market_id, $states, $action_state_set='inactive') {
		$states_str = "'".implode("','", $states)."'";
		$query = "SELECT wo.*, m.name as market_name FROM _watch_orders wo INNER JOIN _users u ON wo.uid = u.uid INNER JOIN _markets m ON m.id = wo.market_id ".
				"WHERE wo.`state` IN ($states_str) AND FIND_IN_SET('active', u.states) > 0 AND market_id={$market_id}";
		$orders = $this->dbp->asArray($query);

		foreach ($orders as $i=>$order) {
			$orders[$i]['action'] = dataModule::actionObject($order['action'], $order['volume'], $action_state_set);
			$orders[$i]['triggers'] = json_decode($order['triggers'], true);
		}
		return $orders;
	}

	public function setOrderState($id, $state, $unixtime=null, $state_data='') {
		if ($unixtime) $str_time = date(DATEFORMAT, $unixtime);
		else $str_time = date(DATEFORMAT);

		$state_data = $this->dbp->safeVal($state_data);
		$query = "UPDATE _watch_orders SET state='{$state}', `state_data`='{$state_data}', state_time='{$str_time}' WHERE id={$id}";
		$result = $this->dbp->query($query);	

		return $result;
	}

	public function saveTriggerState($id_order, $data) {
		if (!is_string($data)) $data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$query = "UPDATE _watch_orders SET triggers_state='{$data}' WHERE id={$id_order}";
		return $this->dbp->query($query);
	}

	public function addUserEvent($uid, $event, $data, $type='info') {
    	$this->sendUserEvent($uid, $event, $data, $type);
    	$data_str = $data?(!is_string($data)?json_encode($data):$data):'';
		$this->dbp->query("INSERT INTO _user_events (`uid`, `type`, `event`, `data`) VALUES ({$uid}, '{$type}', '{$event}', '{$data_str}')");
	}

	public function sendUserEvent($uid, $event, $data, $type='info') {
    	$this->events->send($uid, $event, $data, $type);
    }

	public function getUser($uid, $market_id) {
		$query = "SELECT u.*, k.keyApi, k.secretApi, a.type as account_type FROM _users u ".
				"LEFT JOIN _apikey k ON u.uid = k.uid AND k.market_id={$market_id} ".
				"INNER JOIN _accout a ON a.id=u.account_id ".
				"WHERE u.uid={$uid}";
		if ($user = $this->dbp->line($query)) {
			$user['states'] = explode(',', $user['states']);
		}
		return $user;
	}

	public function getBalance($uid, $account_id, $cur_id) {
		if ($value = $this->dbp->one("SELECT value FROM _balance WHERE uid={$uid} AND account_id={$account_id} ".
				"AND cur_id={$cur_id}")) {
			return $value;
		} else {
			$this->dbp->query("REPLACE _balance (`uid`, `account_id`, `cur_id`) VALUES ({$uid}, {$account_id}, {$cur_id})");
			return 0;
		}		

	}

	public function getBalances($uid, $account_id) {
		$query = "SELECT b.value, c.sign FROM _balance b INNER JOIN _currency c ON b.cur_id=c.cur_id ".
				"WHERE `uid`={$uid} AND `account_id`={$account_id}";
		$items = $this->dbp->asArray($query);
		$result = [];
		foreach ($items as $item) {
			$result[$item['sign']] = $item['value'];
		}

		return $result;
	}

    public function logRecord($logData) {
        //$this->addUserEvent($this->data['uid'], 'CONSOLE', $logData);
        console::log($logData);
    }

	public function transaction($user, $cur_id, $value) {
		$query = "INSERT INTO _transaction (`account_id`, `cur_id`, `value`) VALUES ({$user['account_id']}, {$cur_id}, {$value})";
		if ($this->dbp->query($query)) {
			$query = "SELECT SUM(`value`) FROM _transaction WHERE ".
						"`account_id`={$user['account_id']} AND `cur_id`={$cur_id}";
			$balance = $this->dbp->one($query);
			return $this->dbp->query("REPLACE _balance (`uid`, `account_id`, `cur_id`, `value`) ".
						"VALUES ({$user['uid']}, {$user['account_id']}, {$cur_id}, {$balance})");
		} else return false;
	}

	public function close() {
		$this->dbp->close();
	}
}
?>