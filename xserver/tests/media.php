<?php
declare(strict_types=1);
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Media.php';
function check($v,string $m):void{if(!$v)throw new RuntimeException($m);}
$dir=sys_get_temp_dir().'/chronicle-media-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$s=new Chronicle\Store(new PDO('sqlite::memory:'));$s->initialize();$m=new Chronicle\Media($s,$dir);
try{
    $im=imagecreatetruecolor(90,60);
    $colors=[0xee2222,0x22dd22,0x2222ee,0xeeee22,0xee22ee,0x22eeee];
    foreach($colors as $i=>$c)imagefilledrectangle($im,($i%3)*30,intdiv($i,3)*30,($i%3)*30+29,intdiv($i,3)*30+29,$c);
    ob_start();imagejpeg($im,null,100);$jpeg=ob_get_clean();imagedestroy($im);
    $base=imagecreatefromstring($jpeg);$samples=[];
    for($i=0;$i<6;$i++)$samples[]=imagecolorat($base,($i%3)*30+15,intdiv($i,3)*30+15);
    imagedestroy($base);
    $orders=[1=>[0,1,2,3,4,5],2=>[2,1,0,5,4,3],3=>[5,4,3,2,1,0],4=>[3,4,5,0,1,2],5=>[0,3,1,4,2,5],6=>[3,0,4,1,5,2],7=>[5,2,4,1,3,0],8=>[2,5,1,4,0,3]];
    foreach(['little','big'] as $endian)foreach($orders as $orientation=>$order){
        // A real EXIF APP1 section with orientation and a private marker.
        $tiff=$endian==='little'?"II".pack('vVv',42,8,1).pack('vvVv',0x112,3,1,$orientation)."\0\0".pack('V',0):"MM".pack('nNn',42,8,1).pack('nnNn',0x112,3,1,$orientation)."\0\0".pack('N',0);
        $payload="Exif\0\0".$tiff.'PRIVATE-METADATA-TEST';
        $input=substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($payload)+2).$payload.substr($jpeg,2);
        $result=$m->upload($input);$bytes=file_get_contents($dir.'/'.basename($result['src']));
        check(!str_contains($bytes,'PRIVATE-METADATA-TEST')&&!str_contains($bytes,'Exif'),'メタデータ除去');
        $out=imagecreatefromstring($bytes);$cols=$orientation>=5?2:3;
        check(imagesx($out)===$cols*30&&imagesy($out)===(6/$cols)*30,"寸法 $orientation");
        foreach($order as $i=>$original)check(imagecolorat($out,($i%$cols)*30+15,intdiv($i,$cols)*30+15)===$samples[$original],"向き $orientation $endian $i");
        imagedestroy($out);
    }
    $plain=$m->upload($jpeg);check(is_file($dir.'/'.basename($plain['src'])),'EXIFなしJPEG');
    $transparent=imagecreatetruecolor(2,2);imagealphablending($transparent,false);imagesavealpha($transparent,true);imagefill($transparent,0,0,imagecolorallocatealpha($transparent,1,2,3,127));
    ob_start();imagepng($transparent);$png=ob_get_clean();imagedestroy($transparent);
    $p=$m->upload($png);$out=imagecreatefrompng($dir.'/'.basename($p['src']));check((imagecolorat($out,0,0)>>24)===127,'PNG透過維持');imagedestroy($out);
    $old=ini_get('memory_limit');ini_set('memory_limit',(string)(memory_get_usage(true)+8*1024*1024));
    try{$m->upload($jpeg);throw new RuntimeException('メモリ制限で拒否しなかった');}catch(Chronicle\Failure $e){check($e->status===413,'メモリ制限応答');}finally{ini_set('memory_limit',$old);}
    echo "JPEG全8方向×2バイト順 画素一致 メタデータ除去 PNG透過 メモリ上限を確認\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
