<?
    class checkPair {
        protected $tradeClass;
        protected $options;
        protected $crawler;
        protected $tradecount;
        protected $glass;
        protected $wallkf;
        protected $pdirect;
        protected $candles;
        function __construct($symbol, &$tradeClass, &$crawler, $options) {
            $this->symbol           = $symbol;
            $this->tradeClass       = $tradeClass;
            $this->crawler          = $crawler;
            $this->options          = $options;
            $this->tradecount       = 0;
            $this->wallkf           = 0.3;
            $this->pdirect          = new Queue(6);
            $this->extrapolate      = is_numeric($this->options['EXTRAPOLATE'])?$this->options['EXTRAPOLATE']:1;
        }

        private function resetCandles() {
            if ($this->candles) $this->candles->dispose();

            $time   = time();
            $this->candles = new Candles($this->crawler, $this->symbol, $this->options['CANDLEINTERVAL'] * 60, $time, 
                                    $time - 60 * $this->options['CANDLEINTERVAL'] * $this->options['CANDLECOUNT']);
            $this->candles->update($time);
        }

        function checkMACD_BB($returnCandle=false) {
            $result = false;
            $this->resetCandles();

            // Проверяем восходящуюю EMA, см. параметры EMAINTERVAL и MINEMASLOPE. EMAINTERVAL - число, либо "none"

            $interval = $this->options['MANAGER']['EMAINTERVAL'];
            if ($interval) {
                $ema    = $this->candles->ema($interval);
                $slope  = ($ema[count($ema) - 1] - $ema[0])/$ema[count($ema) - 1]; 
                if ($slope >= $this->options['MANAGER']['MINEMASLOPE'])
                    $result = true;
                else $result = "SLOPE: $slope\n";

            } else $result = true;

            if (!is_string($result) && $result && $this->options['MANAGER']['MACD']) {
                if (!is_string($result = $this->candles->buyCheck($this->options['MANAGER']['MACD'], 
                                floatval($this->options['MANAGER']['buy_macd_value']), 
                                floatval($this->options['MANAGER']['buy_macd_direct'])))) { 
                    $result = $returnCandle?$this->candles:true;
                }
            }

            if (!is_string($result) && $result && ($this->options['BB'])) {
                if (!is_string($result = $this->candles->checkBB($this->options['BB'], 0, $this->options['BB']['BUY_LIMIT']))) { 
                    $result = $returnCandle?$this->candles:true;
                }
            }

            //if (!$returnCandle) $this->candles->dispose();        
            return $result;
        }

        public function lastVolumes($count = 3) {
            if (!$this->candles) $this->resetCandles();

            $volues = $this->candles->getData(5);
            $vcount = count($volues);
            $result = [];
            for ($i=$vcount - 1; $i>=$vcount - $count; $i--)
                $result[] = $volues[$i];
            return $result;
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

        public function glassCheck($orders) {
            $result = ['state'=>'none', 'msg'=>''];
            $prices    = $this->tradeClass->lastPrice($this->symbol);

            $echo = '';
            if ($this->tradecount == 0) $this->tradecount = $this->options['MINTRADECOUNT'];

            $volumes = $this->tradeClass->lastVolumes($this->symbol, $this->tradecount, $this->options['TRADETIME']);
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

//                    $price = $this->glass->curPrice($this->options['state']=='buy'?'ask':'bid');//$prices[$this->options['state']];
                    //$price = $this->glass->curPrice($this->options['state']=='buy'?'ask':'bid');
                    $price = ($prices['buy'] + $prices['sell'])/2;
                    $correct_count = 0;

                    do {
                        $auto = $this->options['EXTRAPOLATE'] == 'AUTO';
                        $stop = $this->glass->extrapolate($buy_persec, $sell_persec, $volumes['time_delta'] * $this->extrapolate);
                        $trade_direct = calcDirect($volumes['sell_wgt'], $volumes['buy_wgt']);

                        $left_price     = $stop['bid']['price'];
                        $right_price    = $stop['ask']['price'];

                        if (isset($this->options['DIMENSIONLEVELS'])) { 
                            // Проверяем на пересечение уровня

                            $dml            = $this->options['DIMENSIONLEVELS'];
                            $bottom_level   = floor($price / $dml) * $dml;
                            $top_level      = ceil($price / $dml) * $dml;

                            if ($left_price < $bottom_level) {
                                $left_price = $bottom_level;
                                $echo .= "Correct left!\n";
                            } 
                            if ($right_price > $top_level) {
                                $right_price = $top_level;
                                $echo .= "Correct right!\n";
                            }
                        }

                        $result['left_price']   = $left_price;
                        $result['right_price']  = $right_price;
                        $spred         = $right_price - $left_price;
                        $spred_percent = $spred / $price;

                        if ($auto && ($spred_percent < $this->options['MINSPRED'])) {
                            $correct_count++;
                            if ($correct_count > 10) break;
                            $this->extrapolate += 1;
                        } else break;

                    } while ($auto);


                    if ($spred > 0) {

                        //$price_direct   = calcDirect($price - $left_price, $right_price - $price);
                        $this->pdirect->push(calcDirect($price - $left_price, $right_price - $price));
                        $price_direct   = $this->pdirect->weighedAvg();

                        $spredPercent  = 1; // Процент цены до стенки


                        $left           = min(max(($price - $left_price) / $spred, 0), 1);

                        $to_right_percent   = ($right_price - $price) / $right_price * 100;
                        $to_left_percent    = ($price - $left_price) / $price * 100;

                        $min_profit         = $this->options['MANAGER']['min_right_wall'] + $this->options['MANAGER']['commission'] * 2;

                        $rate               = $this->options['MANAGER']['direct_rate'];
                        $direct             = ($trade_direct * (1 - $rate)) + ($price_direct * $rate);

    //                    $isBuy = ($left < 0.4) && ($to_right_percent >= $min_profit) && ($direct > $this->options['MANAGER']['min_buy_direct']);
                        $isBuy = ($left < $this->options['MANAGER']['max_left_dist']) && 
                                //($to_right_percent >= $min_profit) && РАССМАТРИВАТЬ МИНИМАЛЬНЫЙ ПРОФИТ
                                ($direct >= $this->options['MANAGER']['min_buy_direct']);
                        /*$isSell = ($left / 1 > $this->options['MANAGER']['min_right_dist']) && 
                                ($direct <= $this->options['MANAGER']['max_sell_direct']);*/
                                
                        $isSell = $direct <= $this->options['MANAGER']['max_sell_direct'];

                       // $echo .= "($left < 0.4) && ($to_right_percent >= $min_profit) && ($direct > {$this->options['MANAGER']['min_buy_direct']})\n";

                        //$echo .= "TIME DELTA: {$volumes['time_delta']}\n";
                        $echo .= ($isBuy?'BUY ':'').($isSell?'SELL ':'')."TRADE DIRECT: ".sprintf(NFRM, $trade_direct).
                                ", PRICE DIRECT: ".sprintf(NFRM, $price_direct).
                                ", SPRED: ".sprintf(NFRMS, $spred_percent * 100)."%, EXTRAPOLATE: ".$this->extrapolate."\n";

                        $echo .= $this->inWall($left, $price, $left_price, $right_price, $direct);    

                        $result['price']        = $this->glass->curPrice($isBuy?'ask':'bid');
                        $result['isBuy']        = $isBuy;
                        $result['isSell']       = $isSell;
                    } else $echo = "Increase EXTRAPOLATE!\n";
                }
            }

            $result['msg'] = $echo;
            return $result;
        }
    }
?>