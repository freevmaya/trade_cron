<?
include_once(MAINDIR.'include/senders/sender.php');
include_once(MAINDIR.'include/senders/api_sender.php');

class sendWatchOrder {
    private $order;
    private $market_data;
    private $time;
    private $user;
    private $dm;
    private $dbparams;
    public function __construct($dbparams, $order, $user, $time, $market_data){
        $this->order        = $order;
        $this->user         = $user;
        $this->time         = $time;
        $this->market_data  = $market_data;
        $this->dbparams     = $dbparams;
    }

    protected function isTest() {
        return $this->order['state'] == 'test';
    }
	

	public function work() {

        $action_obj = $this->order['action'];
        $action = $action_obj['type'].($this->isTest()?'_test':'');

        $this->dm = $this->createDataModule();

        $sender = $this->createSender();
        $sender->setUser($this->user);

        if (!$this->isTest()) {
            $this->dm->setOrderState($this->order['id'], 'performed', $this->time);
            $this->sendUserEvent('UPDATEORDER', ['id'=>$this->order['id'], 'pair'=>$this->order['pair']]);
            console::log('PERFORMED');
        }

        $sender->setLog($this->dm);
        $send_result = $sender->$action($this->order['pair'], $action_obj, $this->time, $this->market_data);
        $this->afterSend($send_result);
        $this->dm->close();
	}

    protected function createDataModule() {
        return new dataModule(new mySQLProvider($this->dbparams['host'], $this->dbparams['dbname'], 
                            $this->dbparams['user'], $this->dbparams['passwd']));
    }

    protected function createSender() {
        if ($this->isTest())
            return new Sender();
        else if ($this->user['account_type'] == 'demo') {
            include_once(MAINDIR.'include/senders/demo_sender.php');
            return new demo_sender($this->dm);
        } else {
            $className = $this->order['market_name'].'_sender';
            include_once(MAINDIR.'include/senders/'.$className.'.php');
            return new $className();
        }
    }

    protected function sendUserEvent($event, $params=null, $eventType='info') {
        $def_params = ['time'=>$this->time, 
                        'id'=>$this->order['id'], 
                        'pair'=>$this->order['pair'], 
                        'state'=>$this->order['state']];
        $params = $params?array_merge($def_params, $params):$def_params;
        $this->dm->addUserEvent($this->order['uid'], $event, $params, $eventType);
    }

    public function afterSend($send_result)  {
    	$data = merge(array_merge([], $send_result), $this->order, ['price', 'state', 'action', 'pair', 'error']);
    	$data['time'] = $this->time;

        if ($this->isTest()) {
            $this->dm->sendUserEvent($this->user['uid'], 'ORDERSUCCESS', $data);
        } else {
            if (!isset($send_result['error'])) {
                $this->onComplete('PRICE: '.$data['price'], $send_result);
                $this->sendUserEvent('ORDERSUCCESS', $data, 'info');
            } else {
                $this->onFail('', $send_result);
                $this->sendUserEvent('FAILORDER', $send_result, 'fail');
            }
        }
    }    

    protected function onFail($info, $send_result) {
        $this->order['state'] = 'fail';
        $this->dm->setOrderState($this->order['id'], $this->order['state'], $this->time, $send_result);
        console::log('ORDER '.$this->order['id'].' '.$this->order['pair'].' FAIL '.$info, 'error');
    }

    protected function transactionComplete($send_result) {
        $pairA = explode('_', $this->order['pair']);
        $action = $this->order['action']['type'];
        $price = isset($send_result['price'])?$send_result['price']:$data['price'];
        $vol = $send_result['quantity'];

        if ($action=='sell') {
            $in_cur_str = $pairA[0];
            $out_cur_str = $pairA[1];
            $this->dm->transaction($this->user, $this->dm->curID($in_cur_str), -$vol);
            $this->dm->transaction($this->user, $this->dm->curID($out_cur_str), $vol * $price);
        } else {
            $in_cur_str = $pairA[1];
            $out_cur_str = $pairA[0];
            $this->dm->transaction($this->user, $this->dm->curID($in_cur_str), -$vol * $price);
            $this->dm->transaction($this->user, $this->dm->curID($out_cur_str), $vol);
        }

        $this->dm->sendUserEvent($this->user['uid'], 'BALANCE', [$this->order['pair']=>
                [$this->dm->getBalance($this->user['uid'], $this->user['account_id'], $this->dm->curID($pairA[0])), 
                $this->dm->getBalance($this->user['uid'], $this->user['account_id'], $this->dm->curID($pairA[1]))]
        ], $type='info');
    }

    protected function onComplete($info, $send_result) {
        $this->transactionComplete($send_result);

        $isprocess = ($this->order['state'] != 'process') && ($this->order['take_profit'] > 0) && ($this->order['stop_loss'] > 0);
        $this->order['state'] = $isprocess?'process':'success';
        $this->dm->setOrderState($this->order['id'], $this->order['state'], $this->time, $send_result);
        console::log('ORDER '.$this->order['id'].' '.$this->order['pair'].' SUCCESS '.$info);
    } 
}
?>