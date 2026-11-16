<?php
declare(strict_types=1);
namespace Chronicle;
final class LegacyEditor {
    public function __construct(private Store $store,private Legacy $legacy){}
    public function original(int $id):array {
        $q=$this->store->db->prepare('SELECT original_json FROM chronicle_imports WHERE legacy_id=?');$q->execute([$id]);$raw=$q->fetchColumn();
        if($raw===false)throw new Failure('記事がありません',404);return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    public function one(int $id):array {
        $r=$this->original($id);$q=$this->store->db->prepare('SELECT * FROM chronicle_legacy_edits WHERE legacy_id=?');$q->execute([$id]);$e=$q->fetch();
        $public=($r['status']==='publish'&&($r['original_record']['post_password']??'')==='');
        return ['id'=>$id,'path'=>$r['path'],'revision'=>$e?(int)$e['revision']:0,'visibility'=>$e?$e['visibility']:($public?'public':'private'),
            'draft'=>$e?json_decode($e['draft_json'],true,512,JSON_THROW_ON_ERROR):['title'=>$r['title']??'','html'=>$r['original_html']],
            'postedAt'=>$r['published_at']??null,'updatedAt'=>$e['updated_at']??$r['updated_at']??null,
            'publishedAt'=>$e?$e['published_at']:(in_array($r['status'],['publish','private'],true)?($r['published_at']??null):null)];
    }
    public function listing():array {
        $out=[];$q=$this->store->db->query('SELECT i.legacy_id,i.original_json,e.draft_json,e.visibility,e.published_at,e.updated_at FROM chronicle_imports i LEFT JOIN chronicle_legacy_edits e ON e.legacy_id=i.legacy_id ORDER BY i.legacy_id DESC');
        while($row=$q->fetch()){$r=json_decode($row['original_json'],true,512,JSON_THROW_ON_ERROR);$draft=$row['draft_json']!==null?json_decode($row['draft_json'],true,512,JSON_THROW_ON_ERROR):null;$title=$draft['title']??$r['title'];if($title==='')$title=mb_substr(trim(strip_tags($draft['html']??$r['original_html'])),0,70);$out[]=['id'=>(int)$row['legacy_id'],'title'=>$title,'visibility'=>$row['visibility']??(($r['status']==='publish'&&($r['original_record']['post_password']??'')==='')?'public':'private'),'path'=>$r['path'],'firstPublishedAt'=>$r['published_at']??$row['published_at'],'publishedAt'=>$row['published_at']??$r['published_at']??null,'updatedAt'=>$row['updated_at']??$r['updated_at']??null];}return $out;
    }
    public function editView(int $id):array {$p=$this->one($id);$p['html']=$this->legacy->html($p['draft']['html']);$original=$this->original($id);$thumb=array_key_exists('thumbnail',$p['draft'])?$p['draft']['thumbnail']:($original['thumbnail']??null);if(!$thumb&&preg_match('/<img[^>]+src="([^"]+)"/',$p['html'],$m))$thumb=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');$p['thumbnailSrc']=$thumb?Legacy::localUrl($thumb):null;return $p;}
    public function change(int $id,int $revision,string $action,?array $content):array {
        if(!in_array($action,['draft','release','unpublish'],true))throw new Failure('操作を確認してください');
        if($content!==null){if(!is_string($content['title']??null)||strlen($content['title'])>2000||!is_string($content['html']??null)||strlen($content['html'])>900000)throw new Failure('本文を確認してください');if(array_key_exists('thumbnail',$content)&&$content['thumbnail']!==null){if(!is_string($content['thumbnail'])||!preg_match('~^/api/images/([a-f0-9]{64})$~D',$content['thumbnail'],$match))throw new Failure('サムネイル画像を選び直してください');$q=$this->store->db->prepare('SELECT hash FROM chronicle_assets WHERE hash=?');$q->execute([$match[1]]);if(!$q->fetchColumn())throw new Failure('サムネイル画像を確認できません');}$content=array_intersect_key($content,array_flip(['title','html','thumbnail']));}
        if($action==='draft'&&$content===null)throw new Failure('本文を指定してください');
        $baseline=$this->one($id);if($content!==null&&!array_key_exists('thumbnail',$content)&&array_key_exists('thumbnail',$baseline['draft']))$content['thumbnail']=$baseline['draft']['thumbnail'];$db=$this->store->db;$db->beginTransaction();
        try{
            $sql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
            $q=$db->prepare($sql.' INTO chronicle_legacy_edits(legacy_id,revision,draft_json,snapshot_json,visibility,published_at,updated_at) VALUES(?,0,?,NULL,?,?,NULL)');
            $q->execute([$id,Store::json($baseline['draft']),$baseline['visibility'],$baseline['publishedAt']]);
            $q=$db->prepare('UPDATE chronicle_legacy_edits SET revision=revision+1 WHERE legacy_id=? AND revision=?');$q->execute([$id,$revision]);if($q->rowCount()!==1)throw new Failure('別の端末で変更されています 入力を残して読み直してください',409);
            $q=$db->prepare('SELECT * FROM chronicle_legacy_edits WHERE legacy_id=?');$q->execute([$id]);$row=$q->fetch();$now=gmdate('Y-m-d\TH:i:s\Z');
            if($action==='unpublish'){$q=$db->prepare("UPDATE chronicle_legacy_edits SET visibility='private' WHERE legacy_id=?");$q->execute([$id]);}
            else{
                $p=$content??json_decode($row['draft_json'],true,512,JSON_THROW_ON_ERROR);
                // Keep the editable source intact; all browser output passes through the HTML allowlist.
                if($action==='release'){
                    $safe=$this->legacy->html($p['html']);if(trim(strip_tags($safe))===''&&!preg_match('/<(img|iframe)\b/',$safe))throw new Failure('本文を追加してください');
                    $q=$db->prepare("UPDATE chronicle_legacy_edits SET draft_json=?,snapshot_json=?,visibility='public',published_at=?,updated_at=? WHERE legacy_id=?");$q->execute([Store::json($p),Store::json($p),$row['published_at']??$now,$now,$id]);
                }else{$q=$db->prepare('UPDATE chronicle_legacy_edits SET draft_json=? WHERE legacy_id=?');$q->execute([Store::json($p),$id]);}
            }
            $q=$db->prepare('SELECT * FROM chronicle_legacy_edits WHERE legacy_id=?');$q->execute([$id]);$saved=$q->fetch();
            $q=$db->prepare('INSERT INTO chronicle_legacy_history(legacy_id,revision,action,state_json,saved_at) VALUES(?,?,?,?,?)');$q->execute([$id,$saved['revision'],$action,Store::json($saved),$now]);
            $db->commit();return $this->editView($id);
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
