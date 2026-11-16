<?php
declare(strict_types=1);
namespace Chronicle;
require_once __DIR__.'/../vendor/autoload.php';
final class Legacy {
    private Store $store;private bool $enabled;private string $amazonTag;
    public function __construct(Store $store,bool $enabled,string $amazonTag=""){$this->store=$store;$this->enabled=$enabled;$this->amazonTag=preg_match("/^[A-Za-z0-9_-]{1,100}$/D",$amazonTag)?$amazonTag:"";}
    public function records():array{
        if(!$this->enabled)return [];
        $out=[];foreach($this->store->db->query("SELECT i.original_json,e.legacy_id AS edited,e.visibility,e.snapshot_json,e.published_at,e.updated_at FROM chronicle_imports i LEFT JOIN chronicle_legacy_edits e ON e.legacy_id=i.legacy_id")->fetchAll() as $row){
            $r=json_decode($row['original_json'],true,512,JSON_THROW_ON_ERROR);
            if($row['edited']!==null){if($row['visibility']!=='public')continue;if($row['snapshot_json']!==null){$p=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$r['title']=$p['title'];$r['original_html']=$p['html'];$r['published_at']=$row['published_at'];$r['updated_at']=$row['updated_at'];}}
            elseif($r['status']!=='publish'||($r['original_record']['post_password']??'')!=='')continue;
            $out[]=$r;
        }return $out;
    }
    public function categories():array {
        $out=[];foreach($this->records() as $r)foreach($r['terms']??[] as $t)if(($t['taxonomy']??'')==='category'){$slug=rawurldecode($t['slug']);$out[$slug]=['path'=>'/category/'.$slug,'name'=>$t['name']];}return array_values($out);
    }
    public function category(string $path):?array{foreach($this->categories() as $c)if(rtrim(rawurldecode($path),'/')===$c['path'])return $c;return null;}
    public function find(string $path):?array{foreach($this->records() as $r)if(rtrim(rawurldecode($r['path']),'/')===rtrim(rawurldecode($path),'/'))return $r;return null;}
    public static function localUrl(string $url):string {
        $u=parse_url($url);
        if($u===false||($u['host']??'')!=='brand-new.tokyo'||!in_array($u['scheme']??'https',['http','https'],true)||isset($u['port'])||isset($u['user'])||isset($u['pass']))return $url;
        return ($u['path']??'/').(isset($u['query'])?'?'.$u['query']:'').(isset($u['fragment'])?'#'.$u['fragment']:'');
    }
    public function html(string $raw):string{
        $raw=preg_replace_callback('~<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>.*?</script>~is',function($m){
            $u=parse_url(html_entity_decode($m[1],ENT_QUOTES,'UTF-8'));
            if(($u['scheme']??'')!=='https'||($u['host']??'')!=='map.yahooapis.jp'||($u['path']??'')!=='/MapsService/embedmap/V2/')return $m[0];
            parse_str($u['query']??'',$q);$lat=$q['lat']??null;$lon=$q['lon']??null;
            if(!is_string($lat)||!is_string($lon)||!preg_match('/^-?\d+(?:\.\d+)?$/D',$lat)||!preg_match('/^-?\d+(?:\.\d+)?$/D',$lon)||abs((float)$lat)>90||abs((float)$lon)>180)return '<p>地図の位置を確認できません</p>';
            return '<p><a href="https://www.google.com/maps/search/?api=1&amp;query='.rawurlencode($lat.','.$lon).'">地図を開く</a></p>';
        },$raw);
        // Normalize only the known YouTube embed host; repair old malformed attributes without executing source HTML.
        $raw=preg_replace_callback('~(<iframe\b[^>]*\bsrc=)(["\'])(.*?)\2~is',function($m){$url=html_entity_decode($m[3],ENT_QUOTES,'UTF-8');if(preg_match('~^(?:https?:)?//www\.youtube\.com/embed/([A-Za-z0-9_-]+)(?:[?]|$)~',$url,$v))$url='https://www.youtube.com/embed/'.$v[1];return $m[1].$m[2].htmlspecialchars($url,ENT_QUOTES,'UTF-8').$m[2];},$raw);
        $raw=preg_replace_callback('/\[blogcard\s+([^\]]+)\]/i',function($m){preg_match('/url=["\']([^"\']+)["\']/',$m[1],$a);return isset($a[1])?'<p><a href="'.htmlspecialchars($a[1],ENT_QUOTES).'">'.htmlspecialchars($a[1]).'</a></p>':htmlspecialchars($m[0]);},$raw);
        $raw=preg_replace_callback('/\[amazonjs\s+([^\]]+)\]/i',function($m){preg_match('/asin=["\']([A-Z0-9]{10})["\']/i',$m[1],$a);preg_match('/title=["\']([^"\']+)["\']/',$m[1],$t);return isset($a[1])?'<p><a href="https://www.amazon.co.jp/dp/'.$a[1].($this->amazonTag!==''?'?tag='.rawurlencode($this->amazonTag):'').'">'.htmlspecialchars($t[1]??'Amazonで商品を見る').'</a></p>':htmlspecialchars($m[0]);},$raw);
        $raw=preg_replace_callback('/\[wpdm_package\s+([^\]]+)\]/i',function($m){preg_match('/id=["\']?(\d+)/',$m[1],$a);return isset($a[1])?'<p><a href="/?wpdmdl='.$a[1].'">ファイルをダウンロード</a></p>':htmlspecialchars($m[0]);},$raw);
        $raw=preg_replace('/\[\/?caption\b[^\]]*\]/i','',$raw);
        $raw=preg_replace('/\[contact-form-7\b[^\]]*\]/i','<p><a href="/contact-form#contact">お問い合わせフォームへ</a></p>',$raw);
        $config=\HTMLPurifier_Config::createDefault();$config->set('Cache.DefinitionImpl',null);$config->set('HTML.Allowed','p,br,div,span,h1,h2,h3,h4,h5,h6,blockquote,pre,code,ul,ol,li,strong,b,em,i,u,s,del,table,thead,tbody,tr,th[colspan|rowspan],td[colspan|rowspan],a[href|title],img[src|alt|width|height],iframe[src|width|height|title|frameborder],hr,sub,sup');$config->set('HTML.SafeIframe',true);$config->set('URI.SafeIframeRegexp','~^https://(?:www\.youtube\.com/embed/[A-Za-z0-9_-]+(?:[?][^\s]*)?|www\.google\.com/maps/embed(?:[?][^\s]*)?)$~D');$config->set('URI.AllowedSchemes',['http'=>true,'https'=>true,'mailto'=>true]);
        $safe=preg_replace('/<iframe(?![^>]*\bsrc=)[^>]*>.*?<\/iframe>/s','',(new \HTMLPurifier($config))->purify($raw));
        return preg_replace_callback('~(<(?:a|img)\b[^>]*\b(?:href|src)=")(.*?)"~is',function($m){
            return $m[1].htmlspecialchars(self::localUrl(html_entity_decode($m[2],ENT_QUOTES,'UTF-8')),ENT_QUOTES,'UTF-8').'"';
        },$safe);
    }
    public function posts():array{
        $out=[];foreach($this->records() as $r){if($r['type']!=='post')continue;$html=str_replace('/api/images/','/api/public/images/',$this->html($r['original_html']));$blocks=[];
            $thumb=isset($r['thumbnail'])?self::localUrl($r['thumbnail']):null;
            if(!$thumb&&preg_match('/<img[^>]+src="([^"]+)"/',$html,$m))$thumb=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
            if($thumb)$blocks[]=['type'=>'image','src'=>$thumb,'alt'=>''];
            $blocks[]=['type'=>'paragraph','text'=>html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8')];
            $out[]=['id'=>'legacy-'.$r['legacy_id'],'url'=>$r['path'],'title'=>$r['title'],'tags'=>array_values(array_map(fn($t)=>$t['name'],$r['terms'])),'date'=>$r['published_at'],'updatedAt'=>$r['updated_at'],'blocks'=>$blocks];
        }return $out;
    }
    public function referencesImage(string $id):bool {
        foreach($this->records() as $r)if(str_contains($r['original_html'],$id)&&preg_match('~(?:src=["\'])(?:/api/images/|/api/public/images/)'.preg_quote($id,'~').'["\']~',$this->html($r['original_html'])))return true;return false;
    }
    public function media(string $path,string $uploads,bool $admin=false):void{
        $path=rawurldecode($path);$prefix='/wp-content/uploads/';$allowed=false;
        $records=$this->records();if($admin)$records=array_map(fn($row)=>json_decode($row['original_json'],true,512,JSON_THROW_ON_ERROR),$this->store->db->query('SELECT original_json FROM chronicle_imports')->fetchAll());
        foreach($records as $r){if(($r['thumbnail']??'')===$path)$allowed=true;
            if(preg_match('~'.preg_quote($path,'~').'(?=[?"\'<>\s,)\]])~u',html_entity_decode(rawurldecode($r['original_html']),ENT_QUOTES,'UTF-8')))$allowed=true;
        }
        $root=realpath($uploads);$file=$root?realpath($root.'/'.substr($path,strlen($prefix))):false;
        if(!$allowed||!$file||!$root||!str_starts_with($file,$root.'/')||!is_file($file))throw new Failure('画像がありません',404);
        $info=@getimagesize($file);if(!$info||!in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG,IMAGETYPE_GIF,IMAGETYPE_WEBP],true))throw new Failure('画像がありません',404);
        header('Content-Type: '.$info['mime']);readfile($file);exit;
    }
    public function download(int $id,string $uploads):void{
        $referenced=false;foreach($this->records() as $r)if(preg_match('/\[wpdm_package\s+[^\]]*id=["\']?'.preg_quote((string)$id,'/').'(?=["\'\s\]])/',$r['original_html']))$referenced=true;
        $q=$this->store->db->prepare('SELECT data_json FROM chronicle_downloads WHERE legacy_id=?');$q->execute([$id]);$raw=$q->fetchColumn();$d=$raw?json_decode($raw,true):null;
        if(!$referenced)foreach($this->records() as $r)if(preg_match('~[?]wpdmdl='.$id.'(?=[&\"\'<>\\s]|$)~',$r['original_html']))$referenced=true;
        if(!$referenced||!($d['public']??false)||count($d['files'])!==1)throw new Failure('ファイルがありません',404);
        $name=$d['files'][0];$root=realpath($uploads.'/download-manager-files');$file=$root?realpath($root.'/'.$name):false;
        if(!$root||!$file||!str_starts_with($file,$root.'/')||!is_file($file))throw new Failure('ファイルがありません',404);
        header('Content-Type: application/octet-stream');header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode(basename($file)));readfile($file);exit;
    }
    public function page(array $r):string{
        $e=fn($v)=>htmlspecialchars($v??'',ENT_QUOTES,'UTF-8');
        return '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($r['title']).' | Brand-new TOKYO</title><link rel="stylesheet" href="/style.css"></head><body><div class="masthead"><header><a class="brand" href="/">Brand-new TOKYO</a></header></div><main class="legacy-article"><h1>'.$e($r['title']).'</h1><time>'.$e(substr($r['published_at']??'',0,10)).'</time><div class="rendered">'.str_replace('/api/images/','/api/public/images/',$this->html($r['original_html'])).'</div><div data-comment-post="legacy-'.(int)$r['legacy_id'].'"></div><a href="/">一覧へ戻る</a></main><script type="module" src="/comments.js"></script></body></html>';
    }
}
