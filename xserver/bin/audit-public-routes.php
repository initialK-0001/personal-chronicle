<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Legacy.php';
if(count($argv)!==3)throw new RuntimeException('非公開の移行JSONと公開URL台帳を指定してください');
$data=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$inventory=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
$s=new Chronicle\Store(new PDO('sqlite::memory:'));$s->initialize();$s->stageImport($data['records']);$l=new Chronicle\Legacy($s,true);
$counts=['root'=>0,'article'=>0,'category'=>0];$missing=[];
foreach($inventory as $entry){$path=parse_url($entry['url'],PHP_URL_PATH)?:'/';if($path==='/')$counts['root']++;elseif($l->find($path))$counts['article']++;elseif($l->category($path))$counts['category']++;else $missing[]=$path;}
echo json_encode(['matched'=>$counts,'missing'=>$missing],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";if($missing)exit(1);
