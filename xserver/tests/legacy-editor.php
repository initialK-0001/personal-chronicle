<?php
declare(strict_types=1);
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Legacy.php';require __DIR__.'/../src/LegacyEditor.php';require __DIR__.'/../src/Web.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
function fails(callable $f,int $code){try{$f();throw new RuntimeException('拒否されない');}catch(Chronicle\Failure $e){check($e->status===$code,'応答コード');}}
$s=new Chronicle\Store(new PDO('sqlite::memory:'));$s->initialize();
$r=['legacy_id'=>10,'path'=>'/same-url','status'=>'publish','type'=>'post','title'=>'元記事','terms'=>[],'original_html'=>'<p>元本文</p>[wpdm_package id="7"]','published_at'=>'2022-01-01T00:00:00Z','updated_at'=>'2022-01-02T00:00:00Z','original_record'=>['post_password'=>'']];$private=$r;$private['legacy_id']=11;$private['path']='/private';$private['status']='private';$protected=$r;$protected['legacy_id']=12;$protected['path']='/protected';$protected['original_record']['post_password']='test-only';$s->stageImport([$r,$private,$protected]);
$legacy=new Chronicle\Legacy($s,true);$e=new Chronicle\LegacyEditor($s,$legacy);$before=$s->db->query('SELECT * FROM chronicle_imports ORDER BY legacy_id')->fetchAll();
check(count($e->listing())===3,'管理一覧は非公開も含む');check(count($legacy->records())===1,'初期非公開を隠す');check($e->one(10)['revision']===0,'閲覧は保存しない');
$content=['title'=>'新タイトル','html'=>'<p>未公開の改稿</p><script>alert(1)</script><img src="/api/images/'.str_repeat('a',64).'">'];$e->change(10,0,'draft',$content);
check($legacy->find('/same-url')['title']==='元記事','下書きは公開しない');check(!$legacy->referencesImage(str_repeat('a',64)),'下書きの画像は非公開');
$second=new Chronicle\LegacyEditor($s,$legacy);check($second->one(10)['draft']===$content,'別セッション復元');fails(fn()=>$e->change(10,0,'release',null),409);
$e->change(10,1,'release',null);$published=$legacy->find('/same-url');check($published['title']==='新タイトル','同じURLで更新');check($published['published_at']===$r['published_at'],'元公開日時保持');check(!str_contains($legacy->page($published),'<script>alert'),'表示時に無害化');check($legacy->referencesImage(str_repeat('a',64)),'公開画像参照');check(str_contains($legacy->page($published),'/api/public/images/'),'公開画像経路');
$e->change(10,2,'unpublish',null);check($legacy->find('/same-url')===null,'旧URL非公開');check(!$legacy->referencesImage(str_repeat('a',64)),'画像公開参照解除');check(!str_contains(Chronicle\Web::sitemap('https://example.test',[],$legacy),'/same-url'),'サイトマップから除外');
$e->change(10,3,'release',null);check($legacy->find('/same-url')['published_at']===$r['published_at'],'再公開日時保持');
check((new Chronicle\Legacy($s,false))->records()===[],'移行公開スイッチを迂回しない');
$e->change(11,0,'draft',['title'=>'秘密','html'=>'秘密本文']);check($legacy->find('/private')===null,'旧非公開の下書きも非公開');$e->change(11,1,'release',null);check($legacy->find('/private')['title']==='秘密','明示操作のみ公開');
fails(fn()=>$e->change(999,0,'draft',$content),404);fails(fn()=>$e->change(10,4,'release',['title'=>'','html'=>'<script>bad()</script>']),400);check($e->one(10)['revision']===4,'拒否時ロールバック');
check($before===$s->db->query('SELECT * FROM chronicle_imports ORDER BY legacy_id')->fetchAll(),'元本文と元データは不変');check((int)$s->db->query('SELECT COUNT(*) FROM chronicle_legacy_history')->fetchColumn()===6,'変更履歴保持');
echo "旧記事の同一URL編集 公開分離 競合 非公開化 元日時 原文保全 HTML無害化を確認\n";
