<?
class dataBaseProvider {
	protected $host; 
	protected $dbname; 
	protected $user; 
	protected $passwd; 
	protected $cache;
	function __construct($host='', $dbname='', $user='', $passwd='') {
		$this->host 	= $host;
		$this->dbname 	= $dbname;
		$this->user 	= $user;
		$this->passwd 	= $passwd;
		$this->connect($host, $dbname, $user, $passwd);
	}

	public function getDBParams() {
		return array(
			'host'=>$this->host,
			'dbname'=>$this->dbname,
			'user'=>$this->user,
			'passwd'=>$this->passwd
		);
	}

	public function connect($host, $dbname, $user='', $passwd='') {

	}

	public function query($query) {

	}

	protected function dbAsArray($query) {
	}

	protected function dbLine($query) {
	}

	protected function dbOne($query) {

	}

	public function lastID() {

	}

	public function close() {

	}

	public function setCacheProvider($cache) {
		$this->cache = $cache;
	}

	public function error($text) {
		$this->log_errors($text, 3);
		throw new Exception($text, 1);
	}

	public function safeVal($str) {
		return $str;
	}

	public function one($query, $cached=false) {
		if ($cached) {
			if (!($cacheData = $this->getCache($query, $key)))
				$this->setCache($query, $cacheData = $this->dbOne($query));

			return $cacheData;
		} else return $this->dbOne($query);
	}

	public function asArray($query, $cached=false) {
		if ($cached) {
			if (!($cacheData = $this->getCache($query, $key)))
				$this->setCache($query, $cacheData = $this->dbAsArray($query));

			return $cacheData;
		} else return $this->dbAsArray($query);
	}

	public function line($query, $cached=false) {
		if ($cached) {
			if (!($cacheData = $this->getCache($query, $key)))
				$this->setCache($query, $cacheData = $this->dbLine($query));

			return $cacheData;
		} else return $this->dbLine($query);
	}
    
    private function setCache($query, $value) {
    	if ($this->cache)
    		$this->cache->set(md5($query), $value);
    }
    
    private function getCache($query, &$cacheKey) {
    	if ($this->cache) {
    		$cacheKey = md5($query);
    		return $this->cache->get($cacheKey);
    	} else return false;
    }

	protected function log_errors($message, $level=2) {
		$file_log=_file_log;

		if (!isset($file_log)) {
			return "ERROR: NOT DEFINED \$file_log, '$message'";
		}
	    
	    $message = date('Y-m-d H:i:s');
	    if (function_exists('GetStack')) {
	    	$stack = GetStack();
		    $message .= "function=\"{$stack[$level]['file']}=>{$stack[$level]['line']}\"";
		}
		$message .= "message=\"$message\"\n";

		if ($handle = fopen($file_log,'a')) {
			fwrite($handle, "$message");
			fclose($handle);
			return $message;
		} else {
			return "ERROR: unable to open log file \"$file_log\", '$message'\n";
		}
	}
}
?>