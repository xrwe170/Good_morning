<?php
namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Bank;
use App\Menu;
use App\FalseData;
use App\Market;
use App\Setting;
use App\HistoricalData;
use App\Users;
use App\Utils\RPC;
use App\DAO\UploaderDAO;

class DefaultController extends Controller
{

    public function falseData()
    {
        $limit = Input::get('limit', '12');
        $page = Input::get('page', '1');
        
        $old = date("Y-m-d", strtotime("-1 day"));
        $old_time = strtotime($old);
        $time = strtotime(date("Y-m-d"));
        
        $yesterday = FalseData::where('time', ">", $old_time)->where("time", "<", $time)->sum('price');
        $today = FalseData::where('time', ">", $time)->sum('price');
        
        $data = FalseData::orderBy('id', 'DESC')->paginate($limit);
        
        return $this->success(array(
            "data" => $data->items(),
            "limit" => $limit,
            "page" => $page,
            "yesterday" => $yesterday,
            "today" => $today
        ));
    }

    public function quotation()
    {
        $result = Market::limit(20)->get();
        return $this->success(array(
            "coin_list" => $result
        ));
    }

    public function historicalData()
    {
        $day = HistoricalData::where("type", "day")->orderBy('id', 'asc')->get();
        $week = HistoricalData::where("type", "week")->orderBy('id', 'asc')->get();
        $month = HistoricalData::where("type", "month")->orderBy('id', 'asc')->get();
        
        return $this->success(array(
            "day" => $day,
            "week" => $week,
            "month" => $month
        ));
    }

    public function quotationInfo()
    {
        $id = Input::get("id");
        if (empty($id))
            return $this->error("参数错误");
        
        // $coin_list = RPC::apihttp("https://api.coinmarketcap.com/v2/ticker/".$id."/");
        $coin_list = Market::find($id);
        
        // $coin_list = @json_decode($coin_list,true);
        
        return $this->success($coin_list);
    }

    public function dataGraph()
    {
        $data = Setting::getValueByKey("chart_data");
        if (empty($data))
            return $this->error("暂无数据");
        
        $data = json_decode($data, true);
        return $this->success(array(
            "data" => array(
                $data["time_one"],
                $data["time_two"],
                $data["time_three"],
                $data["time_four"],
                $data["time_five"],
                $data["time_six"],
                $data["time_seven"]
            ),
            "value" => array(
                $data["price_one"],
                $data["price_two"],
                $data["price_three"],
                $data["price_four"],
                $data["price_five"],
                $data["price_six"],
                $data["price_seven"]
            ),
            "all_data" => $data
        ));
    }

    public function index()
    {
        $coin_list = RPC::apihttp("https://api.coinmarketcap.com/v2/ticker?limit=10");
        $coin_list = @json_decode($coin_list, true);
        
        if (! empty($coin_list["data"])) {
            foreach ($coin_list["data"] as &$d) {
                if ($d["total_supply"] > 10000) {
                    $d["total_supply"] = substr($d["total_supply"], 0, - 4) . "万";
                }
            }
        }
        return $this->success(array(
            "coin_list" => $coin_list["data"]
        ));
    }
    
    //上传NFT文件
    public function uploadNFT(Request $request)
    {
         /* 对图像文件进行严格检测 */
            // $arr = ['image/jpg','image/jpeg','image/png','image/gif'];
            // $file = $request->file('file');
            // $imginfo = getimagesize($file->getRealPath());
            // if(empty($imginfo) || empty($imginfo['bits']) || !in_array($imginfo['mime'],$arr)){
            //     return $this->error("wrong format");
            // }
            
        if (! empty($_FILES["file"]["error"])) {
            return $this->error($_FILES["file"]["error"]);
        } else {
            if ($_FILES["file"]["size"] > 10485760) {
                return $this->error("文件大小超出");
            }
            
                $type = strtolower(substr($_FILES["file"]["name"], strrpos($_FILES["file"]["name"], '.') + 1)); // 得到文件类型，并且都转化成小写
                $wenjian_name = time() . rand(0, 999999) . "." . $type;
                $filename = "./upload_nft/" . $wenjian_name;
                // 转码，把utf-8转成gb2312,返回转换后的字符串， 或者在失败时返回 FALSE。
                $filename = iconv("UTF-8", "gb2312", $filename);
                // 检查文件或目录是否存在
                if (file_exists($filename)) {
                    return $this->error("该文件已存在");
                } else {
                    // var_dump($filename);die;
                    move_uploaded_file($_FILES["file"]["tmp_name"], $filename);
                    return $this->success("/upload_nft/" . $wenjian_name);
                }
        }
    }
    
    public function upload(Request $request)
    {
         /* 对图像文件进行严格检测 */
            $arr = ['image/jpg','image/jpeg','image/png'];
            $file = $request->file('file');
            $imginfo = getimagesize($file->getRealPath());
            if(empty($imginfo) || empty($imginfo['bits']) || !in_array($imginfo['mime'],$arr)){
                return $this->error("wrong format");
            }
            
        if (! empty($_FILES["file"]["error"])) {
            return $this->error($_FILES["file"]["error"]);
        } else {
            // if($_FILES["file"]["size"] > 204800){
            // return $this->error("文件大小超出");
            // }
            if ($_FILES["file"]["size"] > 10485760) {
                return $this->error("文件大小超出");
            }
            // return $this->success($_FILES["file"]["type"]);
            if ($_FILES["file"]["type"] == "image/jpg" || $_FILES["file"]["type"] == "image/png" || $_FILES["file"]["type"] == "image/jpeg") {
                $type = strtolower(substr($_FILES["file"]["name"], strrpos($_FILES["file"]["name"], '.') + 1)); // 得到文件类型，并且都转化成小写
                $wenjian_name = time() . rand(0, 999999) . "." . $type;
                // 防止文件名重复
                // 超哥写的上传路径
                // $url = config('app.images_url');
                // $url = \think\Env::get('IMAGS_URL','./upload/');
                // $filename = $url.$wenjian_name;
                // $filename ="/www/wwwroot/imgs.bitfor-ex.com/upload/".$wenjian_name;
                $filename = "./upload/" . $wenjian_name;
                // 转码，把utf-8转成gb2312,返回转换后的字符串， 或者在失败时返回 FALSE。
                $filename = iconv("UTF-8", "gb2312", $filename);
                // 检查文件或目录是否存在
                if (file_exists($filename)) {
                    return $this->error("该文件已存在");
                } else {
                    // var_dump($filename);die;
                    move_uploaded_file($_FILES["file"]["tmp_name"], $filename);
                    return $this->success("/upload/" . $wenjian_name);
                }
            } else {
                return $this->error("文件类型不对");
            }
        }
    }
    
    public function upload_new(Request $request)
    {
         /* 对图像文件进行严格检测 */
            $arr = ['image/jpg','image/jpeg','image/png'];
            $file = $request->file('file');
            $imginfo = getimagesize($file->getRealPath());
            if(empty($imginfo) || empty($imginfo['bits']) || !in_array($imginfo['mime'],$arr)){
                return $this->error("wrong format");
            }
            
        if (! empty($_FILES["file"]["error"])) {
            return $this->error($_FILES["file"]["error"]);
        } else {
            // if($_FILES["file"]["size"] > 204800){
            // return $this->error("文件大小超出");
            // }
            if ($_FILES["file"]["size"] > 10485760) {
                return $this->error("文件大小超出");
            }
            // return $this->success($_FILES["file"]["type"]);
            if ($_FILES["file"]["type"] == "image/jpg" || $_FILES["file"]["type"] == "image/png" || $_FILES["file"]["type"] == "image/jpeg") {
                $type = strtolower(substr($_FILES["file"]["name"], strrpos($_FILES["file"]["name"], '.') + 1)); // 得到文件类型，并且都转化成小写
                $wenjian_name = time() . rand(0, 999999) . "." . $type;
                // 防止文件名重复
                // 超哥写的上传路径
                $url = config('app.images_url');
                // $url = \think\Env::get('IMAGS_URL','./upload/');
                $filename = $url.$wenjian_name;
                // $filename ="/www/wwwroot/imgs.bitfor-ex.com/upload/".$wenjian_name;
                // $filename = "./upload/" . $wenjian_name;
                // 转码，把utf-8转成gb2312,返回转换后的字符串， 或者在失败时返回 FALSE。
                $filename = iconv("UTF-8", "gb2312", $filename);
                // 检查文件或目录是否存在
                if (file_exists($filename)) {
                    return $this->error("该文件已存在");
                } else {
                    // var_dump($filename);die;
                    move_uploaded_file($_FILES["file"]["tmp_name"], $filename);
                    return $this->success("/upload/" . $wenjian_name);
                }
            } else {
                return $this->error("文件类型不对");
            }
        }
    }

    // ios 文件上传
    public function upload2(Request $request)
    {
        $base64_image_content = $request->input('base64_file', '');
        $res = self::base64_image_content($base64_image_content);
        if (! $res) {
            return $this->error('上传失败');
        }
        
        return $this->success($res);
    }

    /* base64格式编码转换为图片并保存对应文件夹 */
    public function base64_image_content($base64_image_content)
    {
        // 匹配出图片的格式
        if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $base64_image_content, $result)) {
            $type = $result[2];
            if (! in_array($type, [
                'jpg',
                'jpeg',
                'png'
            ])) {
                return false;
            }
            // $new_file = $path."/".date('Ymd',time())."/";
            $path = '/upload/' . date('Ymd') . '/';
            $new_file = public_path() . $path;
            if (! file_exists($new_file)) {
                // 检查是否有该文件夹，如果没有就创建，并给予最高权限
                mkdir($new_file, 0700);
            }
            $filename = time() . rand(0, 999999) . ".{$type}";
            $full_file = $new_file . $filename;
            if (file_put_contents($full_file, base64_decode(str_replace($result[1], '', $base64_image_content)))) {
                return url('') . $path . $filename;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function getNode(\Illuminate\Http\Request $request)
    {
        $user_id = $request->get('user_id', 0);
        $show_message["real_teamnumber"] = Users::find($user_id)->real_teamnumber;
        $show_message["top_upnumber"] = Users::find($user_id)->top_upnumber;
        $show_message["today_real_teamnumber"] = Users::find($user_id)->today_real_teamnumber;
        $account_number = $request->get('account_number', null);
        if (! empty($account_number)) {
            $user_id_search = Users::where('account_number', $account_number)->first();
            if (! empty($user_id_search)) {
                $user_id = $user_id_search->id;
            } else {
                $user_id = 0;
            }
        }
        // if (empty($user_id)){
        $users = Users::where('parent_id', $user_id)->get();
        $results = array();
        foreach ($users as $key => $user) {
            $results[$key]['name'] = $user->account_number;
            $results[$key]['id'] = $user->id;
            $results[$key]['parent_id'] = $user->parent_id;
        }
        $data["show_message"] = $show_message;
        $data["results"] = $results;
        return $this->success($data);
    }

    public function getVersion()
    {
        $version = Setting::getValueByKey('version', '1.0');
        return $this->success($version);
    }

    public function getBanks()
    {
        $result = Bank::all();
        return $this->success($result);
    }

    public function language(Request $request)
    {
        $lang = $request->get('lang', 'zh');
        session()->put('lang', $lang);
        return $this->success($lang);
    }
    
     public function getMenu()
    {
        $menu = Menu::where('show', 1)->orderBy('sort','asc')->get();
        return $this->success($menu);
    }

    public function getSiteConfig(Request $request) {
        $model = Setting::whereIn('key', ['site_name', 'site_logo','site_pc_logo', 'down_logo','open_url'
            ,'zxkf_radio','zxkf_url','telegram_url','telegram_radio','skype_radio','skype_url','whatsApp_radio'
            ,'whatsApp_url','line_radio','line_url','jie_radio','jie_url','hk_radio','hk_url','bank_flag','image_server_url','tk_radio','yzm_radio','ios_apk_download_url','apk_download_url'
        ])->get();
        $settings = [];
        foreach ($model as $setting) {
            $settings[$setting->key] = $setting->value;
        }
        return $this->success($settings);
    }
    
    // public function getlanguage(\Request $request)
    // {
    // $lang=session()->get('lang');
    // return $this->success($lang);
    // }
}
?>