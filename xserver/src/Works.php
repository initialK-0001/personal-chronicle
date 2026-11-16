<?php
declare(strict_types=1);
namespace Chronicle;
final class Works {
    public function __construct(private Store $store) {}
    // Retained only for format-4 backup compatibility. New writes use chronicle_posts.
    public function initialize():void {
        $db=$this->store->db;$mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
        $db->exec('CREATE TABLE IF NOT EXISTS chronicle_works (id '.($mysql?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT').', parent_id BIGINT NULL, revision INTEGER NOT NULL, draft_json TEXT NOT NULL, snapshot_json TEXT NULL, visibility VARCHAR(10) NOT NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NULL)'.($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
    }
    public static function eligible(array $p):bool{return !in_array('ポーズ',$p['tags']??[],true);}
    public static function relation($value):?array {
        if($value===null)return null;
        if(!is_array($value)||!in_array($value['role']??null,['work','version','related'],true))throw new Failure('作品との関連を確認してください');
        $parent=$value['parent']??null;
        if($value['role']==='work'){if($parent!==null)throw new Failure('作品全体には親を指定できません');}
        elseif(!is_string($parent)||!preg_match('/^[1-9][0-9]*$/D',$parent))throw new Failure('関連する作品を選択してください');
        return ['role'=>$value['role'],'parent'=>$parent];
    }
    public function validate(array $p,?int $id=null):void {
        $r=$p['work']??null;if($r===null)return;
        if(!self::eligible($p))throw new Failure('ポーズは作品集の対象外です');
        if($r['role']==='work')return;
        if((string)$id===$r['parent'])throw new Failure('自分自身は指定できません');
        $row=$this->store->one((int)$r['parent']);$parent=json_decode($row['draft_json'],true,512,JSON_THROW_ON_ERROR);
        $role=$parent['work']['role']??null;
        if(!self::eligible($parent)||!in_array($role,$r['role']==='version'?['work']:['work','version'],true))throw new Failure('関連する作品を選び直してください');
    }
    public function published(array $posts):array {
        $roots=[];foreach($posts as $p)if(self::eligible($p)&&($p['work']['role']??null)==='work')$roots[(string)$p['id']]=$p;
        $out=[];foreach($posts as $p){$r=$p['work']??null;if(!$r||!self::eligible($p))continue;
            if($r['role']==='work'||($r['role']==='version'&&isset($roots[$r['parent']])))$out[]=['id'=>(string)$p['id'],'parent_id'=>$r['parent'],'title'=>$p['title'],'url'=>'/?post='.rawurlencode((string)$p['id'])];
        }return $out;
    }
    public function navigation(string $id,array $posts):string {
        $works=$this->published($posts);$byId=[];foreach($works as $w)$byId[$w['id']]=$w;
        $selected=null;foreach($posts as $p)if((string)$p['id']===$id)$selected=$p;
        if(!$selected)return '';
        $html='';$r=$selected['work']??null;if(!$r)return '';
        $label=fn($w)=>$w['title']?:($w['parent_id']===null?'作品紹介':'バージョン紹介');
        $link=fn($w)=>'<a href="'.Web::escape($w['url']).'">'.Web::escape($label($w)).'</a>';
        if($r['parent']!==null&&isset($byId[$r['parent']]))$html.='<p>作品集 '.$link($byId[$r['parent']]).'</p>';
        if(isset($byId[$id])){
            $versions=array_values(array_filter($works,fn($w)=>$w['parent_id']===$id));
            if($versions)$html.='<h2>バージョン</h2><ul>'.implode('',array_map(fn($w)=>'<li>'.$link($w).'</li>',$versions)).'</ul>';
            $parents=array_merge([$id],array_column($versions,'id'));
            $related=array_values(array_filter($posts,fn($p)=>self::eligible($p)&&($p['work']['role']??null)==='related'&&in_array($p['work']['parent'],$parents,true)));
            usort($related,fn($a,$b)=>strcmp($b['date']??'',$a['date']??''));
            if($related)$html.='<h2>関連投稿</h2><section class="feed timeline">'.implode('',array_map([Web::class,'card'],$related)).'</section>';
        }
        return $html===''?'':'<nav class="work-navigation" aria-label="作品との関連">'.$html.'</nav>';
    }
    public function page(array $posts,string $origin):string {
        $roots=array_filter($posts,fn($p)=>self::eligible($p)&&($p['work']['role']??null)==='work');
        usort($roots,fn($a,$b)=>strcmp($b['date']??'',$a['date']??''));
        $body='<h1>作品集</h1><section class="feed timeline">'.implode('',array_map(function($p){if($p['title']==='')$p['title']='作品紹介';return Web::card($p);},$roots)).'</section>';
        if(!$roots)$body.='<p>公開中の作品集はありません</p>';
        return '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>作品集 | Brand-new TOKYO</title><link rel="stylesheet" href="/style.css"><link rel="canonical" href="'.Web::escape($origin.'/works').'"></head><body><div class="masthead"><header><a class="brand" href="/">Brand-new <strong>TOKYO</strong></a></header></div><div class="layout"><aside class="sidebar"><nav class="browse-nav"><a href="/">すべて</a><a href="/?view=images">画像</a><a href="/?view=articles">文章</a><a href="/works">作品集</a></nav></aside><main>'.$body.'</main></div></body></html>';
    }
}
