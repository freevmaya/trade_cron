<?
class sendThread extends Thread {
    private $order;
    private $market_data;
    private $time;
    private $user;
    private $dbparams;
    public function __construct($dbparams, $order, $user, $time, $market_data){
        $this->order        = $order;
        $this->user         = $user;
        $this->time         = $time;
        $this->market_data  = $market_data;
        $this->dbparams     = $dbparams;
    }

    function run() {
        $worker = new sendWatchOrder($this->dbparams, $this->order, $this->user, $this->time, $this->market_data);
        $worker->work();
    }
}


?>