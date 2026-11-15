<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
if(count($argv)!==2)throw new RuntimeException('新しい非公開バックアップディレクトリを指定してください');
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Backup.php';
[$store,$config]=chronicle_boot();echo Chronicle\Store::json(['saved'=>Chronicle\Backup::export($store,$config,$argv[1])])."\n";
