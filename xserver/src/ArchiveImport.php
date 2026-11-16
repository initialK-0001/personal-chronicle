<?php
declare(strict_types=1);
namespace Chronicle;
/** Explicit, operator-authorized archive import. Never called by public HTTP. */
final class ArchiveImport {
    public function __construct(private Store $store){}
    private static function url(mixed $v):?string {
        if(!is_string($v)||!preg_match('~^https?://~i',$v))return null;
        $u=parse_url($v);return $u&&isset($u['host'])&&!isset($u['user'])&&!isset($u['pass'])?$v:null;
    }
    public function run(array $entries):array {
        $report=['added'=>0,'duplicate'=>0,'excluded'=>0,'sources'=>[],'remoteImagePosts'=>0];
        $db=$this->store->db;$legacy=new Legacy($this->store,true);$seen=[];
        $existing=[];foreach($db->query('SELECT legacy_id,original_json FROM chronicle_imports') as $row){$r=json_decode($row['original_json'],true,512,JSON_THROW_ON_ERROR);$existing[(string)$row['legacy_id']]=$r['archive_key']??'wordpress';}
        $insert=$db->prepare('INSERT INTO chronicle_imports(legacy_id,legacy_path,original_status,original_json,sha256) VALUES(?,?,?,?,?)');
        $db->beginTransaction();
        try{foreach($entries as $e){
            if(!is_array($e)||!is_string($e['key']??null)||!is_string($e['time']??null)||!is_string($e['body']??null))throw new Failure('索引の形式を確認してください');
            $key=$e['key'];if(isset($seen[$key])){$report['duplicate']++;continue;}$seen[$key]=true;
            $visibility=$e['visibility']??null;$status=$e['raw']['status']??null;
            if(($visibility!==null&&!in_array($visibility,['公開','public','publish'],true))||($status!==null&&!in_array(strtolower((string)$status),['publish','published','public','1','公開'],true))){$report['excluded']++;continue;}
            $isWp=($e['blogUrl']??null)==='https://brand-new.tokyo';
            if($isWp&&isset($existing[(string)$e['id']])){$report['duplicate']++;continue;}
            $id=1000000000000+(int)hexdec(substr(hash('sha256',$key),0,12));
            if(isset($existing[(string)$id])){if($existing[(string)$id]!==$key)throw new Failure('取り込みIDが衝突しました',409);$report['duplicate']++;continue;}
            $time=(new \DateTimeImmutable($e['time']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            $kind=$e['kind']??'';
            $source=str_starts_with($key,'x:')?'X':(str_starts_with($key,'hatena:')?'はてなブックマーク':($kind==='mixi_diary'?'mixi':(str_contains($e['blogUrl']??'','ameblo.jp')?'アメブロ':($isWp?'WordPress':'Seesaa'))));
            $url=self::url($e['url']??null);
            if($source==='X')$url='https://x.com/i/web/status/'.rawurlencode((string)$e['id']);
            if($source==='Seesaa'&&!$url)$url=self::url(rtrim($e['blogUrl']??'','/').'/article/'.rawurlencode((string)$e['id']));
            $esc=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $html=in_array($kind,['blog_post'],true)&&isset($e['raw']['bodyHtml'])?$e['raw']['bodyHtml']:'<p>'.nl2br($esc($e['body']),false).'</p>';
            foreach($e['media']??[] as $m){$image=self::url($m['url']??null);if($image)$html.='<p><img src="'.$esc($image).'" alt=""></p>';}
            if($url)$html.='<p><a href="'.$esc($url).'">'.($source==='はてなブックマーク'?'ブックマーク先を開く':$esc($source).'の元投稿を開く').'</a></p>';
            $safe=$kind==='blog_post'?$legacy->html($html):$html;$thumb=null;if(preg_match('/<img[^>]+src="([^"]+)"/',$safe,$m))$thumb=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
            $tags=[$source];foreach(array_merge($e['tags']??[],$e['categories']??[]) as $tag)if(is_string($tag)&&$tag!=='')$tags[]=$tag;
            $terms=array_map(fn($t)=>['taxonomy'=>'post_tag','name'=>$t,'slug'=>rawurlencode($t)],array_values(array_unique($tags)));
            $text=html_entity_decode(strip_tags(preg_replace('~</(?:p|div|li|h[1-6])>|<br\s*/?>~i',' ',$safe)),ENT_QUOTES,'UTF-8');
            $r=['legacy_id'=>$id,'path'=>'/archive/'.substr(hash('sha256',$key),0,24),'status'=>'publish','type'=>'post','title'=>(string)($e['title']??''),'terms'=>$terms,'original_html'=>$html,'published_at'=>$time,'updated_at'=>$time,'original_record'=>[],'archive_key'=>$key,'archive_source'=>$source,'archive_preview'=>['text'=>$text,'thumbnail'=>$thumb]];
            $data=Store::json($r);$insert->execute([$id,$r['path'],'publish',$data,hash('sha256',$data)]);$existing[(string)$id]=$key;$report['added']++;$report['sources'][$source]=($report['sources'][$source]??0)+1;if($thumb)$report['remoteImagePosts']++;
        }$db->commit();return $report;}catch(\Throwable $e){$db->rollBack();throw $e;}
    }
}
