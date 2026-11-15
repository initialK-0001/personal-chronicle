<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../src/bootstrap.php';
if(count($argv)!==2){fwrite(STDERR,"取り込む非公開JSONを指定してください\n");exit(1);}
[$store]=chronicle_boot();$data=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if(($data['schema_version']??0)!==1||!is_array($data['records']??null))throw new RuntimeException('移行形式が不正です');
echo Chronicle\Store::json($store->stageImport($data['records']))."\n";

foreach($data['downloads']??[] as $r){$json=Chronicle\Store::json($r);$q=$store->db->prepare('SELECT data_json FROM chronicle_downloads WHERE legacy_id=?');$q->execute([$r['id']]);$old=$q->fetchColumn();if($old!==false){if($old!==$json)throw new RuntimeException('配布データが変化しています');continue;}$q=$store->db->prepare('INSERT INTO chronicle_downloads VALUES(?,?)');$q->execute([$r['id'],$json]);}
