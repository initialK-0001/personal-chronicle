<?php
declare(strict_types=1);
namespace Chronicle;
final class Media {
    private Store $store;private string $dir;
    public function __construct(Store $store,string $dir){$this->store=$store;$this->dir=$dir;if(!is_dir($dir))throw new Failure('画像保存先を確認してください',503);$root=realpath(($_SERVER['DOCUMENT_ROOT']??'')?:__DIR__.'/../public');if($root&&(realpath($dir)===$root||str_starts_with(realpath($dir),$root.'/')))throw new Failure('画像は公開領域外に保存してください',503);}
    public function upload(string $bytes):array {
        if(strlen($bytes)>8*1024*1024)throw new Failure('画像は8MBまでです',413);
        $info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)||$info[0]*$info[1]>16000000)throw new Failure('画像形式または画像サイズを確認してください');
        // Rotation can hold two decoded images at once; reject before GD exhausts PHP memory.
        $limit=ini_parse_quantity((string)ini_get('memory_limit'));
        if($limit>0&&memory_get_usage(true)+strlen($bytes)+$info[0]*$info[1]*12+16*1024*1024>$limit)throw new Failure('画像が大きすぎます サイズを小さくしてください',413);
        $orientation=1;
        if($info[2]===IMAGETYPE_JPEG){
            if(!function_exists('exif_read_data'))throw new Failure('画像処理の設定を確認してください',503);
            $stream=fopen('php://memory','w+b');
            if($stream===false)throw new Failure('画像を読み取れません',503);
            try{fwrite($stream,$bytes);rewind($stream);$exif=@exif_read_data($stream,'IFD0',true);$orientation=(int)($exif['IFD0']['Orientation']??1);}finally{fclose($stream);}
        }
        $im=@imagecreatefromstring($bytes);if(!$im)throw new Failure('画像を読み取れません');
        imagealphablending($im,false);imagesavealpha($im,true);
        $tmp=false;
        try{
            if($orientation===2)imageflip($im,IMG_FLIP_HORIZONTAL);
            elseif($orientation===4)imageflip($im,IMG_FLIP_VERTICAL);
            elseif(in_array($orientation,[3,5,6,7,8],true)){
                $rotated=imagerotate($im,$orientation===3?180:($orientation===8?90:-90),0);
                if($rotated===false)throw new Failure('画像の向きを補正できません',503);
                imagedestroy($im);$im=$rotated;
                if($orientation===5)imageflip($im,IMG_FLIP_HORIZONTAL);
                if($orientation===7)imageflip($im,IMG_FLIP_VERTICAL);
            }
            $tmp=tempnam($this->dir,'upload-');
            if($tmp===false)throw new Failure('画像を保存できません',503);
            if(!imagepng($im,$tmp,6))throw new Failure('画像を保存できません',503);$id=hash_file('sha256',$tmp);$size=filesize($tmp);chmod($tmp,0600);$dest=$this->dir.'/'.$id;
            if(!is_file($dest)&&!rename($tmp,$dest))throw new Failure('画像を保存できません',503);
            $sql=$this->store->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';$q=$this->store->db->prepare($sql.' INTO chronicle_assets(hash,mime,bytes) VALUES(?,?,?)');$q->execute([$id,'image/png',$size]);
            return ['src'=>'/api/images/'.$id,'bytes'=>$size];
        }finally{imagedestroy($im);if($tmp!==false&&is_file($tmp))unlink($tmp);}
    }
    public function serve(string $id,bool $public,bool $legacyReference=false):void{
        if(!preg_match('/^[a-f0-9]{64}$/D',$id))throw new Failure('画像がありません',404);
        if($public){$found=$legacyReference;foreach($this->store->publicPosts() as $p)foreach($p['blocks'] as $b)if(($b['src']??'')==='/api/public/images/'.$id)$found=true;if(!$found)throw new Failure('画像がありません',404);}
        $q=$this->store->db->prepare('SELECT mime FROM chronicle_assets WHERE hash=?');$q->execute([$id]);$mime=$q->fetchColumn();if(!$mime||!is_file($this->dir.'/'.$id))throw new Failure('画像がありません',404);
        header('Content-Type: '.$mime);header('Cache-Control: no-store');readfile($this->dir.'/'.$id);exit;
    }
}
