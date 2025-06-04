<?php

namespace App\Http\Controllers\Api;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\DB;
use App\{
    MailMessage,
    MailMessageUserLog,
    Users,
    UserChat
};

class MailMessageController extends Controller
{
    public function getCount(){
        $user_id = Users::getUserId();
        $count = Db::table('mail_message')
        ->join('mail_message_user_log','mail_message.id','=', 'mail_message_user_log.mail_message_id','left')
        ->where(['mail_message_user_log.user_id'=>$user_id])
        ->where(['mail_message.user_ids'=>$user_id])
        ->orWhere(['mail_message.user_ids'=>0])
        ->where(['mail_message.status'=>1])
        ->whereNotNull('mail_message_user_log.user_id')
        ->selectRaw('count(1) as count')
        ->first();
        $list_count = Db::table('mail_message') 
            ->selectRaw('count(1) as count') 
            ->where(['status'=>1]) 
            ->where(['user_ids'=>$user_id])
            ->orWhere(['user_ids'=>0])
            ->first();
        $result = $list_count -> count - $count -> count;
        return $this->success($result);
    }
    public function getList(){
        $user_id = Users::getUserId();
        $list = MailMessage::where(['user_ids'=>$user_id]) -> orWhere(['user_ids'=>0]) -> get();
        return $this->success($list);
    }
    
    public function detail(Request $request){
        $user_id = Users::getUserId();
        $id = $request->get('id');
        $userreal = MailMessage::find($id);
        if (empty($userreal)) {
            $this->error("信息未找到");
        }
        try {
            $log = MailMessageUserLog::where(['status'=>1]) -> where(['user_id'=>$user_id]) -> where(['mail_message_id'=>$userreal -> id]) -> first();
            if(empty($log)){
                $mailMessageUserLog = new MailMessageUserLog();
                $mailMessageUserLog -> user_id = $user_id;
                $mailMessageUserLog -> mail_message_id = $userreal -> id;
                $mailMessageUserLog -> create_time = time();
                $mailMessageUserLog -> status = 1;
                $mailMessageUserLog -> save();
                $send = ['type' => 'mail_message', 'period' => true];
                UserChat::sendChat($send);
            }
            return $this->success($userreal);
        } catch (\Exception $ex) {
            return $this->error($ex->getMessage());
        }
    }
}
