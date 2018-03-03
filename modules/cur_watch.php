<?
define('TRIGGERSCLASSPATH', MAINDIR.'triggers/');
include_once(TRIGGERSCLASSPATH.'baseTrigger.php');
include_once(MAINDIR.'include/workers/sendThread.php');
include_once(MAINDIR.'include/workers/sendWatchOrder.php');

class cur_watch extends baseTrigger {
    protected $maxPeriod;
    protected $user;
    protected $corder;

	public function watch($options=null) {
        $this->maxPeriod = 0;
        $options    = $options?array_merge($this->defOptions(), $options):$this->defOptions();
        $tcount     = 0;
        $state      = $this->data['state'];
        $this->user = $this->dm->getUser($this->data['uid'], $this->data['market_id']);

        if ($state == 'process') $this->process();
        else {
            $pairA   = explode('_', $this->data['pair']);   
            $cur_in_id  = $this->dm->curID($pairA[0]);
            $cur_out_id = $this->dm->curID($pairA[1]);

            $ts = json_decode($this->data['triggers_state'], true);
            if ($this->user && in_array('active', $this->user['states'])) {

                $action = $this->data['action']['type'];
                $isModified = false;
                $resTgs = [];

                if ($this->data['triggers'])
                    $lres = $this->watchTriggers($action, $cur_in_id, $cur_out_id, $this->data['triggers'], $ts, $isModified, $resTgs);
                else $lres = true;

                if ($isModified) {
                    $this->data['triggers_state'] = json_encode($tstates = $this->getStates($resTgs));
                    $this->dm->saveTriggerState($this->data['id'], $tstates);
                    $this->sendUserEvent('TRIGGERSTATEMODIFIED', ['order_id'=>$this->data['id'], 'state'=>$tstates]);
                }

            	if ($lres) {
                    $this->corder = $this->dm->getCurrentOrder($cur_in_id, $cur_out_id);
                    $sendWorker = $this->createSendThread();
                    $sendWorker->start();
                    //$sendWorker->join();

                    /*
                    $this->afterSend($send_result, $this->data['action']);
                    $result = 1;             
                    */
                }
            }
        }
	}

    protected function createSendThread($order=null) {
        $dbp = $this->dm->dbProvider()->getDBParams();
        return new sendThread($dbp, $order?$order:$this->data, $this->user, $this->time, $this->corder);
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

        $pairA      = explode('_', $this->data['pair']);   
        $cur_in_id  = $this->dm->curID($pairA[0]);
        $cur_out_id = $this->dm->curID($pairA[1]);

        $market_order = $this->dm->getCurrentOrder($cur_in_id, $cur_out_id);
        $price = $market_order['bid_top'];
        $avgPrice = $this->avg($market_order);

        $take_profit = $this->data['take_profit'] <= $price;
        $stop_loss = $this->data['stop_loss'] >= $price;

        if ($take_profit || $stop_loss) {

            $order = array_merge([
                'action'=>dataModule::actionObject($order['action'], $order['volume'], $action_state_set)
            ], $this->data);

            $sendWorker = $this->createSendThread($order);
            $sendWorker->start();

            /*
            $this->data['state'] = 'success';
            $this->dm->setOrderState($this->data['id'], $this->data['state']);
            console::log('EXTENDS, PRICE: '.$price);
            $this->sendUserEvent('ORDERSUCCESS', ['cur_avgprice'=>$avgPrice]);
            */
        } 
    }

}

?>