<?
    class checkPair {
        protected $tradeClass;
        protected $tradecount;
        protected $glass;
        protected $wallkf;
        protected $pdirect;
        function __construct($symbol, $tradeClass) {
            $this->symbol           = $symbol;
            $this->tradeClass       = $tradeClass;
            $this->tradecount       = 0;
            $this->wallkf           = 0.3;
            $this->pdirect          = new Queue(6);
        }

        protected function inWall($left, $price, $left_price, $right_price, $direct_s) {
            $tires         = 20;
            $left_tires    = ceil(max(min($left, 1), 0) * $tires); 
            $right_tires   = $tires - $left_tires; 
            //$echo .= 'BID: '.sprintf(NFRM, $wall_bid_q->weighedAvg()).' ASK: '.sprintf(NFRM, $wall_ask_q->weighedAvg())."\n";

            return sprintf(NFRM, $left_price).
                        str_repeat('-', $left_tires + ($direct_s<0?0:1)).($direct_s<0?'<':'').
                    sprintf(NFRM, $price).
                        ($direct_s<0?'':'>').str_repeat('-', $right_tires + ($direct_s<0?1:0)).
                    sprintf(NFRM, $right_price)."\n";
        }

        public function check($orders, $options=[]) {
            $result = ['state'=>'none', 'msg'=>''];
            $prices    = $this->tradeClass->lastPrice($this->symbol);

            $echo = '';
            if ($this->tradecount == 0) $this->tradecount = $options['MINTRADECOUNT'];

            $volumes = $this->tradeClass->lastVolumes($this->symbol, $this->tradecount, $options['TRADETIME']);
            $allvol = $volumes['buy_wgt'] + $volumes['sell_wgt'];

            if ($allvol > 0) {

/*
                $this->trade_volumes['buy']->push($volumes['buy']);
                $this->trade_volumes['sell']->push($volumes['sell']);

                $avgBuyVol = $this->trade_volumes['buy']->weighedAvg();
                $avgSellVol = $this->trade_volumes['sell']->weighedAvg();
*/                

                // Текущая скорость покупок и продаж в сек.
                $buy_persec = $volumes['buy_persec']; 
                $sell_persec = $volumes['sell_persec'];

                // Избегаем нулевой скорости
                $buy_persec = ($buy_persec<=0)?($sell_persec * 0.01):$buy_persec;
                $sell_persec = ($sell_persec<=0)?($buy_persec * 0.01):$sell_persec;
                $direct_speed = $buy_persec - $sell_persec;


    //                        $echo .= 'SPEED SELL '.sprintf(NFRM, $sell_persec).', BUY '.sprintf(NFRM, $buy_persec)."\n";

                if (!(($buy_persec > 0) && ($sell_persec > 0))) {
                    $this->tradecount += 5;
                } else {

                    if (!$this->glass) $this->glass = new Glass($orders);
                    else $this->glass->setOrders($orders);

//                    $price = $this->glass->curPrice($options['state']=='buy'?'ask':'bid');//$prices[$options['state']];
                    //$price = $this->glass->curPrice($options['state']=='buy'?'ask':'bid');
                    $price = ($prices['buy'] + $prices['sell'])/2;

                    $stop = $this->glass->extrapolate($buy_persec, $sell_persec, $volumes['time_delta'] * $options['EXTRAPOLATE']);
                    $trade_direct = calcDirect($volumes['sell_wgt'], $volumes['buy_wgt']);

                    $left_price     = $stop['bid']['price'];
                    $right_price    = $stop['ask']['price'];

                    //$price_direct   = calcDirect($price - $left_price, $right_price - $price);
                    $this->pdirect->push(calcDirect($price - $left_price, $right_price - $price));
                    $price_direct   = $this->pdirect->weighedAvg();

                    $spreedPercent  = 1; // Процент цены до стенки

                    $spreed         = $right_price - $left_price;

                    $left           = min(max(($price - $left_price) / $spreed, 0), 1);

                    $to_right_percent   = ($right_price - $price) / $right_price * 100;
                    $to_left_percent    = ($price - $left_price) / $price * 100;

                    $min_profit         = $options['MANAGER']['min_right_wall'] + $options['MANAGER']['commission'] * 2;

                    $rate               = $options['MANAGER']['direct_rate'];
                    $direct             = ($trade_direct * (1 - $rate)) + ($price_direct * $rate);

//                    $isBuy = ($left < 0.4) && ($to_right_percent >= $min_profit) && ($direct > $options['MANAGER']['min_buy_direct']);
                    $isBuy = ($left < $options['MANAGER']['max_left_dist']) && 
                            //($to_right_percent >= $min_profit) && РАССМАТРИВАТЬ МИНИМАЛЬНЫЙ ПРОФИТ
                            ($direct >= $options['MANAGER']['min_buy_direct']);
                    /*$isSell = ($left / 1 > $options['MANAGER']['min_right_dist']) && 
                            ($direct <= $options['MANAGER']['max_sell_direct']);*/
                            
                    $isSell = $direct <= $options['MANAGER']['max_sell_direct'];

                   // $echo .= "($left < 0.4) && ($to_right_percent >= $min_profit) && ($direct > {$options['MANAGER']['min_buy_direct']})\n";

                    if ($isBuy && isset($options['DIMENSIONLEVELS'])) { 
                        // Проверяем на пересечение уровня, если левая граница на другом уровне, тогде не покупать

                        $dml = $options['DIMENSIONLEVELS'];
                        $level = floor($price / $dml) * $dml;

                        if (floor($left_price / $dml) * $dml != $level) {
                            $echo .= "Intersection level: ".sprintf(NFRM, $level)."!\n";
                            $isBuy = false;
                        }
                    }

                    //$echo .= "TIME DELTA: {$volumes['time_delta']}\n";
                    $echo .= ($isBuy?'BUY ':'').($isSell?'SELL ':'')."TRADE DIRECT: ".sprintf(NFRM, $trade_direct).
                            ", PRICE DIRECT: ".sprintf(NFRM, $price_direct).
                            ", TOLEFT: ".sprintf(NFRMS, $to_left_percent)."%, TORIGHT: ".sprintf(NFRMS, $to_right_percent)."%\n";

                    $echo .= $this->inWall($left, $price, $left_price, $right_price, $direct);    

                    $result['price']        = $this->glass->curPrice($isBuy?'ask':'bid');
                    $result['left_price']   = $left_price;
                    $result['right_price']  = $right_price;
                    $result['isBuy']        = $isBuy;
                    $result['isSell']       = $isSell;
                }
            }

            $result['msg'] = $echo;
            return $result;
        }
    }
?>