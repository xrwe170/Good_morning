<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
defined('ACCOUNT_ID') || define('ACCOUNT_ID', '50154012');
defined('ACCESS_KEY') || define('ACCESS_KEY', 'c96392eb-b7c57373-f646c2ef-25a14');
defined('SECRET_KEY') || define('SECRET_KEY', '');
class GetWaiCurrencyKline_ThirtyMin extends Command
{
	protected $signature = "get_waikui_kline_data_thirtymin";
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
			echo "开始推送30min\r\n";
		$all = DB::table('currency')->where('is_display', '1')->get();
		$all_arr = $this->object2array($all);
		$legal = DB::table('currency')->where('id',3)->where('is_display', '1')->where('is_legal', '1')->get();
		$legal_arr = $this->object2array($legal);
		$ar = [];
		foreach ($legal_arr as $legal) {
			foreach ($all_arr as $item) {
				if ($legal['id'] != $item['id']) {
					echo "begin2";
					$ar_a = [];
					$ar_a['name'] = strtolower($item['name']) . strtolower("CNY");
					$ar_a['currency_id'] = $item['id'];
					$ar_a['legal_id'] = $legal['id'];
					$ar[] = $ar_a;
				}
			}
		}
		echo "开始遍历币种30min\r\n";
		file_put_contents("ar_new1.txt",'ar:'. json_encode($ar) . PHP_EOL, FILE_APPEND);
         foreach ($ar as $vv) {
					if (in_array($vv["name"], array("usdcny", "hkdcny", "jpycny", "audcny", "eurcny","thbcny",  "plncny", "chfcny" , "twdcny" ,"sgdcny"))) {
						$ar_new[] = $vv;
				
					}
		}
			foreach ($ar_new as $it) {
				// if (in_array($it['name'], $trade)) {
					$data = array();
					$data = $this->get_history_kline($it['name'], '30min', 1);
					if ($data['code'] == '200') {
						$info = $data['data'][0];
						$insert_instance = DB::table('market_hour')->where('currency_id', $it['currency_id'])->where('legal_id', $it['legal_id'])->where('day_time', '=', strtotime($info['date']))->where('period', '30min')->where('sign', 2)->where('type', 7)->first();
						if (!empty($insert_instance)) {
							continue 1;
						}
						$insert_Data = array();
						$insert_Data['currency_id'] = $it['currency_id'];
						$insert_Data['legal_id'] = $it['legal_id'];
						$insert_Data['start_price'] = $this->sctonum($info['open']);
						$insert_Data['end_price'] = $this->sctonum($info['close']);
						$insert_Data['mminimum'] = $this->sctonum($info['low']);
						$insert_Data['highest'] = $this->sctonum($info['high']);
						$insert_Data['type'] = 7;
						$insert_Data['sign'] = 2;
						$insert_Data['day_time'] = strtotime($info['date']);
						$insert_Data['period'] = '30min';
						$insert_Data['number'] = bcmul($info['open'], 1, 5);
						$insert_Data['mar_id'] = strtotime($info['date']);
						DB::table('market_hour')->insert($insert_Data);
					}
				// }
// 			}
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
		echo "获取K线数据30min\r\n";
		$this->api_method = "/api/fin/forex/".$period."/realtime?token=".$this->token."&ticker=".strtoupper($symbol);
		$this->req_method = 'GET';
// 		$param = ['start_date' => $symbol, 'end_date' => $period, 'limit' => $size];
// 		if ($size) {
// 			$param['size'] = $size;
// 		}
// 		https://tsanghi.com/api/fin/forex/1min/realtime?token=edf1a10bca0f48b4961489f1adc47bfd&ticker={ticker}
		$url =$this->url . $this->api_method  ;
		file_put_contents("log_getwai.txt", $url . PHP_EOL, FILE_APPEND);
		echo "获取K线数据结束30min\r\n";
		return json_decode($this->curl($url), TRUE);
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
	function bind_param($param)
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
	function create_sig($param)
	{
		$sign_param_1 = $this->req_method . "\r\n" . $this->api . "\r\n" . $this->api_method . "\r\n" . implode('&', $param);
		$signature = hash_hmac('sha256', $sign_param_1, SECRET_KEY, true);
		return base64_encode($signature);
	}
	public function curl($url, $postdata = [])
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		if ($this->req_method == 'POST') {
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata));
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
		$output = curl_exec($ch);
		$info = curl_getinfo($ch);
		curl_close($ch);
		return $output;
	}
}