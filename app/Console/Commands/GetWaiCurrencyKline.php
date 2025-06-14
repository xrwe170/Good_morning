<?php

namespace App\Console\Commands;

use App\Currency;
use App\UserChat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
defined('ACCOUNT_ID') || define('ACCOUNT_ID', '50154012');
defined('ACCESS_KEY') || define('ACCESS_KEY', 'c96392eb-b7c57373-f646c2ef-25a14');
defined('SECRET_KEY') || define('SECRET_KEY', '');
class GetWaiCurrencyKline extends Command
{
	protected $signature = "get_waikui_kline_data";
	protected $description = "获取外汇K线图数据";
	private $url = "https://tsanghi.com";
	private $api = "";
	public $api_method = "";
	public $req_method = "";
	public $token='edf1a10bca0f48b4961489f1adc47bfd';
	
	public function __construct()
	{
		parent::__construct();
	}
	public function handle()
	{
		while (true) {
			try {
				echo "开始推送\r\n";
				$all = DB::table('currency')->where('is_display', '1')->get();
				$all_arr = $this->object2array($all);
				// echo json_encode($all_arr);
				// $legal = DB::table('currency')->where('id',3)->where('is_display', '1')->where('is_legal', '1')->get();
				// $legal_arr = $this->object2array($legal);
				$ar = [];
				// foreach ($legal_arr as $legal) {
					foreach ($all_arr as $item) {
				// 		if ($legal['id'] != $item['id']) {
							echo "begin2";
							$ar_a = [];
							$ar_a['name'] = strtolower($item['name']) . strtolower("CNY");
							$ar_a['currency_id'] = $item['id'];
							$ar_a['legal_id'] = 3;
							$ar[] = $ar_a;
				// 		}
					}
				// }
				echo "开始遍历币种\r\n";
				// file_put_contents("ar_new1.txt",'ar:'. json_encode($ar) . PHP_EOL, FILE_APPEND);
				$array=[];
				$ar_new=[];
				
				
				foreach ($ar as  &$vv) {
					if (in_array($vv["name"], array("usdcny", "hkdcny", "jpycny", "audcny", "eurcny","thbcny",  "plncny", "chfcny" , "twdcny" ,"sgdcny"))) {
						
						if(!in_array($vv["name"], $array)){
						    $ar_new[] = $vv;
					    	$array[]=$vv["name"];
						}
				
					}
				}
				file_put_contents("ar_new1.txt", json_encode($ar_new) . PHP_EOL, FILE_APPEND);
				foreach ($ar_new as  &$it) {
					echo "遍历币种开始".$it['name']."\r\n";
					$data = array();
					echo "开始请求\r\n";
					$data = $this->get_history_kline($it['name'], '1min', 1);
					if ($data) {
					} else {
					   // file_put_contents("test1.txt",  $it['name']. PHP_EOL, FILE_APPEND);
						echo "重新采集\r\n";
						continue 2;
					}
					echo "请求结束\r\n";
					if ($data['code'] != '200') {
						echo "begin6";
						$this->error('请求失败');
						continue 2;
					}
					$info = $data['data'][0];
					$insert_instance = DB::table('market_hour')->where('currency_id', $it['currency_id'])->where('legal_id', $it['legal_id'])->where('day_time', '=', strtotime($info['date']))->where('type', 5)->where('period', '1min')->where('sign', 2)->first();
					if ($insert_instance) {
						echo "begin7";
						$update_Data = [];
					
					if($info['close']>$info['pre_close']){
				    	$a = bcsub($info['close'], $info['pre_close'], 5);
						$_pencet_num = bcdiv($a, $info['pre_close'], 5);
						$update_Data['change'] = '+' . bcmul($_pencet_num, 100, 3);
				   
    				}else{
    				    	$a = bcsub($info['pre_close'], $info['close'], 5);
    						$_pencet_num = bcdiv($a, $info['pre_close'], 5);
    						$update_Data['change'] = '-' . bcmul($_pencet_num, 100, 3);
    				}
					$update_Data['now_price'] = $this->sctonum($info['close']);
					$update_Data['add_time'] = time();
					
					$que_data = DB::table('currency_quotation')->where('currency_id', $it['currency_id'])->where('legal_id', $it['legal_id'])->orderby('id', 'DESC')->first();
					if (!empty($que_data)) {
						DB::table('currency_quotation')->where('id', $que_data->id)->update($update_Data);
					} 
					
					  $insert_Data =array();
				      	$insert_Data['currency_id'] = $insert_instance->currency_id;
    					$insert_Data['legal_id'] = $insert_instance->legal_id;
    					$insert_Data['start_price'] =  $insert_instance->start_price;
    					$insert_Data['end_price'] = $insert_instance->end_price;
    					$insert_Data['mminimum'] =  $insert_instance->mminimum;
    					$insert_Data['highest'] = $insert_instance->highest;
    					$insert_Data['type'] = 5;
    					$insert_Data['sign'] = 2;
    					$insert_Data['day_time'] =  $insert_instance->day_time;
    					$insert_Data['period'] = '1min';
    					$insert_Data['number'] =  $insert_instance->number;
    					$insert_Data['mar_id'] =  $insert_instance->mar_id;
						$currency = Currency::find($it['currency_id']);
					$legal = Currency::find($it['legal_id']);
					$update_Data['currency_name'] = $currency->name;
					$update_Data['legal_name'] = $legal->name;
					$update_Data['type'] = 'daymarket';
					$update_Data['high'] = $insert_Data['highest'];
					$update_Data['low'] = $this->sctonum($info['low']);
					$update_Data['symbol'] = $currency->name . '/' . $legal->name;
					echo "begin8";
					$new_data = ['type' => 'kline', 'period' => $insert_Data['period'], 'currency_id' => $insert_Data['currency_id'], 'currency_name' => $currency->name, 'legal_id' => $insert_Data['legal_id'], 'legal_name' => $legal->name, 'symbol' => $currency->name . '/' . $legal->name, 'open' => $insert_Data['start_price'], 'close' => $insert_Data['end_price'], 'high' => $insert_Data['highest'], 'low' => $insert_Data['mminimum'], 'volume' => $insert_Data['number'], 'time' => $insert_Data['day_time'] * 1000];
					echo "开始推送\r\n";
					print_r($update_Data);
					UserChat::sendChat($update_Data);
					UserChat::sendChat($new_data);
					unset($currency);
					unset($legal);
					
					
				    //   $insert_Data =array();
				    //   	$insert_Data['currency_id'] = $insert_instance->currency_id;
    				// 	$insert_Data['legal_id'] = $insert_instance->legal_id;
    				// 	$insert_Data['start_price'] =  $insert_instance->start_price;
    				// 	$insert_Data['end_price'] = $insert_instance->end_price;
    				// 	$insert_Data['mminimum'] =  $insert_instance->mminimum;
    				// 	$insert_Data['highest'] = $insert_instance->highest;
    				// 	$insert_Data['type'] = 5;
    				// 	$insert_Data['sign'] = 2;
    				// 	$insert_Data['day_time'] =  $insert_instance->day_time;
    				// 	$insert_Data['period'] = '1min';
    				// 	$insert_Data['number'] =  $insert_instance->number;
    				// 	$insert_Data['mar_id'] =  $insert_instance->mar_id;
					}else{
					    $insert_Data = array();
    					$insert_Data['currency_id'] = $it['currency_id'];
    					$insert_Data['legal_id'] = $it['legal_id'];
    					$insert_Data['start_price'] = $this->sctonum($info['open']);
    					$insert_Data['end_price'] = $this->sctonum($info['close']);
    					$insert_Data['mminimum'] = $this->sctonum($info['low']);
    					$insert_Data['highest'] = $this->sctonum($info['high']);
    					$insert_Data['type'] = 5;
    					$insert_Data['sign'] = 2;
    					$insert_Data['day_time'] = strtotime($info['date']);
    					$insert_Data['period'] = '1min';
    					$insert_Data['number'] = bcmul($info['open'], 1, 5);
    					$insert_Data['mar_id'] = strtotime($info['date']);
    					DB::table('market_hour')->insert($insert_Data);
    					
    						echo "3232";
					
					$update_Data = [];
					$update_Data['currency_id'] = $it['currency_id'];
					$update_Data['legal_id'] = $it['legal_id'];
					$update_Data['now_price'] = $this->sctonum($info['close']);
					$update_Data['add_time'] = time();
					$update_Data['volume'] = '0.00000';
					$update_Data['change'] = '+0.00';
					$time = strtotime(date("Y-m-d"));
					$day_Data = DB::table('market_hour')->where('currency_id', $it['currency_id'])->where('legal_id', $it['legal_id'])->where('period', '1day')->where('sign', 2)->where('day_time', '<=', $time)->where('end_price', '>', '0.00000')->orderby('id', 'DESC')->first();
					if (!empty($day_Data)) {
						$_zero_price = $day_Data->end_price;
					} else {
						$_zero_price = 0;
					}
					
					$update_Data['volume'] = DB::table('market_hour')->where('day_time', '>', $time)->where('currency_id', $it['currency_id'])->where('legal_id', $it['legal_id'])->where('period', '1min')->where('sign', 2)->sum('number');
					
					
				// 	switch (bccomp($update_Data['now_price'], $_zero_price, 5)) {
				// 		case 1:
				// 			if ($_zero_price === 0) {
							    
				// 				$update_Data['change'] = '+0.000';
				// 			} else {
							    
				// 				$a = bcsub($update_Data['now_price'], $_zero_price, 5);
							
				// 			file_put_contents("ar_new1.txt", $update_Data['now_price']."比较".$_zero_price . PHP_EOL, FILE_APPEND);
							
				// 				$_pencet_num = bcdiv($a, $_zero_price, 5);
				// 				$update_Data['change'] = '+' . bcmul($_pencet_num, 100, 3);
				// 			}
							
				// 			break ;
				// 		case 0:
				// 			$update_Data['change'] = '+0.000';
				// 			break ;
				// 		case -1:
				// 			if ($_zero_price === 0) {
				// 				$update_Data['change'] = '+0.000';
				// 			} else {
				// 				$a = bcsub($_zero_price, $update_Data['now_price'], 5);
				// 				$_pencet_num = bcdiv($a, $_zero_price, 5);
				// 				$update_Data['change'] = '-' . bcmul($_pencet_num, 100, 3);
				// 			}
				// 			break ;
				// 		default:
				// 			$update_Data['change'] = '+0.000';
				// 	}
				if($info['close']>$info['pre_close']){
				    	$a = bcsub($info['close'], $info['pre_close'], 5);
						$_pencet_num = bcdiv($a, $info['pre_close'], 5);
						$update_Data['change'] = '+' . bcmul($_pencet_num, 100, 3);
				   
				}else{
				    	$a = bcsub($info['pre_close'], $info['close'], 5);
						$_pencet_num = bcdiv($a, $info['pre_close'], 5);
						$update_Data['change'] = '-' . bcmul($_pencet_num, 100, 3);
				}
					
					
					$que_data = DB::table('currency_quotation')->where('currency_id', $it['currency_id'])->where('legal_id', $it['legal_id'])->orderby('id', 'DESC')->first();
					if (!empty($que_data)) {
						DB::table('currency_quotation')->where('id', $que_data->id)->update($update_Data);
					} else {
						DB::table('currency_quotation')->insert($update_Data);
					}
					$currency = Currency::find($it['currency_id']);
					$legal = Currency::find($it['legal_id']);
					$update_Data['currency_name'] = $currency->name;
					$update_Data['legal_name'] = $legal->name;
					$update_Data['type'] = 'daymarket';
					$update_Data['high'] = $insert_Data['highest'];
					$update_Data['low'] = $this->sctonum($info['low']);
					$update_Data['symbol'] = $currency->name . '/' . $legal->name;
					echo "begin8";
					$new_data = ['type' => 'kline', 'period' => $insert_Data['period'], 'currency_id' => $insert_Data['currency_id'], 'currency_name' => $currency->name, 'legal_id' => $insert_Data['legal_id'], 'legal_name' => $legal->name, 'symbol' => $currency->name . '/' . $legal->name, 'open' => $insert_Data['start_price'], 'close' => $insert_Data['end_price'], 'high' => $insert_Data['highest'], 'low' => $insert_Data['mminimum'], 'volume' => $insert_Data['number'], 'time' => $insert_Data['day_time'] * 1000];
					echo "开始推送\r\n";
					print_r($update_Data);
					UserChat::sendChat($update_Data);
					UserChat::sendChat($new_data);
					unset($currency);
					unset($legal);
					echo "遍历币种结束\r\n";
					}
					
				
				}
				sleep(5);
			} catch (Exception $e) {
			}
		}
	}
	public function object2array($obj)
	{
		return json_decode(json_encode($obj), true);
	}
	public function sctonum($num, $double = 8)
	{
		if (false !== stripos($num, "e")) {
			$a = explode("e", strtolower($num));
			return bcmul($a[0], bcpow(10, $a[1], $double), $double);
		} else {
			return $num;
		}
	}
	public function get_history_kline($symbol = '', $period = '', $size = 0)
	{
		echo "获取K线数据\r\n";
// 		$this->api_method = "/api/fin/forex/".$period."/realtime?token=".$this->token."&ticker=".strtoupper($symbol);
			$this->api_method = "/api/fin/forex/realtime?token=".$this->token."&ticker=".strtoupper($symbol)."&columns=date,close,pre_close,ticker,open,high,low";
		$this->req_method = 'GET';
// 		$param = ['start_date' => $symbol, 'end_date' => $period, 'limit' => $size];
// 		if ($size) {
// 			$param['size'] = $size;
// 		}
// 		https://tsanghi.com/api/fin/forex/1min/realtime?token=edf1a10bca0f48b4961489f1adc47bfd&ticker={ticker}
		$url =$this->url . $this->api_method  ;
		file_put_contents("log_getwai.txt", $url . PHP_EOL, FILE_APPEND);
		echo "获取K线数据结束\r\n";
		return json_decode($this->curl($url), true);
	}
	public function create_sign_url($append_param = [])
	{
		$param = ['AccessKeyId' => ACCESS_KEY, 'SignatureMethod' => 'HmacSHA256', 'SignatureVersion' => 2, 'Timestamp' => date('Y-m-d\\TH:i:s', time())];
		if ($append_param) {
			foreach ($append_param as $k => $ap) {
				$param[$k] = $ap;
			}
		}
		return $this->url . $this->api_method . '?' . $this->bind_param($param);
	}
	public function bind_param($param)
	{
		$u = [];
		$sort_rank = [];
		foreach ($param as $k => $v) {
			$u[] = $k . "=" . urlencode($v);
			$sort_rank[] = ord($k);
		}
		asort($u);
		$u[] = "Signature=" . urlencode($this->create_sig($u));
		return implode('&', $u);
	}
	public function create_sig($param)
	{
		$sign_param_1 = $this->req_method . "\r\n" . $this->api . "\r\n" . $this->api_method . "\r\n" . implode('&', $param);
		$signature = hash_hmac('sha256', $sign_param_1, SECRET_KEY, true);
		return base64_encode($signature);
	}
	public function curl($url, $postdata = [])
	{
		echo "curl开始\r\n";
		$start = microtime(true);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		if ($this->req_method == 'POST') {
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata));
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 4);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
		$output = curl_exec($ch);
		$info = curl_getinfo($ch);
		curl_close($ch);
		if (empty($output)) {
			echo "curl没有采集到\r\n";
		}
		echo "curl结束\r\n";
		$end = microtime(true);
		file_put_contents("haoshi.txt", $end - $start . PHP_EOL, FILE_APPEND);
		return $output;
	}
}