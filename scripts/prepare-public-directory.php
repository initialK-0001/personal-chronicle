<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);ini_set('display_errors','0');
$root=__DIR__;$private=$root.'/private';$live='/home/may231754/brand-new.tokyo/public_html';$out=$root.'/public-directory-preparation.json';
$lock=fopen($root.'/public-directory-preparation.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||is_file($out))exit;
$r=['time'=>gmdate('c'),'success'=>false,'live_modified'=>false];
try{
 $base=$private.'/cutover';$manifest=json_decode(file_get_contents($base.'/originals.json'),true,512,JSON_THROW_ON_ERROR);
 foreach($manifest as $name=>$info)if(!hash_equals($info['sha256'],hash_file('sha256',$live.'/'.$name)))throw new RuntimeException('live_changed');
 $ini=parse_ini_file($live.'/.user.ini',false,INI_SCANNER_RAW);if(!is_array($ini))throw new RuntimeException('ini_parse');
 $r['auto_prepend_nonempty']=trim($ini['auto_prepend_file']??'')!=='';$r['auto_append_nonempty']=trim($ini['auto_append_file']??'')!=='';
 $r['redirect_lines']=[];foreach(explode("\n",file_get_contents($live.'/.htaccess')) as $line)if(preg_match('/^\s*(?:Redirect(?:Match)?\s|RewriteRule\s.*\[.*R(?:=|,|\]))/i',$line))$r['redirect_lines'][]=trim($line);
 $dest=$base.'/public-next';if(file_exists($dest)||!mkdir($dest,0700))throw new RuntimeException('candidate_exists');
 $entry="<?php\ndeclare(strict_types=1);\nif (PHP_VERSION_ID < 80300) { http_response_code(503); header('Retry-After: 60'); exit('メンテナンス中'); }\nputenv(".var_export('CHRONICLE_CONFIG='.$private.'/config.json',true).");\nrequire ".var_export($root.'/xserver/public/index.php',true).";\n";
 $ht="Options -Indexes\nRewriteEngine On\nRewriteCond %{HTTPS} !=on [OR]\nRewriteCond %{HTTP_HOST} !^brand-new\\.tokyo$ [NC]\nRewriteRule ^ https://brand-new.tokyo%{REQUEST_URI} [R=301,L]\nRewriteRule ^\\.well-known/acme-challenge/ - [END]\nRewriteRule ^ index.php [END]\n";
 $newini="display_errors = Off\nlog_errors = On\nauto_prepend_file =\nauto_append_file =\n";
 foreach(['memory_limit','upload_max_filesize','post_max_size','max_execution_time','max_input_time','max_file_uploads'] as $key)if(isset($ini[$key])&&preg_match('/^-?[0-9]+[KMG]?$/i',trim($ini[$key])))$newini.=$key.' = '.trim($ini[$key])."\n";
 foreach(['index.php'=>$entry,'.htaccess'=>$ht,'.user.ini'=>$newini] as $name=>$data){if(file_put_contents($dest.'/'.$name,$data)!==strlen($data))throw new RuntimeException('write');chmod($dest.'/'.$name,0644);$r['candidate_hashes'][$name]=hash('sha256',$data);}
 if(!mkdir($dest.'/.well-known',0755)||!mkdir($dest.'/.well-known/acme-challenge',0755))throw new RuntimeException('acme_directory');
 $r['candidate_path']=$dest;$r['candidate_publicly_accessible']=false;$r['success']=true;
}catch(Throwable $e){$r['error_class']=get_class($e);$r['error_code']=(string)$e->getCode();if($e instanceof RuntimeException)$r['check']=$e->getMessage();}
file_put_contents($out,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));chmod($out,0600);
