<?
    function getMarketId($marketSymbol) {
        if ($res = DB::line("SELECT * FROM _markets WHERE name='$marketSymbol'")) {
            return $res['id'];
        } else {
            DB::query("INSERT INTO _markets (`name`) VALUES ('$marketSymbol')");
            return DB::lastID();
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
    function startScript($script, $code, $waitSec=0, $data='') {
        while (time() != timeObject::sTime()) sleep(1);

        if ($rec = cronReportData($script)) {
            DB::query("UPDATE _cron_report SET `code`='$code', `time`=NOW(), `period`={$waitSec} WHERE `script`='{$script}'");
            $waitSec = $waitSec - (time() - strtotime($rec['time'])); // Расчитываем время задержки
            $rec['data'] = json_decode($rec['data'], true);
        } else DB::query("INSERT INTO _cron_report (`script`, `time`, `data`, `code`, `period`) VALUES ('{$script}', NOW(), '{$data}', '{$code}', {$waitSec})");

        if ($waitSec > 0) sleep($waitSec);

        return $rec;
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