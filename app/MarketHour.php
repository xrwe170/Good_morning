<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Elasticsearch\ClientBuilder;
use App\Jobs\SendMarket;

class MarketHour extends Model
{
    protected $table = 'market_hour';
    public $timestamps = false;

    protected static $esClient = null;

    protected static $period = [
        5 => "1min",
        6 => "5min",
        1 => "15min",
        7 => "30min",
        2 => "60min",
        3 => "1hour",
        4 => "1day",
        8 => "1week",
        9 => "1mon",
        10 => "1year",
    ];

    /**
     * 获得一个ElasticsearchClient实例
     *
     * @return \Elasticsearch\Client
     */
    public static function getEsearchClient()
    {
        if (is_null(self::$esClient)) {
            // $hosts = config('elasticsearch.hosts');
            $hosts=array("localhost","9200");
            self::$esClient = ClientBuilder::create()
                ->setHosts($hosts)
                ->build();
        }
        return self::$esClient;
    }

    /**
     * 批量写入行情数据
     *
     * @param integer $currency_id 币种ID
     * @param integer $legal_id 法币ID
     * @param float $num 成交数量
     * @param float $price 成交价
     * @param integer $sign 来源标记[0.默认,1.交易更新,2.接口,3.后台添加
     * @param integer|null $time 时间戳
     * @param bool $cumulation 是否累计交易量,默认累计
     * @return void
     */
    public static function batchWriteMarketData($currency_id, $legal_id, $num, $price, $sign = 0, $time = null, $cumulation = true)
    {
        //$type类型:1.15分钟,2.1小时,3.4小时,4.一天,5.分时,6.5分钟，7.30分钟,8.一周,9.一月,10.一年
        empty($time) && $time = time();
        $types = [5, 6, 1, 7, 2, 3, 4, 8, 9, 10];
        $start = microtime(true);
        //写入行情数据
        DB::beginTransaction();
        foreach ($types as $key => $value) {
            $data = [];
            $timeline = self::getTimelineInstance($value, $currency_id, $legal_id, $sign, $time);
            bc_comp($timeline->start_price, 0) <= 0 && $data['start_price'] = $price;
            $data['end_price'] = $price;
            bc_comp($timeline->highest, $price) < 0 && $data['highest'] = $price;
            if (bc_comp($timeline->mminimum, 0) <= 0 || bc_comp($timeline->mminimum, $price) > 0) {
                $data['mminimum'] = $price;
            }
            $data['number'] = $cumulation ? bc_add($timeline->number, $num, 5) : $num;
            $result = $timeline->updateTimelineData($data);
            unset($timeline);
            unset($data);
        }
        DB::commit();
        $end = microtime(true);
        // echo '本次插入执行'. ($end - $start) . '秒';
    }


    /**
     * 批量写入行情数据
     *
     * @param integer $currency_id 币种ID
     * @param integer $legal_id 法币ID
     * @param array $market_data 行情数据
     * @param integer $sign 来源标记[0.默认,1.交易更新,2.接口,3.后台添加
     * @param integer|null $time 时间戳
     * @return void
     */
    public static function batchWriteKlineMarket($currency_id, $legal_id, $market_data, $sign = 0, $time = null)
    {
        //$type类型:1.15分钟,2.1小时,3.4小时,4.一天,5.分时,6.5分钟，7.30分钟,8.一周,9.一月,10.一年
        empty($time) && $time = time();
        $types = [5, 6, 1, 7, 2, 3, 4, 8, 9, 10];
        $start = microtime(true);
        //写入行情数据
        DB::beginTransaction();
        foreach ($types as $key => $value) {
            $data = [];
            $timeline = self::getTimelineInstance($value, $currency_id, $legal_id, $sign, $time);
            if ($value == 5) {
                //1分钟的只要传了就更新
                isset($market_data['open']) && $data['start_price'] = $market_data['open'];
                isset($market_data['close']) && $data['end_price'] = $market_data['close'];
                isset($market_data['high']) && $data['highest'] = $market_data['high'];
                isset($market_data['low']) && $data['mminimum'] = $market_data['low'];
                isset($market_data['amount']) && $data['number'] = $market_data['amount'];
            } else {
                if (isset($market_data['open']) && bc_comp($timeline->start_price, 0) <= 0) {
                    $data['start_price'] = $market_data['open'];
                }
                if (isset($market_data['close'])) {
                    $data['end_price'] = $market_data['close'];
                }
                if (isset($market_data['high']) && bc_comp($timeline->highest, $market_data['high']) < 0) {
                    $data['highest'] = $market_data['high'];
                }
                if (isset($market_data['low']) && (bc_comp($timeline->mminimum, 0) <= 0 || bc_comp($timeline->mminimum, $market_data['low']) > 0)) {
                    $data['mminimum'] = $market_data['low'];
                }
                if (isset($market_data['amount'])) {
                    $sum = self::where('type', 5)
                        ->where('currency_id', $currency_id)
                        ->where('legal_id', $legal_id)
                        ->where('day_time', '>=', $timeline->day_time)
                        ->sum('number');
                    $sum || $sum = 0;
                    $data['number'] = $sum;
                }
            }
            $result = $timeline->updateTimelineData($data);
            unset($timeline);
            unset($data);
        }
        DB::commit();
        $end = microtime(true);
        //echo '本次插入执行'. ($end - $start) . '秒';
    }

    /**
     * 更新当前时间线实例数据
     *
     * @param array $data 包含:start_price,end_price,highest,mminimum,number中任意键的数组
     * @return bool
     */
    public function updateTimelineData($data)
    {
        if (isset($this->day_time) && isset($this->type) && isset($this->currency_id) && isset($this->legal_id)) {
            isset($data['start_price']) && $this->start_price = $data['start_price'];
            isset($data['end_price']) && $this->end_price = $data['end_price'];
            isset($data['highest']) && $this->highest = $data['highest'];
            isset($data['mminimum']) && $this->mminimum = $data['mminimum'];
            isset($data['number']) && $this->number = $data['number'];
            isset($data['period']) || $this->period = self::$period[$this->type];
            $result = $this->save();
            return $result;
        } else {
            return false;
        }
    }

    /**
     * 设置时间线数据
     *
     * @param integer $type 类型:1.15分钟,2.1小时,3.4小时,4.一天,5.分时,6.5分钟，7.30分钟,8.一周,9.一月
     * @param integer $currency_id 币种id
     * @param integer $legal_id 法币id
     * @param array $data 包含:start_price,end_price,highest,mminimum,number中任意键的数组
     * @param integer $sign 来源标记[0.默认,1.交易更新,2.接口,3.后台添加
     * @param integer $day_time 时间戳
     * @return bool
     */
    public static function setTimelineData($type, $currency_id, $legal_id, $data, $sign = 0, $day_time = null)
    {
        empty($day_time) && $day_time = time();
        $timeline = self::getTimelineInstance($type, $currency_id, $legal_id, $sign, $day_time);
        if (empty($data) || !is_array($data)) {
            return false;
        }
        isset($data['start_price']) && $timeline->start_price = $data['start_price'];
        isset($data['end_price']) && $timeline->end_price = $data['end_price'];
        isset($data['highest']) && $timeline->highest = $data['highest'];
        isset($data['mminimum']) && $timeline->mminimum = $data['mminimum'];
        isset($data['number']) && $timeline->number = $data['number'];
        isset($data['period']) || $timeline->period = self::$period[$type];
        $result = $timeline->save();
        return $result;
    }

    /**
     * 取指定类型时间线实例,如果没有自动创建
     *
     * @param integer $type 类型:[1.15分钟,2.1小时,3.4小时,4.一天,5.分时,6.5分钟，7.30分钟,8.一周,9.一月]
     * @param integer $currency_id 币种id
     * @param integer $legal_id 法币id
     * @param integer $sign 来源标记[0.默认,1.交易更新,2.接口,3.后台添加
     * @param integer $day_time 时间戳
     * @return void
     */
    public static function getTimelineInstance($type, $currency_id, $legal_id, $sign = 0, $day_time = null)
    {
        empty($day_time) && $day_time = time();
        $time = self::formatTimeline($type, $day_time);
        $timeline = self::where('type', $type)
            ->where('day_time', $time)
            ->where('currency_id', $currency_id)
            ->where('legal_id', $legal_id)
            ->first();
        if (!$timeline) {
            $timeline = self::makeTimelineData($type, $currency_id, $legal_id, $sign, $day_time);
        } else {
            $timeline->sign = $sign;
        }
        return $timeline;
    }

    /**
     * 生成一条时间线数据
     *
     * @param integer $type 类型:1.15分钟,2.1小时,3.4小时,4.一天,5.分时,6.5分钟，7.30分钟,8.一周,9.一月
     * @param integer $currency_id 币种id
     * @param integer $legal_id 法币id
     * @param integer $sign 来源标记[0.默认,1.交易更新,2.接口,3.后台添加
     * @param integer $day_time 时间戳
     * @return App\MarketHour 返回一个行情模型实例
     */
    private static function makeTimelineData($type, $currency_id, $legal_id, $sign = 0, $day_time = null)
    {
        empty($day_time) && $day_time = time();
        $time = self::formatTimeline($type, $day_time);
        $timeline = new self();
        $timeline->type = $type;
        $timeline->day_time = $time;
        $timeline->currency_id = $currency_id;
        $timeline->legal_id = $legal_id;
        $timeline->start_price = 0;
        $timeline->end_price = 0;
        $timeline->highest = 0;
        $timeline->mminimum = 0;
        $timeline->number = 0;
        $timeline->sign = $sign;
        $timeline->period = self::$period[$type];
        $result = $timeline->save();
        return $timeline;
    }

    /**
     * 按类型格式化时间线
     *
     * @param integer $type 类型:1.15分钟,2.1小时,3.一年,4.一天,5.分时,6.5分钟，7.30分钟,8.一周,9.一月,10.4小时
     * @param integer $day_time 时间戳,不传将默认采用当前时间
     * @return void
     */
    private static function formatTimeline($type, $day_time = null)
    {
        empty($day_time) && $day_time = time();
        switch ($type) {
            //15分钟
            case 1:
                $start_time = strtotime(date('Y-m-d H:00:00', $day_time));
                $minute = intval(date('i', $day_time));
                $multiple = floor($minute / 15);
                $minute = $multiple * 15;
                $time = $start_time + $minute * 60;
                break;
            //1小时
            case 2:
                $time = strtotime(date('Y-m-d H:00:00', $day_time));
                break;
            //4小时
            case 3:
                $start_time = strtotime(date('Y-m-d', $day_time));
                $hours = intval(date('H', $day_time));
                $multiple = floor($hours / 4);
                $hours = $multiple * 4;
                $time = $start_time + $hours * 3600;
                break;
            //一天
            case 4:
                $time = strtotime(date('Y-m-d', $day_time));
                break;
            //分时
            case 5:
                $time_string = date('Y-m-d H:i', $day_time);
                $time = strtotime($time_string);
                break;
            //5分钟
            case 6:
                $start_time = strtotime(date('Y-m-d H:00:00', $day_time));
                $minute = intval(date('i', $day_time));
                $multiple = floor($minute / 5);
                $minute = $multiple * 5;
                $time = $start_time + $minute * 60;
                break;
            //30分钟
            case 7:
                $start_time = strtotime(date('Y-m-d H:00:00', $day_time));
                $minute = intval(date('i', $day_time));
                $multiple = floor($minute / 30);
                $minute = $multiple * 30;
                $time = $start_time + $minute * 60;
                break;
            //一周
            case 8:
                $start_time = strtotime(date('Y-m-d', $day_time));
                $week = intval(date('w', $day_time));
                $diff_day = $week;
                $time = $start_time - $diff_day * 86400;
                break;
            //一月
            case 9:
                $time_string = date('Y-m', $day_time);
                $time = strtotime($time_string);
                break;
            //一年
            case 10:
                $time = strtotime(date('Y-01-01', $day_time));
                break;
            default:
                $time = $day_time;
                break;
        }
        return $time;
    }

    public static function getHuobiLeverMarket()
    {
        $currency_match = CurrencyMatch::where('market_from', 2)
            ->where('open_lever', 1)
            ->get();
        if (count($currency_match) <= 0) {
            return false;
        }
        return $currency_match;
    }


    public static function setEsearchMarket($market_data)
    {
        $es_client = self::getEsearchClient();
        $type = $market_data['base-currency'] . '.' . $market_data['quote-currency'] . '.' . $market_data['period'];
        // $market_data['close']>6600&&var_dump($market_data);
        $params = [
            'index' => 'market.kline',
            'type' => 'doc',
            'id' => $type . '.' . $market_data['id'],
            'body' => $market_data,
        ];
        $response = $es_client->index($params);
        return $response;
    }

    public static function getAndSetEsearchMarket($market_data)
    {
        $result = self::getEsearchMarketById($market_data['base-currency'], $market_data['quote-currency'], $market_data['period'], $market_data['id']);
        if (isset($result['_source'])) {
            $data = $result['_source'];
            $price = $market_data['close'];
            /*
            //当前价格和原最高价最低价对比
            bc_comp($data['high'], $price) < 0 && $market_data['high'] = $price; //更新最高价
            bc_comp($data['low'], $price) > 0 && $market_data['low'] = $price; //更新最低价
            */
            bc_comp($market_data['high'], $data['high']) < 0 && $market_data['high'] = $data['high']; //新过来的价格如果不高于原最高价则不更新
            bc_comp($market_data['low'], $data['low']) > 0 && $market_data['low'] = $data['low']; //新过来的价格如果不低于原最低价则不更新
        }
        if($market_data['close'] > 6600)
        echo 'get and set '.json_encode($market_data);
        $response = self::setEsearchMarket($market_data);
        return $response; 
    }

    public static function getEsearchMarketById($base_currency, $quote_currency, $peroid, $id)
    {
        try {
            $es_client = self::getEsearchClient();
            $type = $base_currency . '.' . $quote_currency . '.' . $peroid;
            $params = [
                'index' => 'market.kline',
                'type' => 'doc',
                'id' => $type . '.' . $id,
            ];
            $result = $es_client->get($params);
        } catch (\Throwable $th) {
            $result = [];
        }
        return $result;
    }

    /**
     * 从ElasticSearch取行情
     *
     * @param string $base_currency 基础币种，即交易币
     * @param string $quote_currency 计价币种，即法币
     * @param string $peroid 行情时间分辨率
     * @param integer $from 开始时间戳
     * @param integer $to 结束时间戳
     * @return array
     */
    /**
     * 从ElasticSearch取行情
     *
     * @param string $base_currency 基础币种，即交易币
     * @param string $quote_currency 计价币种，即法币
     * @param string $peroid 行情时间分辨率
     * @param integer $from 开始时间戳
     * @param integer $to 结束时间戳
     * @return array
     */
    public static function getEsearchMarket($base_currency, $quote_currency, $peroid, $from, $to)
    {
        $size = 0;
        $base_currencys = $base_currency;
        $base_currency = strtoupper($base_currency);
        $currency_model = Currency::where(['name' => $base_currency])->first();
        $match = CurrencyMatch::where(['currency_id' => $currency_model->id, 'legal_id' => 3])->first();
        $quote_currencys = $quote_currency;
        $quote_currency = strtoupper($quote_currency);
        $interval_list = [
            "1min" => 60,
            "5min" => 300,
            "15min" => 900,
            "30min" => 1800,
            "60min" => 3600,
            "1hour" => 3600,
            "1day" => 86400,
            "1week" => 604808,
            "1mon" => 2592000,
            "1year" => 31536000,
        ];
        $interval = $interval_list[$peroid];
        $size = intval(($to - $from) / $interval) + 100;
        $size > 10000 && $size = 10000;
        $type = $base_currency . '.' . $quote_currency . '.' . $peroid;


        if ($match->market_from == 2) {
            if ($match->market_from == 1) {
                $base_currencys = $currency_model->type;
            }
            $res = file_get_contents("https://api.huobi.pro/market/history/kline?symbol=" . strtolower($base_currencys . $quote_currencys) . "&period={$peroid}&size=500");
//        dd($res);
            $res = json_decode($res, true);
            $data_arr = [];
            if (!isset($res['data'])) {
                return [];
            }
            foreach ($res['data'] as $key => $item) {
                $data_arr[] = [
                    "id" => $item['id'],
                    "period" => $peroid,
                    "base-currency" => strtolower($currency_model->currency_name),
                    "quote-currency" => $quote_currency,
                    "open" => $item['open'],
                    "close" => $item['close'],
                    "high" => $item['high'],
                    "low" => $item['low'],
                    "vol" => $item['vol'],
                    "amount" => $item['amount']
                ];
            }


            $res = array_reverse($data_arr);

        } elseif($match->market_from == 1){
             if ($peroid === '1min') {
                $list = MyQuotation::whereBetween('itime', [$from, $to])->where("base",$base_currency)->get()->toArray();
                $res = [];
                foreach ($list as $item) {
                    $res[] = [
                        "id" => strtotime($item['itime']),
                        "period" => $peroid,
                        "base-currency" => $base_currency,
                        "quote-currency" => $quote_currency,
                        "open" => $item['open'],
                        "close" => $item['close'],
                        "high" => $item['high'],
                        "low" => $item['low'],
                        "vol" => $item['vol'],
                        "amount" => $item['vol'],
                        "time" => strtotime($item['itime']) * 1000
                    ];
                }
                $adt2=getConfig("../config/daymarket_".strtolower($base_currency).".php");
                
                $res[] = [
                        "id" => $adt2['libra_data']['time'],
                        "period" => $adt2['libra_data']['period'],
                        "base-currency" => $adt2['libra_data']['currency_name'],
                        "quote-currency" => $adt2['libra_data']['legal_name'],
                        "open" => $adt2['libra_data']['open'],
                        "close" => $adt2['libra_data']['close'],
                        "high" => $adt2['libra_data']['high'],
                        "low" => $adt2['libra_data']['low'],
                        "vol" => $adt2['libra_data']['volume'],
                        "amount" => $adt2['libra_data']['volume'],
                        "time" => strtotime($adt2['libra_data']['time'])
                    ];;
                unset($res[count($res)]);
            } elseif (in_array($peroid, ['5min', '15min', '30min', '60min', '1day', '1week', '1mon'])) {
                $base_s = 300;
                if ($peroid === '5min') {
                    $base_s = 300;
                }
                if ($peroid === '15min') {
                    $base_s = 900;
                }
                if ($peroid === '30min') {
                    $base_s = 1800;
                }
                if ($peroid === '60min') {
                    $base_s = 3600;
                }
                if ($peroid === '1day') {
                    $base_s = 86400;
                }

                if ($peroid === '1week') {
                    $base_s = 86400 * 7;
                }
                if ($peroid === '1mon') {
                    $base_s = 86400 * 30;
                }
                $diff = 0;
                $nowtime=time();
                if($peroid=='5min' || $peroid=='15min' || $peroid=='30min' || $peroid=='60min'){
                     $where=[];
                     if($peroid=='5min'){
                         $where["period5"]=1;
                         
                     
                         //$where='period="1min,5min" or period="1min,5min,15min"';
                     }
                     if($peroid=='15min'){
                        $where["period15"]=1;
                     }
                     if($peroid=='30min'){
                        $where["period30"]=1;
                     }
                     if($peroid=='60min'){
                         $where["period60"]=1;
                     }
                     $list = MyQuotation::whereBetween('itime', [$from + $diff, $to + $diff])->where($where)->where("base",$base_currency)->get()->toArray();
                        // echo json_encode($list);
                     //$list = MyQuotation::whereBetween('itime', [$from + $diff, $to + $diff])->where("base",$base_currency)->where("period","like","%,".$peroid."%")->get()->toArray();
                }else{
                    $list = MyQuotation::whereBetween('itime', [$from + $diff, $to + $diff])->whereRaw('itime % ' . $base_s . '=0')->where("base",$base_currency)->get()->toArray();
                }
                
                $res = [];
                $index = 0;
               foreach ($list as $item) {
                    //echo $item["itime"].PHP_EOL;// 1617811200 是2021年4月8日0时
                    if(strtotime($item["itime"])>1617811200){
                        
                    }
                          //var_dump($item);
                        $high = MyQuotation::whereBetween('itime', [strtotime($item['itime']) - 1, strtotime($item['itime'])  + ($base_s - 59)])->where("base",$base_currency)->max('high');
                        $low = MyQuotation::whereBetween('itime', [strtotime($item['itime'])  - 1, strtotime($item['itime'])  + ($base_s - 59)])->where("base",$base_currency)->min('low');
                        $vol = MyQuotation::whereBetween('itime', [strtotime($item['itime'])  - 1, strtotime($item['itime'])  + ($base_s - 59)])->where("base",$base_currency)->sum('vol');
                       
                        //in_array($peroid, ['5min', '15min', '30min', '60min', '1day', '1week', '1mon'])
                        //print_r($item["itime"]);$peroid=="1day"
                        if(in_array($peroid, ['1day', '1week', '1mon'])){
                            $date=date("Y-m-d",strtotime($item['itime']));
                            $start=MyQuotation::where('itime', strtotime($date." 00:00:00"))->where("base",$base_currency)->first(['open']);
                            $last = MyQuotation::where('itime', strtotime($date." 23:59:00"))->where("base",$base_currency)->first(['close']);
                        }else{
                            $last = MyQuotation::where('itime', strtotime($item['itime']) + ($base_s - 60))->where("base",$base_currency)->first(['close']);
                            $start = "";
                        }
                           // echo  $item['itime'];
                           //print_r($item['close']."比".$last ->close.PHP_EOL);
                            $close=$last?$last->close:$item['close'];
                            $newopen=$start?$start->open:$item['open'];
                            
                            $res[] = [
                                "id" => strtotime($item['itime']) - $diff,
                                "period" => $peroid,
                                "base-currency" => $base_currency,
                                "quote-currency" => $quote_currency,
                                "open" => $newopen,
                                "close" => $close,
                                "high" => $high,
                                "low" => $low,
                                "vol" => $vol,
                                "amount" => $vol,
                                "time" => (strtotime($item['itime']) - $diff) * 1000
                            ];
                            
                            /*$res[] = [
                                "id" => strtotime($item['itime']) - $diff,
                                "period" => $item['itime'],
                                "base-currency" => $base_currency,
                                "quote-currency" => $quote_currency,
                                "open" => $item['open'],
                                "close" => $item['close'],
                                "high" => $item['high'],
                                "low" => $item['low'],
                                "vol" =>$item['vol'],
                                "amount" =>$item['vol'],
                                "time" => (strtotime($item['itime']) - $diff) * 1000
                            ];*/
                            $index++;
                    
//                  
                }
            } 
            
            return $res;
        }else{
             $res = [];
           
            if ($match->market_from == 3) {
                $token='edf1a10bca0f48b4961489f1adc47bfd';
                // $base_currency='USDCNY';
                 $quotecurrency='CNY';
                $base_currencys=$base_currency."CNY";
                $basecurrency='USD';
               
                // echo $peroid;
                // exit;
                if($peroid=="1day"){
                      $peroid="daily";
                      $res = file_get_contents("https://tsanghi.com/api/fin/forex/".$peroid."/realtime?token=".$token."&ticker=" . $base_currencys . "&limit=500");
                }else if($peroid=="1week"){
                      $peroid="weekly";
                      $res = file_get_contents("https://tsanghi.com/api/fin/forex/".$peroid."/realtime?token=".$token."&ticker=" . $base_currencys . "&limit=500");
                }else if($peroid=="1mon"){
                      $peroid="monthly";
                      $res = file_get_contents("https://tsanghi.com/api/fin/forex/".$peroid."/realtime?token=".$token."&ticker=" . $base_currencys . "&limit=500");
                } else{
                      $res = file_get_contents("https://tsanghi.com/api/fin/forex/".$peroid."/realtime?token=".$token."&ticker=" . $base_currencys . "&limit=500");
                }
                
               
              
     //   dd($res);
                    $res = json_decode($res, true);
                    $data_arr = [];
                    if (!isset($res['data'])) {
                        return [];
                    }
                    foreach ($res['data'] as $key => $item) {
                        $data_arr[] = [
                            "id" => strtotime( $item['date']),
                            "period" => $item['date'],
                            "base-currency" => strtolower($base_currency),
                            "quote-currency" =>$quotecurrency,
                            "open" => $item['open'],
                            "close" => $item['close'],
                            "high" => $item['high'],
                            "low" => $item['low'],
                            "vol" => 3333,
                            "amount" => $item['open']
                        ];
                    }
        
        //   dd($res);
                    $res = array_reverse($data_arr);
            }
           

        
          
        }
        
        
//         $symbol = strtoupper($base_currency . '/' . $quote_currency);
//         $needles = Needle::where('symbol', $symbol)->get()->toArray();
//         if (count($needles) > 0) {
//             array_walk($res, function (&$v) use ($needles) {

//                 foreach ($needles as $needle) {
//                     $needle['itime'] = strtotime($needle['itime']);

//                     $curren = intval($v['id']);

//                     if ($v['period'] === '1min') {
//                         //一分钟
//                         $next = strtotime('+1 minutes', $curren);
//                     } else if ($v['period'] === '5min') {
//                         $next = strtotime('+5 minutes', $curren);
//                     } else if ($v['period'] === '15min') {
//                         $next = strtotime('+15 minutes', $curren);
//                     } else if ($v['period'] === '60min') {
//                         $next = strtotime('+60 minutes', $curren);
//                     } else if ($v['period'] === '1day') {
//                         $next = strtotime('+1 day', $curren);
//                     } else if ($v['period'] === '1week') {
//                         $next = strtotime('+7 days', $curren);
//                     } else if ($v['period'] === '1mon') {
//                         $next = strtotime('+1 month', $curren);
//                     }
// //            var_dump("当前：{$curren},下一个时间戳:{$next}");
//                     if ($needle['itime'] >= $curren && $needle['itime'] < $next) {
//                         if (($v['period'] !== '1min' && $needle['itime'] < time()) || $v['period'] === '1min') {
//                             if ($v['period'] === '1min') {
//                                 $v['open'] = $needle['open'];
//                                 $v['high'] = $needle['high'];
//                                 $v['close'] = $needle['close'];
//                                 $v['low'] = $needle['low'];
//                             } else {
//                                 $v['open'] = $v['open'] > $needle['open'] ? $v['open'] : $needle['open'];
//                                 $v['high'] = $v['high'] > $needle['high'] ? $v['high'] : $needle['high'];
//                                 $v['low'] = $v['high'] < $needle['low'] ? $v['low'] : $needle['low'];
//                                 $v['close'] = $v['close'] < $needle['close'] ? $v['close'] : $needle['close'];
//                             }
//                         }
//                     }
//                 }

//             });
//         }
//die;
        return $res;


    }

    public static function batchEsearchMarket($base_currency, $quote_currency, $price, $time)
    {
    	
        $currency = Currency::where('name', $base_currency)->first();
        $legal = Currency::where('name', $quote_currency)->first();
        $currency_match = CurrencyMatch::where('currency_id', $currency->id)
            ->where('legal_id', $legal->id)
            ->first();
        $types = [
            '1min'=> 5,
            '5min'=> 6,
            '15min'=> 1,
            '30min'=> 7,
            '60min'=> 2,
            '1day'=> 4,
            '1mon'=> 9,
            '1week'=> 8,
            '1year'=> 10,
        ];
        $periods = ['1min', '5min', '15min', '30min', '60min', '1day', '1mon', '1week', '1year'];
        foreach ($periods as $key => $period) {
            $type = $types[$period];
            $convert_time = self::formatTimeline($type, $time);
            $result = self::getEsearchMarketById($base_currency, $quote_currency, $period, $convert_time);
            if (isset($result['_source'])) {
                $data = $result['_source'];
                $data['close'] = $price; //更新下最新价格
                bc_comp($data['high'], $price) < 0 && $data['high'] = $price; //更新最高价
                bc_comp($data['low'], $price) > 0 && $data['low'] = $price; //更新最低价
              
                // unset($data['vol'], $data['amount']); //不影响成交数量
                
                self::setEsearchMarket($data);
            } else {
                //dd($result);
                //拿不到数据,可能是还没有也有可能是程序错误,为了保险建议不处理
                $data = [
                    'id' => $convert_time,
                    'period' => $period,
                    'base-currency' => $base_currency,
                    'quote-currency' => $quote_currency,
                    'open' => $price,
                    'close' => $price,
                    'high' => $price,
                    'low' => $price,
                    'vol' => 0,
                    'amount' => 0,
                ];
                //self::setEsearchMarket($data);
            }
            //dump($data);
            if ($period == '1min') {
                $kline_data = [
                    'type' => 'kline',
                    'period' => '1min',
                    'match_id' => $currency_match->id,
                    'currency_id' => $currency->id,
                    'currency_name' => $base_currency,
                    'legal_id' => $legal->id,
                    'legal_name' => $quote_currency,
                    'open' => $data['open'],
                    'close' => $data['close'],
                    'high' => $data['high'],
                    'low' => $data['low'],
                    'symbol' => $currency_match->currency_name . '/' . $currency_match->legal_name,
                    'volume' => $data['amount'] ?? 0,
                    'time' => $convert_time * 1000,
                    'market_form' => 'risk',
                ];
               
            }
            //  var_dump(['data' => $kline_data,'d' => '123']);
            SendMarket::dispatch($kline_data)->onQueue('kline.1min');
            // self::setEsearchMarket($data);
        }
    }

    public static function getLastEsearchMarket($base_currency, $quote_currency, $peroid = '1min')
    {
        $es_client = self::getEsearchClient();
        $params = [
            'index' => 'market.kline',
            'type' =>  'doc',
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['match' => ['period' => $peroid]],
                            ['match' => ['base-currency' => $base_currency]],
                            ['match' => ['quote-currency' => $quote_currency]],  
                        ],
                    ],
                ],
                'sort' => [
                    'id' => [
                        'order' => 'desc',
                    ],
                ],
                'size' => 1,
            ],
        ];
        $result = $es_client->search($params);
        if (isset($result['hits'])) {
            $data = array_column($result['hits']['hits'], '_source');
            $data = reset($data);
        } else {
            $data = [];
        }
        return $data;
    }
}
