<?php
declare(strict_types=1);
// Deploy beside xserver/, outside public_html. No credential values enter reports.
if(PHP_SAPI!=='cli')exit(1);
umask(0077);ini_set('display_errors','0');
$root=__DIR__;$out=$root.'/mysql-probe-v3.json';
$lock=fopen($root.'/mysql-probe-v3.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||file_exists($out))exit;
$r=['time'=>gmdate('c'),'php'=>PHP_VERSION,'checks'=>[]];$step='setup';
function verify($ok,string $name):void {global $r;if(!$ok)throw new RuntimeException($name);$r['checks'][$name]=true;}
try{
 require $root.'/xserver/src/Store.php';
 $prefix='mprobe_'.bin2hex(random_bytes(5)).'_';
 $generated=$root.'/private/'.$prefix;mkdir($generated,0700);
 foreach(['Probe'=>$prefix,'Restore'=>$prefix.'r_'] as $ns=>$pre)foreach(['Store','Legacy','Comments','Backup'] as $class){
  $source=file_get_contents($root.'/xserver/src/'.$class.'.php');
  $source=str_replace("__DIR__.'/../vendor/autoload.php'",var_export($root.'/xserver/vendor/autoload.php',true),$source);
  $source=str_replace(['namespace Chronicle;','chronicle_'],['namespace '.$ns.';',$pre],$source);
  file_put_contents($generated.'/'.$ns.$class.'.php',$source);require $generated.'/'.$ns.$class.'.php';
 }
 $r['test_prefix']=$prefix;
 $step='credential';$secret=$root.'/private/db-password.txt';verify(is_file($secret)&&!is_link($secret)&&((fileperms($secret)&0777)===0600),'credential_permissions');
 $password=rtrim(file_get_contents($secret),"\r\n");verify(strlen($password)>0,'credential_present');
 $dsn='mysql:host=mysql10070.xserver.jp;dbname=may231754_chronicle;charset=utf8mb4';$user='may231754_chroni';
 $connect=fn()=>new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $step='connect';$db=$connect();verify($db->query('SELECT DATABASE()')->fetchColumn()==='may231754_chronicle','dedicated_database');$r['mysql']=$db->query('SELECT VERSION()')->fetchColumn();
 $tables=['chronicle_contact','chronicle_comments','chronicle_comment_limits','chronicle_downloads','chronicle_assets','chronicle_posts','chronicle_history','chronicle_imports','chronicle_legacy_edits','chronicle_legacy_history','chronicle_login_limits'];
 $step='empty_guard';$existing=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);foreach($existing as $t){verify(in_array($t,$tables,true),'known_tables');verify((int)$db->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn()===0,'existing_tables_empty');}
 $step='schema';$s=new Chronicle\Store($db);$s->initialize();verify(count($db->query('SHOW TABLES')->fetchAll())===11,'schema_created');
 $step='isolated_tables';$s=new Probe\Store($db);$s->initialize();
 $p=['title'=>'','tags'=>[],'blocks'=>[['type'=>'paragraph','text'=>'接続確認 🟢']]];
 $step='post';$a=$s->create($p);$id=(int)$a['id'];verify($id===1,'automatic_id');verify($s->publicPosts()===[],'draft_private');$s->change($id,1,'release');
 $p['blocks'][0]['text']='未公開の追記';$s->change($id,2,'draft',$p);verify($s->publicPosts()[0]['blocks'][0]['text']==='接続確認 🟢','published_snapshot_separate');
 try{$s->change($id,2,'release');throw new RuntimeException('conflict_accepted');}catch(Probe\Failure $e){verify($e->status===409,'conflict_rejected');}
 $s->change($id,3,'unpublish');verify($s->publicPosts()===[],'unpublish');$s->change($id,4,'release');
 $step='comments';$c=new Probe\Comments($s,new Probe\Legacy($s,true),str_repeat('t',32));$c->submit(['post'=>(string)$id,'body'=>'匿名コメント'],'test');verify($c->published((string)$id)===[],'pending_private');
 try{$c->change(1,1,'reply','返信');throw new RuntimeException('unapproved_reply');}catch(Probe\Failure $e){verify($e->status===409,'unapproved_reply_rejected');}
 $c->change(1,1,'approve');verify(count($c->published((string)$id))===1,'comment_approval');
 $step='backup';$work=$root.'/private/mysql-rehearsal-'.bin2hex(random_bytes(5));mkdir($work,0700);foreach(['images','legacy','restored-images','restored-legacy'] as $d)mkdir($work.'/'.$d,0700);
 $rows=Probe\Backup::export($s,['images'=>$work.'/images','legacy_uploads'=>$work.'/legacy'],$work.'/backup');
 $target=new Restore\Store($connect());$target->initialize();
 // Adapt only test table identifiers to the separate restore namespace.
 $input=$work.'/restore-input';mkdir($input,0700);
 $data=json_decode(file_get_contents($work.'/backup/database.json'),true,512,JSON_THROW_ON_ERROR);
 $mapped=[];foreach($data['tables'] as $name=>$values)$mapped[str_replace($prefix,$prefix.'r_',$name)]=$values;$data['tables']=$mapped;
 $payload=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);file_put_contents($input.'/database.json',$payload);
 $manifest=json_decode(file_get_contents($work.'/backup/manifest.json'),true,512,JSON_THROW_ON_ERROR);$manifest['files']['database.json']=hash('sha256',$payload);$manifest['rows']=array_map('count',$mapped);file_put_contents($input.'/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
 $restored=Restore\Backup::restore($target,['images'=>$work.'/restored-images','legacy_uploads'=>$work.'/restored-legacy'],$input);verify(array_values($rows)===array_values($restored),'restore_counts');
 foreach(array_keys($rows) as $t)verify($db->query('SELECT * FROM '.$t)->fetchAll()===$target->db->query('SELECT * FROM '.str_replace($prefix,$prefix.'r_',$t))->fetchAll(),'restore_'.$t);
 $step='permanent_empty';$real=$connect();foreach($tables as $t)verify((int)$real->query('SELECT COUNT(*) FROM '.$t)->fetchColumn()===0,'permanent_tables_empty');
 $step='config';$config=$root.'/private/database.php';verify(!file_exists($config),'config_not_overwritten');$f=fopen($config,'x');if(!$f)throw new RuntimeException('config_create');fwrite($f,"<?php\nreturn ".var_export(['dsn'=>$dsn,'db_user'=>$user,'db_password'=>$password],true).";\n");fclose($f);chmod($config,0600);verify((fileperms($config)&0777)===0600,'config_private');
 $step='cleanup';foreach($tables as $t)foreach([$prefix,$prefix.'r_'] as $pre)$db->exec('DROP TABLE '.str_replace('chronicle_',$pre,$t));
 verify(count($real->query('SHOW TABLES')->fetchAll())===11,'test_tables_removed');
 $r['success']=true;
}catch(Throwable $e){$r['success']=false;$r['failed_step']=$step;$r['error_class']=get_class($e);$r['error_code']=(string)$e->getCode();}
file_put_contents($out,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));chmod($out,0600);
