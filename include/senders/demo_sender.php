<?

class demo_sender extends api_sender {
	protected $dm;
	function __construct($dm) {
		$this->dm = $dm;
    }

	public function api_query($api_name, array $req = array()) 	{
	    return ["result"=>true, "demo"=>1];
	}

	public function order_create_api($params) {
		return ["result"=>true,
				"demo"=>1,
	    		"api_name"=>'order_create',
  				"order_id"=>rand(1, 100000000)];
	}

	protected function refreshUserInfo() {
		return $this->user_info = ['balances'=>$this->dm->getBalances($this->user['uid'], $this->user['account_id'])];
	}
}

?>