<?php
declare(strict_types=1);
// CLI only; place beside xserver/. Read administrator password locally, never output it.
if(PHP_SAPI!=='cli')exit(1);umask(0077);ini_set('display_errors','0');
$root=__DIR__;$private=$root.'/private';$out=$root.'/configuration-result.json';$lock=fopen($root.'/configuration.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||is_file($out))exit;
$r=['time'=>gmdate('c'),'success'=>false];$stage='input';
try{
 $source=$private.'/admin-password.txt';if(!is_file($source)||is_link($source)||(fileperms($source)&0777)!==0600)throw new RuntimeException('password_file');
 $password=rtrim(file_get_contents($source),"\r\n");if(strlen($password)<12||strlen($password)>72)throw new RuntimeException('password_length');
 if(is_file($private.'/config.json'))throw new RuntimeException('config_exists');
 $db=require $private.'/database.php';$config=$db+['origin'=>'https://brand-new.tokyo','password_hash'=>password_hash($password,PASSWORD_DEFAULT),'rate_secret'=>bin2hex(random_bytes(32)),'sessions'=>$private.'/sessions','images'=>$private.'/images','legacy_uploads'=>$private.'/legacy-uploads','legacy_public_enabled'=>false,'public_indexing'=>false,'adsense'=>['enabled'=>false,'account_settings_verified'=>false]];
 if(!password_verify($password,$config['password_hash']))throw new RuntimeException('hash_check');unset($password);
 $stage='configuration';$f=fopen($private.'/config.json','x');if(!$f)throw new RuntimeException('config_write');$json=json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(fwrite($f,$json)!==strlen($json))throw new RuntimeException('config_write');fclose($f);chmod($private.'/config.json',0600);
 putenv('CHRONICLE_CONFIG='.$private.'/config.json');require $root.'/xserver/src/bootstrap.php';[$store,$loaded]=chronicle_boot();
 if($store->db->query('SELECT DATABASE()')->fetchColumn()!=='may231754_chronicle')throw new RuntimeException('database_name');
 foreach(['sessions','images','legacy_uploads'] as $key)if(!is_dir($loaded[$key])||!is_writable($loaded[$key])||is_link($loaded[$key]))throw new RuntimeException('private_directory');
 $stage='credential_cleanup';if(!unlink($source))throw new RuntimeException('password_cleanup');
 $r+=['configuration_private'=>(fileperms($private.'/config.json')&0777)===0600,'plaintext_admin_password_removed'=>true,'database_connected'=>true,'publication_enabled'=>false,'ads_enabled'=>false];$r['success']=true;
}catch(Throwable $e){$r['stage']=$stage;$r['error_class']=get_class($e);$r['error_code']=(string)$e->getCode();if($e instanceof RuntimeException&&!($e instanceof PDOException))$r['check']=$e->getMessage();}
file_put_contents($out,json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($out,0600);
