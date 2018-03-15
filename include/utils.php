<?
    function getMarketId($dbp, $marketSymbol) {
        if ($res = $dbp->line("SELECT * FROM _markets WHERE name='$marketSymbol'")) {
            return $res['id'];
        } else {
            $dbp->query("INSERT INTO _markets (`name`) VALUES ('$marketSymbol')");
            return $dbp->lastID();
        }
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
    function startScript($dbp, $script, $code, $waitSec=0, $data='', $is_dev=false) {
        if (!$is_dev)
            while (time() != timeObject::sTime()) sleep(1);

        if ($rec = cronReportData($dbp, $script)) {
            $dbp->query("UPDATE _cron_report SET `code`='$code', `time`=NOW(), `period`={$waitSec} WHERE `script`='{$script}'");
            $waitSec = $waitSec - (time() - strtotime($rec['time'])); // Расчитываем время задержки
            $rec['data'] = json_decode($rec['data'], true);
        } else $dbp->query("INSERT INTO _cron_report (`script`, `time`, `data`, `code`, `period`) VALUES ('{$script}', NOW(), '{$data}', '{$code}', {$waitSec})");

        if (!$is_dev && ($waitSec > 0)) sleep($waitSec);

        return $rec;
    }

    function cronReport($dbp, $script, $data) {
        if (!is_string($data)) $data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $dbp->query("UPDATE _cron_report SET `time`=NOW(), `data`='{$data}' WHERE `script`='{$script}'");
    }

    function cronReportData($dbp, $script) {
        $query = "SELECT * FROM _cron_report WHERE `script`='{$script}'";
        return $dbp->line($query);
    }

    function isStopScript($dbp, $script, $code) {
        $result = false;
        if ($rec = cronReportData($dbp, $script)) {
            $result = $rec['code'] != $code;
        }
        return $result;
    }

    function merge($arr1, $arr2, $fields) {
        $res = [];
        foreach ($fields as $field) {
            if (is_object($arr2)) $val = isset($arr2->$field)?$arr2->$field:(isset($arr1[$field])?$arr1[$field]:'');
            else $val = isset($arr2[$field])?$arr2[$field]:(isset($arr1[$field])?$arr1[$field]:'');
            $res[$field] = $val;
        }
        return $res;
    }

    function object_to_array($obj) {
        $_arr = is_object($obj) ? get_object_vars($obj) : $obj;
        foreach ($_arr as $key => $val) {
                $val = (is_array($val) || is_object($val)) ? object_to_array($val) : $val;
                $arr[$key] = $val;
        }
        return $arr;
    }
        

    function varavg($list, $count_k) {
        $inc    = ($count_k>0)?1:-1;
        $countl = count($list);
        $i      = ($count_k>0)?0:(count($list)-1);
        $count  = $countl * $count_k;
        $accum  = 0;
        if ($countl > 1) { 
            $n      = abs($count) + 1;
            $an     = 2 / $n;
            $d      = $an / ($n - 1);
            while (($i >= 0) && ($i < $countl)) {
                if (!isset($list[$i])) {
                    print_r($list);
                }

                $accum += $list[$i] * $an;
                
                $i += $inc;
                $an -= $d;
            }
        } else if ($countl > 0) $accum = $list[0];
        return $accum;
    } 
?>