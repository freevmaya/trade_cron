<?
    class checkPair {
        protected $directAvg;
        protected $directAvgLong;
        protected $trade_volumes;
        protected $tradeClass;
        protected $tradecount;
        protected $glass;
        protected $wallkf;
        function __construct($symbol, $tradeClass) {
            $this->symbol           = $symbol;
            $this->directAvg        = new Queue(3);
            $this->directAvgLong    = new Queue(6);
            $this->trade_volumes    = ['buy'=>new Queue(12), 'sell'=>new Queue(12)];
            $this->tradeClass       = $tradeClass;
            $this->tradecount       = 0;
            $this->wallkf           = 0.3;
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

        public function check($orders, $options=['state'=>'sell']) {

            $result = ['state'=>'none', 'msg'=>''];

//            $timeCount = $this->tradeClass->timeCountSec($this->symbol);
            $prices    = $this->tradeClass->lastPrice($this->symbol);

            $echo = '';
            if ($this->tradecount == 0) $this->tradecount = $options['MINTRADECOUNT'];

//            if ($timeCount > $this->tick) {
                $volumes = $this->tradeClass->lastVolumes($this->symbol, $this->tradecount);
                $allvol = $volumes['buy_wgt'] + $volumes['sell_wgt'];

                //print_r($volumes);

                if ($allvol > 0) {

                    $this->trade_volumes['buy']->push($volumes['buy']);
                    $this->trade_volumes['sell']->push($volumes['sell']);

                    $avgBuyVol = $this->trade_volumes['buy']->weighedAvg();
                    $avgSellVol = $this->trade_volumes['sell']->weighedAvg();

                    // Текущая скорость покупок и продаж в сек.
                    $buy_persec = $volumes['buy_persec']; 
                    $sell_persec = $volumes['sell_persec'];

                    // Избегаем нулевой скорости
                    $buy_persec = ($buy_persec<=0)?($sell_persec * 0.01):$buy_persec;
                    $sell_persec = ($sell_persec<=0)?($buy_persec * 0.01):$sell_persec;
                    $direct_speed = $buy_persec - $sell_persec;


                    $echo .= "-----------------{$this->symbol}-TRADECOUNT-{$this->tradecount}------------------\n";
    //                        $echo .= 'SPEED SELL '.sprintf(NFRM, $sell_persec).', BUY '.sprintf(NFRM, $buy_persec)."\n";

                    if (!(($buy_persec > 0) && ($sell_persec > 0))) {
                        $this->tradecount += 5;
                    } else {

                        if (!$this->glass) $this->glass = new Glass($orders);
                        else $this->glass->setOrders($orders);
                        $price = $this->glass->curPrice($options['state']=='buy'?'ask':'bid');//$prices[$options['state']];



                        $direct = $volumes['buy_wgt']/$allvol - $volumes['sell_wgt']/$allvol;
                        $this->directAvg->push($direct);
                        $this->directAvgLong->push($direct);

                        $direct_s = $this->directAvgLong->weighedAvg();                        

                        $stop = $this->glass->extrapolate(max($buy_persec, 1), max($sell_persec, 1), $volumes['time_delta']);
                        $hist = $this->glass->histogram(0, $price * 0.8, $price * 1.2);

                        $d = 80;
                        $wallkf = $this->wallkf;

                        while (true) {
                            $abs_vol = $avgBuyVol + $avgSellVol;
                            $ask_walls = $this->glass->walls($hist['ask'], $abs_vol * $wallkf);
                            $bid_walls = $this->glass->walls($hist['bid'], $abs_vol * $wallkf);
                            if ((count($ask_walls) == 0) || (count($bid_walls) == 0)) {
                                $wallkf *= 0.6;
                            } else if ((count($ask_walls) > 5) || (count($bid_walls) > 5)) {
                                $wallkf *= 1.4;
                            } else break;
                            $d--;
                            if ($d == 0) break;
                        } 
                        $this->wallkf = $wallkf;

                        //print_r($hist['bid']);
                        //print_r($bid_walls);
                        $direct_trend = $this->directAvgLong->trends();

                        if ((count($ask_walls) > 0) && (count($bid_walls) > 0)) {
                            //print_r($bid_walls);
                            //print_r($ask_walls);

                            $spreedPercent  = 1; // Процент цены до стенки

                            $spreed         = $ask_walls[0][0] - $bid_walls[0][0];

                            $left_price     = $bid_walls[0][0];
                            $right_price    = $ask_walls[0][0];

                            $left           = min(max(($price - $left_price) / $spreed, 0), 1);

                            $to_right_percent   = ($right_price - $price) / $right_price * 100;
                            $to_left_percent    = ($price - $left_price) / $price * 100;

                            $left_buy = (1 - $left) + $direct_s * 0.6;
                            $right_sell = $left - $direct_s * 0.6;

                            $echo .= "LEFT_BUY: {$left_buy}, RIGHT_SELL: {$right_sell}\n";

                            if (($direct_trend >= 0) && ($left < 0.8) && 
                                ($left_buy >= $options['BUYPOWER']) && 
                                ($to_right_percent >= $spreedPercent)) $state = 'buy';

                            else if ($right_sell >= $options['SELLPOWER']) $state = 'sell';
//                            else if (($direct_trend <= 0) && ($right_sell > $options['SELLPOWER']) && ($to_left_percent >= $spreedPercent)) $state = 'sell';

                            else $state = 'wait';

                            $echo .= 'DIRECT: '.sprintf(NFRM, $direct_s).", DIRECT_TRENDS: ".sprintf(NFRM, $direct_trend).
                                    ", STATE: {$state}, TOLEFT: ".round($to_left_percent)."%, TORIGHT: ".round($to_right_percent)."%\n";
                            $result['state'] = $state;// && 
                            $result['price'] = $price;

                            $echo .= $this->inWall($left, $price, $left_price, $right_price, $direct_s);
/*                            
                            $askWall = $this->glass->maxWall($hist['ask']);
                            $bidWall = $this->glass->maxWall($hist['bid']);
*/                            

                            //$wall_ask_q->push($stop['ask']['price']);
                            //$wall_bid_q->push($stop['bid']['price']);

                            //$spreed = $askWall[2] + $bidWall[2];

                            
                        } else {
                            $echo .= "SMALL WALLS, K: {$this->wallkf}\n";
                        }
                    }
                //}
            }

            $result['msg'] = $echo;
            return $result;
        }
    }

?>