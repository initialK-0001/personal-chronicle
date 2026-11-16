<?php
declare(strict_types=1);
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Legacy.php';require __DIR__.'/../src/LegacyEditor.php';require __DIR__.'/../src/Web.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
function fails(callable $f,int $code){try{$f();throw new RuntimeException('拒否されない');}catch(Chronicle\Failure $e){check($e->status===$code,'応答コード');}}
$s=new Chronicle\Store(new PDO('sqlite::memory:'));$s->initialize();
$r=['legacy_id'=>10,'path'=>'/same-url','status'=>'publish','type'=>'post','title'=>'元記事','terms'=>[],'original_html'=>'<p>元本文</p>[wpdm_package id="7"]','published_at'=>'2022-01-01T00:00:00Z','updated_at'=>'2022-01-02T00:00:00Z','original_record'=>['post_password'=>'']];$private=$r;$private['legacy_id']=11;$private['path']='/private';$private['status']='private';$protected=$r;$protected['legacy_id']=12;$protected['path']='/protected';$protected['original_record']['post_password']='test-only';$s->stageImport([$r,$private,$protected]);
$legacy=new Chronicle\Legacy($s,true);$e=new Chronicle\LegacyEditor($s,$legacy);$before=$s->db->query('SELECT * FROM chronicle_imports ORDER BY legacy_id')->fetchAll();
check(count($e->listing())===3,'管理一覧は非公開も含む');check(count($legacy->records())===1,'初期非公開を隠す');check($e->one(10)['revision']===0,'閲覧は保存しない');
check($e->listing()[2]['publishedAt']===$r['published_at'],'一覧の投稿日時');check($e->listing()[2]['updatedAt']===$r['updated_at'],'一覧の元更新日時');
$content=['title'=>'新タイトル','html'=>'<p>未公開の改稿</p><script>alert(1)</script><img src="/api/images/'.str_repeat('a',64).'">'];$e->change(10,0,'draft',$content);
check($legacy->find('/same-url')['title']==='元記事','下書きは公開しない');check(!$legacy->referencesImage(str_repeat('a',64)),'下書きの画像は非公開');
$second=new Chronicle\LegacyEditor($s,$legacy);check($second->one(10)['draft']===$content,'別セッション復元');fails(fn()=>$e->change(10,0,'release',null),409);
$e->change(10,1,'release',null);$published=$legacy->find('/same-url');check($published['title']==='新タイトル','同じURLで更新');check($published['published_at']===$r['published_at'],'元公開日時保持');check(!str_contains($legacy->page($published),'<script>alert'),'表示時に無害化');check($legacy->referencesImage(str_repeat('a',64)),'公開画像参照');check(str_contains($legacy->page($published),'/api/public/images/'),'公開画像経路');
check($e->listing()[2]['updatedAt']===$published['updated_at'],'公開更新後の一覧日時');
$e->change(10,2,'unpublish',null);check($legacy->find('/same-url')===null,'旧URL非公開');check(!$legacy->referencesImage(str_repeat('a',64)),'画像公開参照解除');check(!str_contains(Chronicle\Web::sitemap('https://example.test',[],$legacy),'/same-url'),'サイトマップから除外');
$e->change(10,3,'release',null);check($legacy->find('/same-url')['published_at']===$r['published_at'],'再公開日時保持');
check((new Chronicle\Legacy($s,false))->records()===[],'移行公開スイッチを迂回しない');
$e->change(11,0,'draft',['title'=>'秘密','html'=>'秘密本文']);check($legacy->find('/private')===null,'旧非公開の下書きも非公開');$e->change(11,1,'release',null);check($legacy->find('/private')['title']==='秘密','明示操作のみ公開');
fails(fn()=>$e->change(999,0,'draft',$content),404);fails(fn()=>$e->change(10,4,'release',['title'=>'','html'=>'<script>bad()</script>']),400);check($e->one(10)['revision']===4,'拒否時ロールバック');
check($before===$s->db->query('SELECT * FROM chronicle_imports ORDER BY legacy_id')->fetchAll(),'元本文と元データは不変');check((int)$s->db->query('SELECT COUNT(*) FROM chronicle_legacy_history')->fetchColumn()===6,'変更履歴保持');
echo "旧記事の同一URL編集 公開分離 競合 非公開化 元日時 原文保全 HTML無害化を確認\n";

// Original publication is authoritative even when an edit has a later publication timestamp.
$s->db->exec("UPDATE chronicle_legacy_edits SET published_at='2026-11-16T00:00:00Z' WHERE legacy_id=10");
check($legacy->find('/same-url')['published_at']===$r['published_at'],'旧記事は初出投稿日を優先');
$listed=array_column($e->listing(),null,'id');check($listed[10]['firstPublishedAt']===$r['published_at'],'管理一覧も初出投稿日');
$timeline=array_column($legacy->posts(),null,'id');check($timeline['legacy-10']['date']===$r['published_at'],'タイムラインの並び替え用日時は初出');

$thumbHash=str_repeat('b',64);$thumb='/api/images/'.$thumbHash;
$s->db->prepare('INSERT INTO chronicle_assets(hash,mime,bytes) VALUES(?,?,?)')->execute([$thumbHash,'image/png',10]);
$rev=$e->one(10)['revision'];$e->change(10,$rev,'draft',['title'=>'サムネイル検証','html'=>'<p>本文</p>','thumbnail'=>$thumb]);
check(!$legacy->referencesImage($thumbHash),'下書きサムネイルは非公開');
$e->change(10,$rev+1,'release',null);check($legacy->referencesImage($thumbHash),'公開サムネイルだけの画像参照');
$cards=array_column($legacy->posts(),null,'id');check($cards['legacy-10']['blocks'][0]['src']==='/api/public/images/'.$thumbHash,'公開サムネイル経路');
$e->change(10,$rev+2,'draft',['title'=>'旧画面からの保存','html'=>'<p>本文更新</p>']);check($e->one(10)['draft']['thumbnail']===$thumb,'省略時は指定サムネイルを保持');
$e->change(10,$rev+3,'draft',['title'=>'自動','html'=>'<img src="/wp-content/uploads/first.jpg">','thumbnail'=>null]);check($legacy->referencesImage($thumbHash),'自動への下書き中は公開サムネイルを維持');
$e->change(10,$rev+4,'release',null);check(!$legacy->referencesImage($thumbHash),'公開解除済サムネイルを秘匿');$cards=array_column($legacy->posts(),null,'id');check($cards['legacy-10']['blocks'][0]['src']==='/wp-content/uploads/first.jpg','本文先頭画像へ変更');
fails(fn()=>$e->change(10,$rev+5,'draft',['title'=>'不正','html'=>'本文','thumbnail'=>'https://example.org/a.png']),400);
echo "サムネイルの下書き分離・公開参照・省略保持・本文先頭への切替を確認\n";
