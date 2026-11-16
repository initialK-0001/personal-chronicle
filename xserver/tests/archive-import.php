<?php
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Legacy.php';require __DIR__.'/../src/LegacyEditor.php';require __DIR__.'/../src/ArchiveImport.php';
function check($x,$m){if(!$x)throw new RuntimeException($m);}
$s=new Chronicle\Store(new PDO('sqlite::memory:'));$s->initialize();$i=new Chronicle\ArchiveImport($s);
$e=['key'=>'x:owner:123','id'=>'123','time'=>'2010-01-02T03:04:05Z','body'=>'<script>危険</script> 本文','kind'=>'post','media'=>[['url'=>'javascript:alert(1)']]];
$private=$e;$private['key']='private';$private['visibility']='非公開';$draft=$e;$draft['key']='draft';$draft['raw']=['status'=>'draft'];
$blog=$e;$blog['key']='blog:ameblo:1';$blog['kind']='blog_post';$blog['blogUrl']='https://ameblo.jp/test';$blog['raw']=['bodyHtml'=>'<p>記事</p><script>bad()</script><img src="https://example.com/a.jpg">'];
$r=$i->run([$e,$private,$draft,$blog,$e]);check($r['added']===2&&$r['excluded']===2&&$r['duplicate']===1,'件数');$l=new Chronicle\Legacy($s,true);$records=$l->records();check(count($records)===2,'一括公開');check($records[0]['published_at']==='2010-01-02T03:04:05Z','元日時');check(!str_contains($l->page($records[0]),'<script>危険'),'本文escape');check(!str_contains($l->page($records[1]),'bad()'),'HTML無害化');check($i->run([$e,$blog])['duplicate']===2,'再実行重複なし');
$ed=new Chronicle\LegacyEditor($s,$l);$id=$records[0]['legacy_id'];$ed->change($id,0,'release',['title'=>'変更','html'=>'<p>新本文</p>']);check($l->posts()[0]['blocks'][0]['text']==='新本文','編集後に旧プレビューを使わない');$ed->change($id,1,'unpublish',null);check($l->find($records[0]['path'])===null,'非公開化');
try{$i->run([array_replace($e,['key'=>'new']),['key'=>'invalid']]);throw new RuntimeException('失敗を検出しない');}catch(Chronicle\Failure $ex){}check(count($ed->listing())===2,'途中失敗はロールバック');echo "一括公開・元日時・重複防止・非公開除外・編集・HTML無害化・ロールバック成功\n";
