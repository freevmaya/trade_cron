<?
class cronExecManager {
    public static function getReport($script) {
        $query = "SELECT * FROM _cron_report WHERE `script`='{$script}'";
        return DB::line($query);
    }

    public static function report($script, $data) {
        if (!is_string($data)) $data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        DB::query("REPLACE _cron_report (`script`, `time`, `data`, `stop_require`) VALUES ('{$script}', NOW(), '{$data}', 0)");
    }

	/* 
		Вызывать в конце цикла скрипта
		Сохраняет данные о цикле исполнения и проверяет наличии требования завершении работы.
		Если есть требование, возвращает "STOP" 
	*/
    public static function checkControl($script, $data) { 
    	$result = false;
    	$rec = cronExecManager::getReport($script);
		cronExecManager::report($script, $data);    	

    	if ($rec && ($rec['stop_require'] == 1)) {
    		print_r($rec);
    		$result = 'STOP';
    	}
		return $result;
    }

    /*
    	Вызывать перед исполенение основного цикла скрипта
		Отправляет сигнал для завершения, ранее запущеного скрипта.
		Ждет завершение, после передает управление дальше
    */
    public static function demandControl($script, $last=10, $isDev=false) {
    	if ($report = cronExecManager::getReport($script)) {
  			/*
  				Если ранее запущенный скрипт сохранял данные крайний раз не более $last минут назад
  					Отправляем ему сигнал на остановку,	ждем остановки, передаем управление дальше.
  				Иначе считаем его повисшим и передаем управление дальше
  			*/

    		if (!$isDev && (strtotime($report['time']) + 60 * $last < time())) return;

			DB::query("UPDATE _cron_report SET `stop_require`=1 WHERE `script`='{$script}'");
			do {
		    	$report = cronExecManager::getReport($script);
			} while ($report['stop_require'] == 0);
    	}
    }
}
?>