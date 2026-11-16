<?php
declare(strict_types=1);
namespace Chronicle;
final class Works {
    public function __construct(private Store $store) {}
    public function initialize():void {
        $db=$this->store->db;$mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
        $db->exec('CREATE TABLE IF NOT EXISTS chronicle_works (id '.($mysql?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT').', parent_id BIGINT NULL, revision INTEGER NOT NULL, draft_json TEXT NOT NULL, snapshot_json TEXT NULL, visibility VARCHAR(10) NOT NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NULL)'.($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
    }
    public function rows():array{return $this->store->db->query('SELECT * FROM chronicle_works ORDER BY id DESC')->fetchAll();}
    public function one(int $id):array{foreach($this->rows() as $r)if((int)$r['id']===$id)return $r;throw new Failure('作品がありません',404);}
    private function content(array $p):array {
        foreach(['title'=>500,'description'=>45000] as $key=>$max)if(!is_string($p[$key]??null)||strlen($p[$key])>$max)throw new Failure('入力内容を確認してください');
        if(trim($p['title'])==='')throw new Failure('名前を入力してください');
        if(!is_array($p['posts']??null)||count($p['posts'])>200)throw new Failure('関連投稿を確認してください');
        foreach($p['posts'] as $id)if(!is_string($id)||!preg_match('/^(?:legacy-)?[1-9][0-9]*$/D',$id))throw new Failure('投稿を確認してください');
        $out=['title'=>trim($p['title']),'description'=>$p['description'],'posts'=>array_values(array_unique($p['posts']))];
        if(strlen(Store::json($out))>60000)throw new Failure('説明が長すぎます');
        return $out;
    }
    public function create(?int $parent,array $p):array {
        $p=$this->content($p);if($parent!==null&&$this->one($parent)['parent_id']!==null)throw new Failure('作品全体を選択してください');
        $q=$this->store->db->prepare("INSERT INTO chronicle_works(parent_id,revision,draft_json,visibility,created_at) VALUES(?,1,?,'private',?)");$q->execute([$parent,Store::json($p),gmdate('c')]);return $this->one((int)$this->store->db->lastInsertId());
    }
    public function change(int $id,int $revision,string $action,?array $p):array {
        if(!in_array($action,['draft','release','unpublish'],true))throw new Failure('操作を確認してください');
        $p=$p===null?null:$this->content($p);$db=$this->store->db;$db->beginTransaction();
        try{$q=$db->prepare('UPDATE chronicle_works SET revision=revision+1 WHERE id=? AND revision=?');$q->execute([$id,$revision]);if($q->rowCount()!==1)throw new Failure('別の端末で変更されています 入力を残して読み直してください',409);
            $r=$this->one($id);$p??=json_decode($r['draft_json'],true,512,JSON_THROW_ON_ERROR);$json=Store::json($p);
            if($action==='release'){$q=$db->prepare("UPDATE chronicle_works SET draft_json=?,snapshot_json=?,visibility='public',updated_at=? WHERE id=?");$q->execute([$json,$json,gmdate('c'),$id]);}
            elseif($action==='draft'){$q=$db->prepare('UPDATE chronicle_works SET draft_json=? WHERE id=?');$q->execute([$json,$id]);}
            else{$q=$db->prepare("UPDATE chronicle_works SET visibility='private' WHERE id=?");$q->execute([$id]);}
            $db->commit();return $this->one($id);
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function eligible(array $p):bool{return !preg_match('/ポーズ|pose/ui',($p['title']??'').' '.implode(' ',$p['tags']??[]));}
    public function published(array $posts):array {
        $available=[];foreach($posts as $p)if(self::eligible($p))$available[(string)$p['id']]=true;
        $rows=$this->rows();$parents=[];foreach($rows as $r)if($r['parent_id']===null&&$r['visibility']==='public')$parents[(int)$r['id']]=true;
        $out=[];foreach($rows as $r){if($r['visibility']!=='public'||($r['parent_id']!==null&&!isset($parents[(int)$r['parent_id']])))continue;
            $p=json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$p['posts']=array_values(array_filter($p['posts'],fn($id)=>isset($available[$id])));
            $out[]=['id'=>(int)$r['id'],'parent_id'=>$r['parent_id']===null?null:(int)$r['parent_id']]+$p;
        }return $out;
    }
    public function page(?int $id,array $posts,string $origin):string {
        $works=$this->published($posts);$selected=null;foreach($works as $w)if($w['id']===$id)$selected=$w;
        if($id!==null&&!$selected)throw new Failure('作品がありません',404);
        $e=fn($v)=>Web::escape((string)$v);$title=$selected['title']??'作品集';$body='<h1>'.$e($title).'</h1>';
        if($selected){if($selected['parent_id']!==null){foreach($works as $w)if($w['id']===$selected['parent_id'])$body='<a href="/works/'.$w['id'].'">'.$e($w['title']).'</a>'.$body;}
            $body.='<div class="rendered"><p style="white-space:pre-wrap">'.$e($selected['description']).'</p></div>';
            if($selected['parent_id']===null){$body.='<h2>バージョン</h2>';foreach($works as $w)if($w['parent_id']===$id)$body.='<p><a href="/works/'.$w['id'].'">'.$e($w['title']).'</a></p>';}
            $keys=$selected['posts'];if($selected['parent_id']===null)foreach($works as $w)if($w['parent_id']===$id)$keys=array_merge($keys,$w['posts']);
            $related=array_values(array_filter($posts,fn($p)=>in_array((string)$p['id'],$keys,true)));usort($related,fn($a,$b)=>strcmp($b['date']??'',$a['date']??''));
            if($related)$body.='<h2>関連投稿</h2><section class="feed timeline">'.implode('',array_map([Web::class,'card'],$related)).'</section>';
        }else{foreach($works as $w)if($w['parent_id']===null)$body.='<article class="timeline-post"><h2><a href="/works/'.$w['id'].'">'.$e($w['title']).'</a></h2><p style="white-space:pre-wrap">'.$e($w['description']).'</p></article>';if(!$works)$body.='<p>公開中の作品集はありません</p>';}
        return '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($title).' | Brand-new TOKYO</title><link rel="stylesheet" href="/style.css"><link rel="canonical" href="'.$e($origin.'/works'.($id===null?'':'/'.$id)).'"></head><body><div class="masthead"><header><a class="brand" href="/">Brand-new <strong>TOKYO</strong></a></header></div><div class="layout"><aside class="sidebar"><nav class="browse-nav"><a href="/">すべて</a><a href="/?view=images">画像</a><a href="/?view=articles">文章</a><a href="/works">作品集</a></nav></aside><main>'.$body.'</main></div></body></html>';
    }
}
