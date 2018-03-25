<?
    class checkPair {
        protected $directAvg;
        protected $directAvgLong;
        protected $trade_volumes;
        protected $tradeClass;
        protected $tick;
        protected $glass;
        protected $wallkf;
        function __construct($symbol, $tradeClass) {
            $this->symbol           = $symbol;
            $this->directAvg        = new Queue(3);
            $this->directAvgLong    = new Queue(15);
            $this->trade_volumes    = ['buy'=>new Queue(15), 'sell'=>new Queue(15)];
            $this->tradeClass       = $tradeClass;
            $this->tick             = 120;
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

            $timeCount = $this->tradeClass->timeCountSec($this->symbol);
            $prices    = $this->tradeClass->lastPrice($this->symbol);

            $echo = '';
            if ($timeCount > $this->tick) {
                $volumes = $this->tradeClass->lastVolumes($this->symbol, $this->tick);
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

                    $direct = $volumes['buy_wgt']/$allvol - $volumes['sell_wgt']/$allvol;
                    $this->directAvg->push($direct);
                    $this->directAvgLong->push($direct);

                    $direct_s = $this->directAvgLong->weighedAvg();


                    $echo .= "-----------------{$this->symbol}-TICK-{$this->tick}------------------\n";
    //                        $echo .= 'SPEED SELL '.sprintf(NFRM, $sell_persec).', BUY '.sprintf(NFRM, $buy_persec)."\n";

                    if (!(($buy_persec > 0) && ($sell_persec > 0))) {
                        $this->tick += 5;
                    } else {
                        $price = $prices[$options['state']];

                        if (!$this->glass) $this->glass = new Glass($orders);
                        else $this->glass->setOrders($orders);

                        $stop = $this->glass->extrapolate(max($buy_persec, 1), max($sell_persec, 1), $this->tick);

                        $hist = $this->glass->histogram(0, $price * 0.8, $price * 1.2);

                        $d = 20;
                        $wallkf = $this->wallkf;

                        while (true) {
                            $abs_vol = $avgBuyVol + $avgSellVol;
                            $ask_walls = $this->glass->walls($hist['ask'], $avgBuyVol * $wallkf);
                            $bid_walls = $this->glass->walls($hist['bid'], $avgSellVol * $wallkf);
                            if ((count($ask_walls) == 0) || (count($bid_walls) == 0)) {
                                $wallkf *= 0.9;
                            } else if ((count($ask_walls) > 4) && (count($bid_walls) > 4)) {
                                $wallkf *= 1.1;
                            } else break;
                            $d--;
                            if ($d == 0) break;
                        } 

                        //print_r($hist['bid']);
                        //print_r($bid_walls);
                        $direct_trend = $this->directAvgLong->trends();

                        if ((count($ask_walls) > 0) && (count($bid_walls) > 0)) {
                            $this->wallkf = $wallkf;

                            $spreed  = $ask_walls[0][0] - $bid_walls[0][0];
                            $left    = max(($price - $bid_walls[0][0]) / $spreed, 0);

                            $left_price    = $bid_walls[0][0];
                            $right_price   = $ask_walls[0][0];

                            $to_right_percent = (1 - $price/$right_price) * 100;


                            if (($direct_trend >= 0) && ($direct_s > 0) && ($left < 0.4) && ($to_right_percent >= 0.5)) $state = 'buy';
                            else if (($direct_trend <= 0) && ($direct_s < 0) && ($left > 0.6)) $state = 'sell';
                            else $state = 'wait';

                            $echo .= 'DIRECT: '.sprintf(NFRM, $direct_s).", DIRECT_TRENDS: ".sprintf(NFRM, $direct_trend).
                                    ", STATE: {$state}, TORIGHT: ".round($to_right_percent)."%\n";
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
                            $echo .= "SMALL TRADES\n";
                        }
                    }
                }
            }

            $result['msg'] = $echo;
            return $result;
        }
    }

?>