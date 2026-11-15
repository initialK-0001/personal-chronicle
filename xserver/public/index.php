<?php
declare(strict_types=1);
ini_set('display_errors','0');
require __DIR__.'/../src/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
function serveHTML(string $html):void{header('Content-Type: text/html; charset=UTF-8');header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:; frame-src https://www.youtube.com https://www.google.com; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");echo $html;exit;}
function respond($data,int $status=200):void{http_response_code($status);echo Chronicle\Store::json($data);exit;}
try {
    [$store,$config]=chronicle_boot();
    $legacy=new Chronicle\Legacy($store,($config['legacy_public_enabled']??false)===true,$config['legacy_amazon_tag']??'');
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
    if($path==='/api/public/posts'){
        if($method!=='GET')throw new Chronicle\Failure('未対応の操作です',405);
        respond(['posts'=>array_merge($store->publicPosts(),$legacy->posts())]);
    }
    if(preg_match('~^/api/public/images/([a-f0-9]{64})$~D',$path,$m)&&$method==='GET')(new Chronicle\Media($store,$config['images']??''))->serve($m[1],true);
    if($method==='GET'&&str_starts_with($path,'/wp-content/uploads/'))$legacy->media($path,$config['legacy_uploads']??'');
    $publicAssets=['/style.css'=>'text/css','/timeline.js'=>'text/javascript','/public.js'=>'text/javascript','/login.js'=>'text/javascript'];
    if($method==='GET'&&isset($publicAssets[$path])){header('Content-Type: '.$publicAssets[$path]);readfile(__DIR__.'/../ui'.$path);exit;}
    if($method==='GET'&&($path==='/'||$path==='/login')){if(isset($_GET['wpdmdl']))$legacy->download((int)$_GET['wpdmdl'],$config['legacy_uploads']??'');serveHTML(file_get_contents(__DIR__.'/../ui/'.($path==='/login'?'login.html':'index.html')));}
    if($method==='GET'&&($r=$legacy->find($path)))serveHTML($legacy->page($r));
    $auth=new Chronicle\Auth($store,$config);$auth->start();
    if($path==='/api/session'&&$method==='GET')respond(['authenticated'=>$auth->loggedIn(),'csrf'=>$_SESSION['csrf']]);
    if($method!=='GET')$auth->mutation();
    if($path==='/api/upload'&&$method==='POST'){$auth->guard();$bytes=file_get_contents('php://input',false,null,0,8*1024*1024+1);respond((new Chronicle\Media($store,$config['images']??''))->upload($bytes));}
    $input=[];
    if($method==='POST'){
        if((int)($_SERVER['CONTENT_LENGTH']??0)>1000000)throw new Chronicle\Failure('容量の上限を超えています',413);
        $raw=file_get_contents('php://input',false,null,0,1000001);if(strlen($raw)>1000000)throw new Chronicle\Failure('容量の上限を超えています',413);
        $input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($input))throw new Chronicle\Failure('入力形式が不正です');
    }
    if($path==='/api/login'&&$method==='POST'){$auth->login(is_string($input['password']??null)?$input['password']:'');respond(['authenticated'=>true,'csrf'=>$_SESSION['csrf']]);}
    if(in_array($path,['/admin','/admin.html'],true)&&!$auth->loggedIn()){header('Location: /login',true,303);exit;}
    $auth->guard();
    if($method==='GET'&&in_array($path,['/admin','/admin.html','/admin.js'],true)){if($path==='/admin.js'){header('Content-Type: text/javascript');readfile(__DIR__.'/../ui/admin.js');exit;}serveHTML(file_get_contents(__DIR__.'/../ui/admin.html'));}
    if($method==='GET'&&preg_match('~^/api/images/([a-f0-9]{64})$~D',$path,$m))(new Chronicle\Media($store,$config['images']??''))->serve($m[1],false);
    if($path==='/api/state'){
        if($method==='GET')respond($store->state());
        if($method!=='POST')throw new Chronicle\Failure('未対応の操作です',405);
        $id=$input['id']??($input['post']['id']??'');if(!ctype_digit((string)$id))throw new Chronicle\Failure('投稿IDを確認してください');
        $version=$input['etag'][(string)$id]??null;if(!is_int($version))throw new Chronicle\Failure('投稿を読み直してください',409);
        $store->change((int)$id,$version,$input['action']??'', $input['post']??null);respond($store->state((int)$id));
    }
    if($path==='/admin/legacy'&&$method==='GET'){$preview=new Chronicle\Legacy($store,true,$config['legacy_amazon_tag']??'');$r=$preview->find($_GET['path']??'');if(!$r)throw new Chronicle\Failure('記事がありません',404);serveHTML($preview->page($r));}
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
