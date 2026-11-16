<?php
declare(strict_types=1);
namespace Chronicle;
final class Auth {
    private Store $store;
    private array $config;
    public function __construct(Store $store,array $config){$this->store=$store;$this->config=$config;}
    public function start():void {
        if(!is_dir($this->config['sessions']))throw new Failure('セッション保存先を確認してください',503);
        ini_set('session.gc_maxlifetime',(string)(8*3600));ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.use_trans_sid','0');
        session_name('chronicle_session');session_save_path($this->config['sessions']);
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!($this->config['local_test']??false),'httponly'=>true,'samesite'=>'Strict']);session_start();
        if(isset($_SESSION['admin'])&&($_SESSION['expires']??0)<time()){$_SESSION=[];session_regenerate_id(true);}
        if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public function loggedIn():bool{return ($_SESSION['admin']??false)===true&&($_SESSION['expires']??0)>=time();}
    public function guard():void{if(!$this->loggedIn())throw new Failure('ログインしてください',401);}
    public function mutation():void{
        if(($_SERVER['HTTP_ORIGIN']??'')!==$this->config['origin'])throw new Failure('操作元のURLを確認できません 正規の管理画面を開き直してください',403);
        if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))throw new Failure('画面の認証情報が古くなっています 再読み込みしてください',403);
    }
    public function login(string $password):void{
        $bucket=hash_hmac('sha256',($_SERVER['REMOTE_ADDR']??'unknown').':'.intdiv(time(),900),$this->config['rate_secret']);
        $db=$this->store->db;$db->beginTransaction();
        try{
            $sql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
            $q=$db->prepare($sql.' INTO chronicle_login_limits(bucket,attempts,expires) VALUES(?,0,?)');$q->execute([$bucket,time()+1800]);
            $q=$db->prepare('UPDATE chronicle_login_limits SET attempts=attempts+1 WHERE bucket=? AND attempts<8');$q->execute([$bucket]);$allowed=$q->rowCount()===1;
            $q=$db->prepare('DELETE FROM chronicle_login_limits WHERE expires<?');$q->execute([time()]);$db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        if(!$allowed)throw new Failure('しばらく待ってからログインしてください',429);
        if(strlen($password)>1024||!password_verify($password,$this->config['password_hash']))throw new Failure('ログインできません',401);
        session_regenerate_id(true);$_SESSION=['admin'=>true,'expires'=>time()+8*3600,'csrf'=>bin2hex(random_bytes(32))];
        $q=$db->prepare('DELETE FROM chronicle_login_limits WHERE bucket=?');$q->execute([$bucket]);
    }
    public function logout():void{$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));}
}
