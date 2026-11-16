<?php
declare(strict_types=1);
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Media.php';
use Chronicle\Store;use Chronicle\Media;use Chronicle\Failure;
Media::prepareUpload();
$s=new Store(new PDO('sqlite::memory:'));$s->initialize();
$dir=sys_get_temp_dir().'/chronicle-image-limits-'.bin2hex(random_bytes(6));mkdir($dir,0700);$m=new Media($s,$dir);
function fixture(int $w,int $h,int $compression=6):string{$im=imagecreatetruecolor($w,$h);imagealphablending($im,false);imagesavealpha($im,true);ob_start();imagepng($im,null,$compression);$png=ob_get_clean();imagedestroy($im);return $png;}
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function rejected(Media $m,string $bytes,string $message):void{try{$m->upload($bytes);throw new RuntimeException('上限超過を許可');}catch(Failure $e){verify($e->status===413&&str_contains($e->getMessage(),$message),'上限エラー');}}
try{
 $large=fixture(2000,2000,0);verify(strlen($large)>15*1000*1000,'15MB超の実PNG');$m->upload($large);unset($large);
 $edge=fixture(4000,4000);$r=$m->upload($edge);$info=getimagesize($dir.'/'.basename($r['src']));verify($info[0]===4000&&$info[1]===4000,'境界の画素数を保持');unset($edge);
 rejected($m,fixture(4001,1),'4000');rejected($m,fixture(1,4001),'4000');
 $tiny=fixture(1,1);$max=$tiny.str_repeat("\0",Media::MAX_BYTES-strlen($tiny));$m->upload($max);rejected($m,$max.'x','30MB');unset($max);
 echo Store::json(['success'=>true,'large_png_15mb'=>true,'exact_30mb'=>true,'exact_4000_square'=>true,'over_limits_rejected'=>true,'memory_limit'=>ini_get('memory_limit'),'post_max_size'=>ini_get('post_max_size')])."\n";
}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
