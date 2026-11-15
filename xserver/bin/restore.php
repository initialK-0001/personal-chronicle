<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
if(count($argv)!==2)throw new RuntimeException('検証するバックアップディレクトリを指定してください');
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Backup.php';
[$store,$config]=chronicle_boot();if(($config['offline_restore']??false)!==true)throw new RuntimeException('復元専用の停止中環境を指定してください');
echo Chronicle\Store::json(['restored'=>Chronicle\Backup::restore($store,$config,$argv[1])])."\n";
