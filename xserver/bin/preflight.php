<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
if(PHP_VERSION_ID<80300)throw new RuntimeException('PHP 8.3以上が必要です');
foreach(['pdo','gd','dom','mbstring','exif'] as $ext)if(!extension_loaded($ext))throw new RuntimeException('PHP拡張が不足しています '.$ext);
require __DIR__.'/../src/bootstrap.php';
[$store,$config]=chronicle_boot();
foreach(['sessions','images'] as $key){$dir=$config[$key]??'';if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('保存先を確認してください '.$key);}
new Chronicle\Media($store,$config['images']);
$store->db->query('SELECT 1')->fetchColumn();
echo "PHP・拡張・設定・保存先・DB接続の確認が完了しました HTTP経由の検証は別途必要です\n";
