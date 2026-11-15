<?php
declare(strict_types=1);
ini_set('display_errors','0');
require __DIR__.'/../src/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
function respond($data,int $status=200):void{http_response_code($status);echo Chronicle\Store::json($data);exit;}
try {
    [$store,$config]=chronicle_boot();
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
    if($path==='/api/public/posts'){
        if($method!=='GET')throw new Chronicle\Failure('未対応の操作です',405);
        respond(['posts'=>$store->publicPosts()]);
    }
    $auth=new Chronicle\Auth($store,$config);$auth->start();
    if($path==='/api/session'&&$method==='GET')respond(['authenticated'=>$auth->loggedIn(),'csrf'=>$_SESSION['csrf']]);
    if($method!=='GET')$auth->mutation();
    $input=[];
    if($method==='POST'){
        if((int)($_SERVER['CONTENT_LENGTH']??0)>1000000)throw new Chronicle\Failure('容量の上限を超えています',413);
        $raw=file_get_contents('php://input',false,null,0,1000001);if(strlen($raw)>1000000)throw new Chronicle\Failure('容量の上限を超えています',413);
        $input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($input))throw new Chronicle\Failure('入力形式が不正です');
    }
    if($path==='/api/login'&&$method==='POST'){$auth->login(is_string($input['password']??null)?$input['password']:'');respond(['authenticated'=>true,'csrf'=>$_SESSION['csrf']]);}
    $auth->guard();
    if($path==='/api/logout'&&$method==='POST'){$auth->logout();respond(['authenticated'=>false]);}
    if($path==='/api/admin/posts'&&$method==='POST')respond(['post'=>$store->create($input)],201);
    if(preg_match('~^/api/admin/posts/([1-9][0-9]*)$~D',$path,$m)){
        if($method==='GET')respond(['post'=>$store->one((int)$m[1])]);
        if($method==='POST'){
            if(!is_int($input['revision']??null)||!is_string($input['action']??null))throw new Chronicle\Failure('更新内容を確認してください');
            respond(['post'=>$store->change((int)$m[1],$input['revision'],$input['action'],$input['content']??null)]);
        }
        throw new Chronicle\Failure('未対応の操作です',405);
    }
    throw new Chronicle\Failure('見つかりません',404);
}catch(Chronicle\Failure $e){respond(['error'=>$e->getMessage()],$e->status);}catch(JsonException $e){respond(['error'=>'入力形式が不正です'],400);}catch(Throwable $e){respond(['error'=>'処理できませんでした'],503);}
