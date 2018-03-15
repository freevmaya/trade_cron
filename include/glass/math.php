<?
class Math {

	public static function sum($list, $start, $count) {
		$sum = 0;
		for ($n=$start;$n<$start + $count;$n++) {
			$sum += $list[$n];
		}
		return $sum;
	}

	public static function avg($list, $start, $count) {
		return Math::sum($list, $start, $count)/$count; 
	}

	public static function ema($list, $smoonInterval, $start=0) {
		$end = count($list) - 1;
		$result = [];

		if ($start + $smoonInterval < $end) {
			$a = 2 / ($smoonInterval + 1);
			$i = $start + $smoonInterval;
			$result[] = Math::avg($list, $start, $smoonInterval);
			$n = 0;

			while ($i<=$end) {
				$result[] = $a * $list[$i] + (1 - $a) * $result[$n];
				$i++;
				$n++;
			}
		}
		return $result;
	}

	public static function suba($ema1, $ema2, $start1=0, $start2=0) {
		$res = [];
		$count = min(count($ema1) - $start1, count($ema2) - $start2);

		for ($i=0; $i<$count; $i++)
			$res[] = $ema1[$i + $start1] - $ema2[$i + $start2];

		return $res;
	}
}
?>