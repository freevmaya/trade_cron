<?
define('TRIGGERSCLASSPATH', MAINDIR.'triggers/');
include_once(TRIGGERSCLASSPATH.'baseTrigger.php');

class cur_watch extends baseTrigger {
	protected $sender;
    protected $maxPeriod;

	public function watch($sender, $options=null) {
        $result = 0;
		$this->sender = $sender;
        $this->maxPeriod = 0;
        $options = $options?array_merge($this->defOptions(), $options):$this->defOptions();

        $result = 0;
        $tcount = 0;
        $state = $this->data['state'];
        if ($state == 'process') 
            $result = $this->process();
        else {
            $pairA   = explode('_', $this->data['pair']);   
            $cur_in_id  = curID($pairA[0]);
            $cur_out_id = curID($pairA[1]);

            $ts = json_decode($this->data['triggers_state'], true);
            $user = $this->dm->getUser($this->data['uid'], $this->data['market_id']);
            if ($user && in_array('active', $user['states'])) {

                $action = $this->data['action']['type'];
                $isModified = false;
                $resTgs = [];

                $lres = $this->watchTriggers($action, $cur_in_id, $cur_out_id, $this->data['triggers'], $ts, $isModified, $resTgs);

                if ($isModified) {
                    $this->data['triggers_state'] = json_encode($tstates = $this->getStates($resTgs));
                    $this->dm->saveTriggerState($this->data['id'], $tstates);
                    $this->sendUserEvent('TRIGGERSTATEMODIFIED', ['order_id'=>$this->data['id'], 'state'=>$tstates]);
                }

            	if ($lres) {
                    $corder = $this->dm->getCurrentOrder($cur_in_id, $cur_out_id);
                    $avgPrice = $this->avg($corder);
                    if ($state == 'active') {
                        $this->sender->setUser($user);
                        $send_result = $this->sender->$action($this->data['pair'], $this->data['action'], $this->time, $corder);
                    } else if ($state == 'test') {
                        $taction = $action.'_test';
                        $send_result = $this->sender->$taction($this->data['pair'], $this->data['action'], $this->time, $corder);
                    }
                    if ($send_result) {
                        $this->onComplete('PRICE: '.$send_result['price']);
                        $this->sendUserEvent('ORDERSUCCESS', array_merge(['pair'=>$this->data['pair'],
                                'cur_avgprice'=>$avgPrice, 'action'=>$action], $send_result));
                    } else {
                        $this->onFail('');
                        $this->sendUserEvent('FAILORDER', ['pair'=>$this->data['pair'], 'cur_avgprice'=>$avgPrice, 'action'=>$action]);
                    }
                    $result = 1;             
                }
            }
        }
        return $result;
	}

    public function period() {
        return $this->maxPeriod;
    }

    protected function defOptions() {
        return ['testComplete'=>true];
    }

    protected function sendUserEvent($event, $params=null) {
        $def_params = ['time'=>$this->time, 
                        'id'=>$this->data['id'], 
                        'pair'=>$this->data['pair'], 
                        'state'=>$this->data['state']];
        $params = $params?array_merge($def_params, $params):$def_params;
        $this->dm->addUserEvent($this->data['uid'], $event, $params);
    }

    protected function getStates($resTgs) {
        $states = array();
        foreach ($resTgs as $type=>$tgs) {
            if (is_array($tgs)) {
                $states[$type] = [];
                for ($i=0;$i<count($tgs);$i++)
                    $states[$type][$i] = $tgs[$i]->getState();
            } else $states[$type] = $tgs->getState();
        }
        return $states;
    }

    protected function watchTriggers($action, $cur_in_id, $cur_out_id, $triggers, $ts, &$isModified, &$resTgs, $a_ttype=null) {
        $lres = true;
        $maxPeriod = 0;
        foreach ($triggers as $i=>$trigger) {
            $ttype = $a_ttype?$a_ttype:$i;

            if (isset($trigger[0])) {
                $resTgs[$ttype] = [];
                $lres = $this->watchTriggers($action, $cur_in_id, $cur_out_id, $trigger, isset($ts[$ttype])?$ts[$ttype]:null, $isModified, $resTgs[$ttype], $ttype);
                //print_r(count($resTgs[$ttype]).' '.count($trigger));
            } else {
                $trClass = $ttype.'Trigger';
                if (!class_exists($trClass)) {
                    if (file_exists($classFileName = TRIGGERSCLASSPATH.$trClass.'.php'))
                        include_once($classFileName);
                    else {
                        console::log("FILE NOT FOUND {$classFileName}");
                        $lres = false;
                        break;
                    }
                }

                //$this->trace($trClass);
                $tObj = $resTgs[$i] = new $trClass($this->dm, $trigger, $this->time, isset($ts[$i])?$ts[$i]:null, $action);
                if ($res = $tObj->check($cur_in_id, $cur_out_id))
                    $this->maxPeriod  = max($tObj->timePeriod(), $maxPeriod);

                $lres = $lres && $res;
                $isModified = $isModified || $tObj->isModified();
            }

            if (!$lres) break;
        }
        return $lres;
    }

    protected function process() {

        $pairA   = explode('_', $this->data['pair']);   
        $cur_in_id  = curID($pairA[0]);
        $cur_out_id = curID($pairA[1]);

        $market_order = $this->dm->getCurrentOrder($cur_in_id, $cur_out_id);
        $price = $market_order['bid_top'];
        $avgPrice = $this->avg($market_order);

        if (($this->data['take_profit'] <= $price) || ($this->data['stop_loss'] >= $price)) {

            $this->data['state'] = 'success';
            $this->dm->setOrderState($this->data['id'], $this->data['state']);
            console::log('EXTENDS, PRICE: '.$price);
            $this->sendUserEvent('ORDERSUCCESS', ['cur_avgprice'=>$avgPrice]);
        } 
    }

    protected function onFail($info) {
        $this->data['state'] = 'fail';
        $this->dm->setOrderState($this->data['id'], $this->data['state'], $this->time);
        console::log('ORDER '.$this->data['id'].' '.$this->data['pair'].' FAIL '.$info, 'error');
    }

	protected function onComplete($info) {
        if ($this->data['state'] != 'test') {
            $isprocess = ($this->data['state'] != 'process') && ($this->data['take_profit'] > 0) && ($this->data['stop_loss'] > 0);
            $this->data['state'] = $isprocess?'process':'success';
            $this->dm->setOrderState($this->data['id'], $this->data['state'], $this->time);
        }
		console::log('ORDER '.$this->data['id'].' '.$this->data['pair'].' SUCCESS '.$info);
	}
}

?>