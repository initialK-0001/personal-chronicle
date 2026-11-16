<?php
declare(strict_types=1);
ini_set('display_errors','0');
require __DIR__.'/../src/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
function serveHTML(string $html,bool $public=false):void{global $config,$legacy;$index=$public&&($config['public_indexing']??false)===true;header('X-Robots-Tag: '.($index?'index, follow':'noindex, nofollow'));if($index)$html=str_replace('<meta name="robots" content="noindex,nofollow">','',$html);header('Content-Type: text/html; charset=UTF-8');header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:; frame-src https://www.youtube.com https://www.google.com; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");if($public&&$legacy->find('/privacy-policy'))$html=str_replace('</body>','<nav class="policy-links" aria-label="サイト情報"><a href="/privacy-policy">プライバシーポリシー</a></nav></body>',$html);if(Chronicle\Ads::enabled($config,$public)&&rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/')!=='/contact-form'){$nonce=base64_encode(random_bytes(18));$html=Chronicle\Ads::render($html,$config,$nonce);header("Content-Security-Policy: ".Chronicle\Ads::policy($nonce));}echo $html;exit;}
function respond($data,int $status=200):void{http_response_code($status);echo Chronicle\Store::json($data);exit;}
try {
    [$store,$config]=chronicle_boot();
    $legacy=new Chronicle\Legacy($store,($config['legacy_public_enabled']??false)===true,$config['legacy_amazon_tag']??'');
    header('X-Robots-Tag: noindex, nofollow');
    $comments=new Chronicle\Comments($store,$legacy,$config['rate_secret']);
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
    if($path==='/ads.txt'){
        if(!in_array($method,['GET','HEAD'],true)){header('Allow: GET, HEAD');throw new Chronicle\Failure('未対応の操作です',405);}
        $sellers=Chronicle\Ads::sellers($config);if($sellers===null)throw new Chronicle\Failure('見つかりません',404);
        header('Content-Type: text/plain; charset=UTF-8');if($method==='GET')echo $sellers;exit;
    }
    if($path==='/api/public/comments'&&$method==='GET')respond(['comments'=>$comments->published(is_string($_GET['post']??null)?$_GET['post']:'')]);
    if($path==='/api/public/posts'){
        if($method!=='GET')throw new Chronicle\Failure('未対応の操作です',405);
        respond(['posts'=>array_merge($store->publicPosts(),$legacy->posts())]);
    }
    if(preg_match('~^/api/public/images/([a-f0-9]{64})$~D',$path,$m)&&$method==='GET')(new Chronicle\Media($store,$config['images']??''))->serve($m[1],true,$legacy->referencesImage($m[1]));
    if($method==='GET'&&str_starts_with($path,'/wp-content/uploads/'))$legacy->media($path,$config['legacy_uploads']??'');
    $publicAssets=['/style.css'=>'text/css','/timeline.js'=>'text/javascript','/public.js'=>'text/javascript','/login.js'=>'text/javascript','/comments.js'=>'text/javascript','/contact.js'=>'text/javascript'];
    if($method==='GET'&&isset($publicAssets[$path])){header('Content-Type: '.$publicAssets[$path]);readfile(__DIR__.'/../ui'.$path);exit;}
    if($method==='GET'&&$path==='/robots.txt'){header('Content-Type: text/plain; charset=UTF-8');echo ($config['public_indexing']??false)===true?"User-agent: *\nDisallow: /admin\nDisallow: /api/\nDisallow: /login\nSitemap: ".$config['origin']."/sitemap.xml\n":"User-agent: *\nDisallow: /\n";exit;}
    if($method==='GET'&&$path==='/sitemap.xml'){if(($config['public_indexing']??false)!==true)throw new Chronicle\Failure('見つかりません',404);header('Content-Type: application/xml; charset=UTF-8');echo Chronicle\Web::sitemap($config['origin'],$store->publicPosts(),$legacy);exit;}
    if($method==='GET'&&$path==='/login')serveHTML(file_get_contents(__DIR__.'/../ui/login.html'));
    if($method==='GET'&&$path==='/'){
        if(isset($_GET['wpdmdl']))$legacy->download((int)$_GET['wpdmdl'],$config['legacy_uploads']??'');
        $posts=$store->publicPosts();$selected=null;
        if(isset($_GET['post'])){if(!is_string($_GET['post']))throw new Chronicle\Failure('記事がありません',404);foreach($posts as $p)if($p['id']===$_GET['post'])$selected=$p;if(!$selected)throw new Chronicle\Failure('記事がありません',404);}
        serveHTML(Chronicle\Web::page(file_get_contents(__DIR__.'/../ui/index.html'),array_merge($posts,$legacy->posts()),$config['origin'],$selected),true);
    }
    if($method==='GET'&&rtrim($path,'/')==='/contact-form'){
        if(!$legacy->find('/contact-form'))throw new Chronicle\Failure('見つかりません',404);
        serveHTML(str_replace('</head>','<link rel="canonical" href="'.Chronicle\Web::escape($config['origin'].'/contact-form').'"></head>',file_get_contents(__DIR__.'/../ui/contact.html')),true);
    }
    if($method==='GET'&&($r=$legacy->find($path))){$html=$legacy->page($r);$html=str_replace('</head>','<link rel="canonical" href="'.Chronicle\Web::escape(Chronicle\Web::url($config['origin'],$r['path'])).'"></head>',$html);serveHTML($html,true);}
    if($method==='GET'&&($category=$legacy->category($path))){$html=Chronicle\Web::page(file_get_contents(__DIR__.'/../ui/index.html'),array_merge($store->publicPosts(),$legacy->posts()),$config['origin'],null,$category['name']);$html=str_replace('</head>','<link rel="canonical" href="'.Chronicle\Web::escape(Chronicle\Web::url($config['origin'],$category['path'])).'"></head>',$html);serveHTML($html,true);}
    if(!str_starts_with($path,'/api/')&&!str_starts_with($path,'/admin')&&!in_array($path,['/comments-admin.js','/legacy-admin.js','/contact-admin.js'],true))throw new Chronicle\Failure('見つかりません',404);
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
    $contact=new Chronicle\Contact($store,$config['rate_secret'],$legacy->find('/contact-form')!==null);
    if($path==='/api/public/contact'&&$method==='POST'){$contact->submit($input,$_SERVER['REMOTE_ADDR']??'unknown');respond(['received'=>true],202);}
    if($path==='/api/public/comments'&&$method==='POST'){$comments->submit($input,$_SERVER['REMOTE_ADDR']??'unknown');respond(['received'=>true],202);}
    if(in_array($path,['/admin','/admin.html','/admin/comments','/admin/legacy-edit','/admin/contact'],true)&&!$auth->loggedIn()){header('Location: /login',true,303);exit;}
    $auth->guard();
    if($path==='/admin/contact'&&$method==='GET')serveHTML(file_get_contents(__DIR__.'/../ui/contact-admin.html'));
    if($path==='/contact-admin.js'&&$method==='GET'){header('Content-Type: text/javascript');readfile(__DIR__.'/../ui/contact-admin.js');exit;}
    if($path==='/api/admin/contact'&&$method==='GET')respond(['messages'=>$contact->listing()]);
    if(preg_match('~^/api/admin/contact/([1-9][0-9]*)$~D',$path,$m)&&$method==='POST'){
        if(!is_int($input['revision']??null)||!is_string($input['action']??null))throw new Chronicle\Failure('操作を確認してください');
        $contact->change((int)$m[1],$input['revision'],$input['action']);respond(['saved'=>true]);
    }
    $legacyEditor=new Chronicle\LegacyEditor($store,$legacy);
    if($path==='/admin/legacy-edit'&&$method==='GET')serveHTML(file_get_contents(__DIR__.'/../ui/legacy-admin.html'));
    if($path==='/legacy-admin.js'&&$method==='GET'){header('Content-Type: text/javascript');readfile(__DIR__.'/../ui/legacy-admin.js');exit;}
    if($path==='/api/admin/legacy'&&$method==='GET')respond(['posts'=>$legacyEditor->listing()]);
    if(preg_match('~^/api/admin/legacy/([1-9][0-9]*)$~D',$path,$m)){
        if($method==='GET')respond($legacyEditor->editView((int)$m[1]));
        if($method==='POST'){
            if(!is_int($input['revision']??null)||!is_string($input['action']??null)||isset($input['content'])&&!is_array($input['content']))throw new Chronicle\Failure('更新内容を確認してください');
            respond($legacyEditor->change((int)$m[1],$input['revision'],$input['action'],$input['content']??null));
        }
        throw new Chronicle\Failure('未対応の操作です',405);
    }
    if($path==='/api/admin/legacy-preview'&&$method==='POST'){
        if(!is_string($input['html']??null)||strlen($input['html'])>900000)throw new Chronicle\Failure('本文を確認してください');respond(['html'=>$legacy->html($input['html'])]);
    }
    if($method==='GET'&&str_starts_with($path,'/admin/legacy-media/'))$legacy->media('/wp-content/uploads/'.substr($path,strlen('/admin/legacy-media/')),$config['legacy_uploads']??'',true);
    if($path==='/admin/comments'&&$method==='GET')serveHTML(file_get_contents(__DIR__.'/../ui/comments-admin.html'));
    if($path==='/comments-admin.js'&&$method==='GET'){header('Content-Type: text/javascript');readfile(__DIR__.'/../ui/comments-admin.js');exit;}
    if($path==='/api/admin/comments'&&$method==='GET')respond(['comments'=>$comments->moderation()]);
    if(preg_match('~^/api/admin/comments/([1-9][0-9]*)$~D',$path,$m)&&$method==='POST'){
        if(!is_int($input['revision']??null)||!is_string($input['action']??null))throw new Chronicle\Failure('操作を確認してください');
        $comments->change((int)$m[1],$input['revision'],$input['action'],$input['reply']??null);respond(['saved'=>true]);
    }
    if($method==='GET'&&in_array($path,['/admin','/admin.html','/admin.js'],true)){if($path==='/admin.js'){header('Content-Type: text/javascript');readfile(__DIR__.'/../ui/admin.js');exit;}serveHTML(file_get_contents(__DIR__.'/../ui/admin.html'));}
    if($method==='GET'&&preg_match('~^/api/images/([a-f0-9]{64})$~D',$path,$m))(new Chronicle\Media($store,$config['images']??''))->serve($m[1],false);
    if($path==='/api/state'){
        if($method==='GET')respond($store->state());
        if($method!=='POST')throw new Chronicle\Failure('未対応の操作です',405);
        $id=$input['id']??($input['post']['id']??'');if(!ctype_digit((string)$id))throw new Chronicle\Failure('投稿IDを確認してください');
        $version=$input['etag'][(string)$id]??null;if(!is_int($version))throw new Chronicle\Failure('投稿を読み直してください',409);
        $store->change((int)$id,$version,$input['action']??'', $input['post']??null);respond($store->state((int)$id));
    }
    if($path==='/admin/legacy-id'&&$method==='GET'){$preview=new Chronicle\Legacy($store,true);foreach($preview->records() as $r)if((string)$r['legacy_id']===($_GET['id']??''))serveHTML($preview->page($r));throw new Chronicle\Failure('記事がありません',404);}
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
