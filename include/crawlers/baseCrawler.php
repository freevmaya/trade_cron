<?

class baseCrawler {
	public function getOrders() {

	}

	public function getTrades() {

	}

	protected function checkError($data) {
		return null;
	}

	public function query($url, $req=null) {
		// generate the POST data string
		//echo $url;
	    static $ch = null;
	    if (is_null($ch)) {
	        $ch = curl_init();
	        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; PHP client; ' . php_uname('s') . '; PHP/' . phpversion() . ')');
	    }

	    curl_setopt($ch, CURLOPT_URL, $url);
	    if ($req) {
	    	$post_data = http_build_query($req, '', '&');
		    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
	    }
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	    $res = curl_exec($ch);

	    if (($res) === false) {
	    	return ['error'=>'Could not get reply:'. curl_error($ch), 'error_code'=>curl_errno($ch)];
	    } else {
	    	$data = json_decode($res, true);
	    	if ($reterror = $this->checkError($data)) 
	    		return $reterror;
	    }

	    return $data;
	}
}

?>