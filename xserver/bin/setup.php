<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../src/bootstrap.php';
[$store]=chronicle_boot();$store->initialize();echo "データベースを準備しました\n";
