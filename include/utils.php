<?
    define('REFRESHMIN', 15);
    
    function curID($cur_sign) {
        $query = "SELECT * FROM ".DBPREF."_currency WHERE `sign`='$cur_sign'";
        $cur_rec = DB::line($query);
        if ($cur_rec) return $cur_rec['cur_id'];
        else {
            DB::query("INSERT INTO ".DBPREF."_currency (`sign`, `name`) VALUES ('{$cur_sign}', '{$cur_sign}')");
            return DB::lastID();
        }        
    } 
    
    function r($val, $round) {
        return round($val * $round)/$round;
    }
      
    function getCachedData($fileName, $queryA, $refresh=false) {
        $filetime = @filectime($fileName);
        
        $REALDATA = $refresh || (((time() - $filetime) / 60) > REFRESHMIN);
        if ($REALDATA) $query = $queryA;
        else $query = $fileName;
        
        $str_cnt = file_get_contents($query);
        
        if ($REALDATA) {
            $file = fopen($fileName, 'w+');
            fwrite($file, $str_cnt);
            fclose($file);
        }
        return json_decode($str_cnt, true);
    } 
    
    function sesVar($name, $defVal='', $reset=false) {
        GLOBAL $_SESSION, $request;
        
        if ($rval = $request->getVar($name)) return $_SESSION[$name] = $rval;
        else if (!$reset || isset($_SESSION[$name])) return $_SESSION[$name];
        else return $_SESSION[$name] = $defVal;
    }

    function cnvValue($val, $maxVal=1) {
        if (is_string($val) && (strpos($val, "%") > -1)) return floatval(str_replace("%", '', $val)) / 100 * $maxVal;
        else return floatval($val);
    }

    function isState($obj, $value) {
        return isset($obj['state']) && ($obj['state'] == $value);
    }

    function isActive($obj) {
        return isState($obj, 'active');
    }

    /*
        Выполнять при старте скрипта, $code = уникальный код из md5(время запуска)
    */
    function startScript($script, $code, $waitSec=0) {
        while (time() != timeObject::sTime()) sleep(1);

        if ($rec = cronReportData($script)) {
            DB::query("UPDATE _cron_report SET `code`='$code', `time`=NOW(), `period`={$waitSec} WHERE `script`='{$script}'");
            $waitSec = $waitSec - (time() - strtotime($rec['time'])); // Расчитываем время задержки
        } else DB::query("INSERT INTO _cron_report (`script`, `time`, `data`, `code`, `period`) VALUES ('{$script}', NOW(), '{$data}', '{$code}', {$waitSec})");

        if ($waitSec > 0) sleep($waitSec);
    }

    function cronReport($script, $data) {
        if (!is_string($data)) $data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        DB::query("UPDATE _cron_report SET `time`=NOW(), `data`='{$data}' WHERE `script`='{$script}'");
    }

    function cronReportData($script) {
        $query = "SELECT * FROM _cron_report WHERE `script`='{$script}'";
        return DB::line($query);
    }

    function isStopScript($script, $code) {
        $result = false;
        if ($rec = cronReportData($script)) {
            $result = $rec['code'] != $code;
        }
        return $result;
    }
?>