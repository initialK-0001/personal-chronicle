<?php
declare(strict_types=1);
require __DIR__.'/../src/Ads.php';
use Chronicle\Ads;
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$config=['origin'=>'https://example.test','public_indexing'=>true,'adsense'=>['enabled'=>true,'account_settings_verified'=>true,'client'=>'ca-pub-0000000000000000','slot'=>'0000000000']];
check(Ads::enabled($config,true),'本番公開設定');check(!Ads::enabled($config,false),'管理画面は広告なし');check(!Ads::enabled([],true),'未設定は広告なし');
foreach(['enabled','account_settings_verified'] as $key){$c=$config;$c['adsense'][$key]=false;check(!Ads::enabled($c,true),'確認前は配信しない');}
$c=$config;$c['local_test']=true;check(!Ads::enabled($c,true),'テストで配信しない');$c=$config;$c['public_indexing']=false;check(!Ads::enabled($c,true),'開発URLで配信しない');
$c=$config;$c['adsense']['slot']='" onclick="evil';check(!Ads::enabled($c,true),'識別子の入力検証');
$html='<html><body><main>作品画像と本文</main><script type="module" src="/public.js"></script></body></html>';$nonce=base64_encode(random_bytes(18));$out=Ads::render($html,$config,$nonce);
check(strpos($out,'chronicle-ad')>strpos($out,'</main>'),'作品に重ねず本文の後');check(substr_count($out,'adsbygoogle.js')===1,'ライブラリーは一度');check(substr_count($out,'nonce="'.$nonce.'"')===3,'既存JSもnonce付与');check(str_contains(Ads::policy($nonce),"'strict-dynamic'"),'対応CSP');check(str_contains($out,'スポンサーリンク'),'広告ラベル');
echo "広告の本番限定 管理・開発除外 入力検証 本文後配置 nonce付与を確認\n";
