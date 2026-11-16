<?php
declare(strict_types=1);
// Deploy beside xserver/ and WordPressExport.php, outside the web root.
if(PHP_SAPI!=='cli')exit(1);umask(0077);ini_set('display_errors','0');set_time_limit(0);
$root=__DIR__;$private=$root.'/private';$report=$root.'/migration-import.json';$lock=fopen($root.'/migration-import.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||is_file($report))exit;
$r=['started_at'=>gmdate('c'),'success'=>false];$step='source';
function need(bool $condition,string $code):void{if(!$condition)throw new RuntimeException($code);}
try{
 require $root.'/WordPressExport.php';require $root.'/xserver/src/Store.php';require $root.'/xserver/src/Legacy.php';
 $sql='/home/may231754/brand-new.tokyo/chronicle-migration-backup-20261115/Brand-new-TOKYO-db-20261115.sql.gz';
 $uploads='/home/may231754/chronicle-web-backup-20261115/brand-new.tokyo/public_html/wp-content/uploads';
 $data=Chronicle\WordPressExport::read($sql,'wp319d61','42175f697beb90f0f555a957c3a9bdcfb9789a23b340894f195dc5209608073c');
 need(hash('sha256',Chronicle\WordPressExport::canonical($data))==='205eaaba9511f05fb50931900be3567192aebbb303260518c1b32105c9644653','export_mismatch');
 $r['records']=count($data['records']);$r['downloads']=count($data['downloads']);$r['states']=array_count_values(array_column($data['records'],'status'));$r['original_data_match']=true;
 $step='database_guard';$cfg=require $private.'/database.php';need(str_contains($cfg['dsn'],'dbname=may231754_chronicle;'),'database_target');$db=new PDO($cfg['dsn'],$cfg['db_user'],$cfg['db_password']);$s=new Chronicle\Store($db);need($db->query('SELECT DATABASE()')->fetchColumn()==='may231754_chronicle','database_name');
 foreach(['chronicle_imports','chronicle_downloads','chronicle_posts','chronicle_legacy_edits'] as $t)need((int)$db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn()===0,'target_not_empty');
 $step='media';$dest=$private.'/legacy-uploads';$partial=$dest.'.partial';need(!file_exists($dest)&&!file_exists($partial),'media_exists');need(disk_free_space($private)>2*1024**3,'disk_space');need(mkdir($partial,0700),'mkdir');
 $dirs=['2016','2017','2018','2021','2022','poses','pictures','download-manager-files'];$manifest=[];$bytes=0;
 foreach($dirs as $dir){$base=$uploads.'/'.$dir;need(is_dir($base)&&!is_link($base),'media_source');$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
  foreach($it as $file){need(!$file->isLink(),'media_symlink');if(!$file->isFile())continue;$source=$file->getPathname();$rel=substr($source,strlen($uploads)+1);need(!str_contains($rel,"\0")&&!str_contains($rel,"\n"),'media_name');$target=$partial.'/'.$rel;
   if(!is_dir(dirname($target)))need(mkdir(dirname($target),0700,true),'media_mkdir');$before=hash_file('sha256',$source);need(copy($source,$target),'media_copy');chmod($target,0600);$hash=hash_file('sha256',$target);need(hash_equals($before,$hash),'media_hash');$size=filesize($target);$bytes+=$size;$manifest[$rel]=['bytes'=>$size,'sha256'=>$hash];
  }
 }
 ksort($manifest,SORT_STRING);$h=hash_init('sha256');foreach($manifest as $name=>$a)hash_update($h,$name."\0".$a['bytes']."\0".$a['sha256']."\n");$hash=hash_final($h);
 need(count($manifest)===7161&&$bytes===825489056&&$hash==='5b85f9088e9f96f7adae61d8b831759b15133d419e74b47a8f7740a4ed5ab65a','media_inventory');
 need(rename($partial,$dest),'media_finalize');$r['media']=['files'=>count($manifest),'bytes'=>$bytes,'manifest_sha256'=>$hash,'all_match'=>true];
 file_put_contents($private.'/legacy-media-manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));file_put_contents($private.'/posts-import.json',Chronicle\Store::json($data));
 $step='import';$r['import']=$s->stageImport($data['records']);$db->beginTransaction();try{$q=$db->prepare('INSERT INTO chronicle_downloads VALUES(?,?)');foreach($data['downloads'] as $item)$q->execute([$item['id'],Chronicle\Store::json($item)]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 $step='verify';$rows=$db->query('SELECT original_json FROM chronicle_imports ORDER BY legacy_id')->fetchAll(PDO::FETCH_COLUMN);$actual=array_map(fn($v)=>json_decode($v,true,512,JSON_THROW_ON_ERROR),$rows);need($actual===$data['records'],'record_roundtrip');
 $rows=$db->query('SELECT data_json FROM chronicle_downloads ORDER BY legacy_id')->fetchAll(PDO::FETCH_COLUMN);need(array_map(fn($v)=>json_decode($v,true,512,JSON_THROW_ON_ERROR),$rows)===$data['downloads'],'download_roundtrip');
 need($s->publicPosts()===[]&&(new Chronicle\Legacy($s,false))->records()===[],'not_public');$r['roundtrip_match']=true;$r['public_records_enabled']=false;$r['reimport']=$s->stageImport($data['records']);
 $step='private_paths';foreach(['images','sessions'] as $dir){need(!file_exists($private.'/'.$dir),'path_exists');need(mkdir($private.'/'.$dir,0700),'private_mkdir');}
 $f=fopen($private.'/admin-password.txt','x');need($f!==false,'admin_input_exists');fclose($f);chmod($private.'/admin-password.txt',0600);
 $r['admin_password_ready']=false;$r['success']=true;$r['finished_at']=gmdate('c');
}catch(Throwable $e){$r['failed_step']=$step;$r['error_class']=get_class($e);$r['error_code']=(string)$e->getCode();if($e instanceof RuntimeException&&!($e instanceof PDOException))$r['check']=$e->getMessage();}
file_put_contents($report,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));chmod($report,0600);
