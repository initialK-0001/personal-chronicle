<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);ini_set('display_errors','0');set_time_limit(0);
$root=__DIR__;$out=$root.'/server-http-check.json';$lock=fopen($root.'/server-http-check.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||is_file($out))exit;
$r=['time'=>gmdate('c'),'success'=>false,'scope'=>'loopback HTTP only; not public HTTPS'];$process=null;$tmp=null;
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
try{
 check(function_exists('proc_open'),'process_unavailable');$cfg=json_decode(file_get_contents($root.'/private/config.json'),true,512,JSON_THROW_ON_ERROR);
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);check($socket!==false,'loopback_unavailable');$address=stream_socket_get_name($socket,false);fclose($socket);
 $tmp=$root.'/private/http-check-'.bin2hex(random_bytes(5));mkdir($tmp,0700);mkdir($tmp.'/sessions',0700);
 $cfg['origin']='http://'.$address;$cfg['local_test']=true;$cfg['legacy_public_enabled']=true;$cfg['public_indexing']=false;$cfg['sessions']=$tmp.'/sessions';$cfg['adsense']['enabled']=false;
 file_put_contents($tmp.'/config.json',json_encode($cfg,JSON_THROW_ON_ERROR));putenv('CHRONICLE_CONFIG='.$tmp.'/config.json');
 require $root.'/xserver/src/bootstrap.php';[$store]=chronicle_boot();$legacy=new Chronicle\Legacy($store,true);
 $paths=['/'];foreach($legacy->records() as $record)$paths[]=$record['path'];foreach($legacy->categories() as $category)$paths[]=$category['path'];$paths=array_values(array_unique($paths));check(count($paths)===207,'route_count');
 $cmd=[PHP_BINARY,'-S',$address,'-t',$root.'/xserver/public',$root.'/xserver/public/index.php'];
 $process=proc_open($cmd,[0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes,$root);check(is_resource($process),'server_start');fclose($pipes[0]);
 $ready=false;for($i=0;$i<30;$i++){$conn=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1);if($conn){fclose($conn);$ready=true;break;}usleep(100000);}check($ready,'server_ready');
 $get=function(string $path)use($address):array{$context=stream_context_create(['http'=>['method'=>'GET','ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]);$body=file_get_contents('http://'.$address.$path,false,$context);$headers=$http_response_header??[];preg_match('/HTTP\/\S+ (\d+)/',$headers[0]??'',$m);return [(int)($m[1]??0),implode("\n",$headers),$body];};
 $passed=0;foreach($paths as $path){[$status,$headers,$body]=$get(implode('/',array_map(fn($s)=>rawurlencode(rawurldecode($s)),explode('/',$path))));check($status===200&&is_string($body)&&str_contains(strtolower($headers),'text/html')&&str_contains(strtolower($headers),'noindex'),'public_route_'.substr(hash('sha256',$path),0,12));$passed++;}
 $r['routes_passed']=$passed;
 [$status]=$get('/admin');check($status===303,'admin_login_redirect');[$status]=$get('/api/admin/posts');check($status===401,'admin_api_denied');[$status]=$get('/sitemap.xml');check($status===404,'development_sitemap_disabled');
 $r['admin_anonymous_denied']=true;$r['development_noindex']=true;
 $privateChecks=0;foreach($store->db->query("SELECT original_json FROM chronicle_imports WHERE original_status IN ('private','draft')")->fetchAll(PDO::FETCH_COLUMN) as $json){$item=json_decode($json,true,512,JSON_THROW_ON_ERROR);if($item['path']==='/'||$legacy->find($item['path']))continue;[$status]=$get(implode('/',array_map(fn($s)=>rawurlencode(rawurldecode($s)),explode('/',$item['path']))));check($status===404,'private_route_denied');$privateChecks++;}
 $r['private_paths_denied']=$privateChecks;$r['success']=true;
}catch(Throwable $e){$r['error_class']=get_class($e);$r['error_code']=(string)$e->getCode();if($e instanceof RuntimeException&&!($e instanceof PDOException))$r['check']=$e->getMessage();}
finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}$r['server_stopped']=true;}
file_put_contents($out,json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($out,0600);
