<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../src/bootstrap.php';
if(count($argv)!==2){fwrite(STDERR,"取り込む非公開JSONを指定してください\n");exit(1);}
[$store]=chronicle_boot();$data=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if(($data['schema_version']??0)!==1||!is_array($data['records']??null))throw new RuntimeException('移行形式が不正です');
echo Chronicle\Store::json($store->stageImport($data['records']))."\n";
