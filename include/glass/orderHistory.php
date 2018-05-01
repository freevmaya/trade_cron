<?
class orderHistory {
	private $fileName;
	private $defCoinInfo;
	private $sender;
	public  $list;

	function __construct($sender, $fileName, $defCoinInfo=['purchase'=>null, 'profit'=>0]) {
		$this->fileName 	= $fileName;
		$this->defCoinInfo 	= $defCoinInfo;
		$this->sender 		= $sender;
		$this->list 		= $this->readFileData();
	}

    private function readFileData() {
        if (file_exists($this->fileName)) {
            $file_data = json_decode(file_get_contents($file_name), true);
        } else $file_data = [];
        return $file_data;
    }

    public function totalProfit($currency="BNB") {
        $allprofit = [];

        if ($history) {
            foreach ($history as $pair=>$item) {
                $ap = explode('_', $pair); $pix = $ap[1];
                if (!isset($allprofit[$pix])) $allprofit[$pix] = 0;
                $allprofit[$pix] += $item['profit'];
            }
        }

        $this->sender->resetPrices();
        
        return "TOTAL PROFIT: ".print_r($allprofit, true)."\nBALANCE TO {$currency}: ".sprintf(NFRM, $this->sender->calcBalance($currency));
    }

    public function purchaseSymbols() {
    	$symbols = [];

		foreach ($this->list as $pair=>$item) {
            if ((count($item['list']) > 0) && (array_search($pair, $symbols) === false)) $symbols[] = $pair;
        }    	
        return $symbols;
    }

    public function save() {
        file_put_contents($this->fileName, json_encode($this->list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

?>