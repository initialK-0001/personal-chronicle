<?php
declare(strict_types=1);
// Read-only toward the live site. All generated files remain outside public_html.
if(PHP_SAPI!=='cli')exit(1);umask(0077);ini_set('display_errors','0');
$root=__DIR__;$live='/home/may231754/brand-new.tokyo/public_html';$private=$root.'/private';$out=$root.'/cutover-preparation.json';
$lock=fopen($root.'/cutover-preparation.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||is_file($out))exit;
$r=['time'=>gmdate('c'),'success'=>false,'live_modified'=>false];$step='paths';
function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
try{
 $dest=$private.'/cutover';ok(!file_exists($dest),'destination_exists');ok(mkdir($dest,0700),'mkdir');ok(mkdir($dest.'/rollback',0700),'rollback_mkdir');ok(mkdir($dest.'/candidate',0700),'candidate_mkdir');
 $r['originals']=[];foreach(['index.php','.htaccess','.user.ini'] as $name){$src=$live.'/'.$name;ok(is_file($src)&&!is_link($src),'source_missing');$hash=hash_file('sha256',$src);ok(copy($src,$dest.'/rollback/'.$name),'copy');chmod($dest.'/rollback/'.$name,0600);ok(hash_equals($hash,hash_file('sha256',$dest.'/rollback/'.$name)),'copy_hash');$r['originals'][$name]=['sha256'=>$hash,'bytes'=>filesize($src),'mode'=>decoct(fileperms($src)&0777)];}
 $step='inspect';$ht=file_get_contents($live.'/.htaccess');$ini=file_get_contents($live.'/.user.ini');
 $r['php_handler_lines']=[];foreach(explode("\n",$ht) as $line)if(preg_match('/^\s*(AddHandler|AddType|SetHandler|FcgidWrapper|Action)\s/i',$line))$r['php_handler_lines'][]=trim($line);
 $r['cache_environment_lines']=[];foreach(explode("\n",$ht) as $line)if(preg_match('/^\s*SetEnvIf\s+Request_URI\s+.*Ngx_Cache_/',$line))$r['cache_environment_lines'][]=trim($line);
 $r['user_ini_auto_prepend']=preg_match('/^\s*auto_prepend_file\s*=\s*[^;\r\n]+/m',$ini)===1;
 $r['user_ini_auto_append']=preg_match('/^\s*auto_append_file\s*=\s*[^;\r\n]+/m',$ini)===1;
 $r['legacy_redirect_directives']=preg_match_all('/^\s*(?:Redirect(?:Match)?\s|RewriteRule\s.*\[.*R(?:=|,|\]))/mi',$ht);
 $step='configuration';putenv('CHRONICLE_CONFIG='.$private.'/config.json');require $root.'/xserver/src/bootstrap.php';[$s,$config]=chronicle_boot();
 ok(($config['legacy_public_enabled']??null)===false&&($config['public_indexing']??null)===false,'publication_state');ok($s->db->query('SELECT DATABASE()')->fetchColumn()==='may231754_chronicle','database');
 $r['records']=(int)$s->db->query('SELECT COUNT(*) FROM chronicle_imports')->fetchColumn();ok($r['records']===388,'record_count');
 $step='candidate';$entry="<?php\ndeclare(strict_types=1);\n// Keep the existing site available until the approved PHP switch.\nif (PHP_VERSION_ID < 80300) {\n    define('WP_USE_THEMES', true);\n    require ".var_export($live.'/wp-blog-header.php',true).";\n    exit;\n}\nputenv(".var_export('CHRONICLE_CONFIG='.$private.'/config.json',true).");\nrequire ".var_export($root.'/xserver/public/index.php',true).";\n";
 file_put_contents($dest.'/candidate/index.php',$entry);chmod($dest.'/candidate/index.php',0600);
 // Base only. Server-managed PHP handler must be reconciled after inspecting the report.
 file_put_contents($dest.'/candidate/htaccess-base.txt',"Options -Indexes\nRewriteEngine On\nRewriteRule ^ index.php [END]\n");
 $r['candidate_entry_sha256']=hash('sha256',$entry);$r['htaccess_ready']=false;
 foreach($r['originals'] as $name=>$info)ok(hash_equals($info['sha256'],hash_file('sha256',$live.'/'.$name)),'live_changed_during_preparation');
 file_put_contents($dest.'/originals.json',json_encode($r['originals'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 $r['success']=true;
}catch(Throwable $e){$r['step']=$step;$r['error_class']=get_class($e);$r['error_code']=(string)$e->getCode();if($e instanceof RuntimeException&&!($e instanceof PDOException))$r['check']=$e->getMessage();}
file_put_contents($out,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));chmod($out,0600);
