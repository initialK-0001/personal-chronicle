<?php
declare(strict_types=1);
// Authorized production cutover 2026-11-16. Never deletes the old site or database.
if(PHP_SAPI!=='cli')exit(1);umask(0077);ini_set('display_errors','0');
$root=__DIR__;$base=$root.'/private/cutover';$live='/home/may231754/brand-new.tokyo/public_html';$next=$base.'/public-next';$old=$base.'/wordpress-before-switch';$cfg=$root.'/private/config.json';$out=$root.'/production-switch-v2.json';
$lock=fopen($root.'/production-switch.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||file_exists($out))exit;
$r=['time'=>gmdate('c'),'success'=>false];$moved=false;$installed=false;$configChanged=false;
function must(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
try{
 must(PHP_VERSION_ID>=80300,'php_cli');must(is_dir($live)&&!is_link($live)&&is_dir($next)&&!file_exists($old),'directories');
 $report=json_decode(file_get_contents($root.'/live-delta.json'),true,512,JSON_THROW_ON_ERROR);must(($report['success']??false)&&($report['content_unchanged']??false),'delta');$checked=strtotime($report['started_at']??'');must($checked!==false&&time()-$checked>=0&&time()-$checked<7200,'delta_expired');
 $originals=json_decode(file_get_contents($base.'/originals.json'),true,512,JSON_THROW_ON_ERROR);foreach($originals as $name=>$info)must(hash_file('sha256',$live.'/'.$name)===$info['sha256'],'original_hash');
 $prepared=json_decode(file_get_contents($root.'/public-directory-preparation.json'),true,512,JSON_THROW_ON_ERROR);foreach($prepared['candidate_hashes'] as $name=>$hash)must(hash_file('sha256',$next.'/'.$name)===$hash,'candidate_hash');
 $config=json_decode(file_get_contents($cfg),true,512,JSON_THROW_ON_ERROR);must(($config['legacy_public_enabled']??true)===false&&($config['public_indexing']??true)===false,'configuration_state');must(($config['adsense']['enabled']??true)===false,'ads_disabled');
 $snapshot=$base.'/config-before-switch.json';must(!file_exists($snapshot)&&copy($cfg,$snapshot),'config_snapshot');chmod($snapshot,0600);
 $config['legacy_public_enabled']=true;$config['public_indexing']=true;$config['adsense']['enabled']=false;
 must(file_put_contents($cfg.'.next',json_encode($config,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))!==false,'config_stage');chmod($cfg.'.next',0600);
 must(rename($live,$old),'old_move');$moved=true;
 must(rename($cfg.'.next',$cfg),'config_activate');$configChanged=true;
 must(chmod($next,0755),'public_permissions');must(rename($next,$live),'new_move');$installed=true;
 foreach($prepared['candidate_hashes'] as $name=>$hash)must(hash_file('sha256',$live.'/'.$name)===$hash,'installed_hash');
 $r+=['old_site_preserved'=>is_file($old.'/wp-config.php'),'public_directory_installed'=>true,'published_legacy_enabled'=>true,'advertising_enabled'=>false,'php_panel_change_pending'=>true];$r['success']=true;
}catch(Throwable $e){
 $r['error_class']=get_class($e);if($e instanceof RuntimeException)$r['check']=$e->getMessage();
 $rollback=true;if($installed)$rollback=rename($live,$next)&&$rollback;
 if($configChanged)$rollback=copy($base.'/config-before-switch.json',$cfg)&&$rollback;
 if($moved)$rollback=rename($old,$live)&&$rollback;
 $r['rolled_back']=$rollback;
}
file_put_contents($out,json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($out,0600);
