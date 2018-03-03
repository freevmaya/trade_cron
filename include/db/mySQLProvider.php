<?

    include_once(dirname(__FILE__).'/dataBaseProvider.php');

	class mySQLProvider extends dataBaseProvider {
		protected $mysqli;
		protected $result_type;


		function __construct($host, $dbname, $user='', $passwd='') {
			parent::__construct($host, $dbname, $user, $passwd);
			$this->result_type = MYSQLI_ASSOC;
		}

		public function connect($host, $dbname, $user='', $passwd='') {
			$this->mysqli = new mysqli($host, $user, $passwd, $dbname);
		    if ($this->mysqli->connect_errno) 
		    	$this->error($this->mysqli->connect_errno.', '.$this->mysqli->error);
		}

		public function close() {
			//trace('close', 'display', 3);
			$this->mysqli->close();
		}

		public function safeVal($str) {
			if (is_array($str) || is_object($str)) $str = json_encode($str);
	        return $this->mysqli->real_escape_string($str);
	    }

		public function query($query) {
			if (!($result = $this->mysqli->query($query)))
				$this->error('mysql_error='.$this->mysqli->error.' query='.$query);

			return $result;
		}

		protected function dbAsArray($query) {
			$result=$this->query($query);
			$ret=array();
			while ($row=$result->fetch_array($this->result_type)) $ret[]=$row;
			$result->free();
			return $ret;
		}

		protected function dbOne($query, $column=0) {
			$row=$this->dbLine($query);
			if ($row===false) return false;
			return array_shift($row);
		}

		protected function dbLine($query) {
			$res = false;
			if ($result = $this->query($query)) {
				if ($result->num_rows >= 1) $res = $result->fetch_array($this->result_type);
				$result->free();
			} 
			return $res;
		}

		public function lastID() {
			return $this->one("SELECT LAST_INSERT_ID()");
		}
	}
?>