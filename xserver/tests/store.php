<?php
declare(strict_types=1);
require __DIR__.'/../src/Store.php';
use Chronicle\Store;use Chronicle\Failure;
function check($v,$message){if(!$v)throw new RuntimeException($message);}
$s=new Store(new PDO('sqlite::memory:'));$s->initialize();
$p=['title'=>'','tags'=>[],'blocks'=>[['type'=>'paragraph','text'=>'公開する本文']],'id'=>999,'date'=>'1900-01-01'];
$a=$s->create($p);check((int)$a['id']===1,'IDは自動採番');check($s->publicPosts()===[],'下書きが漏れない');
$a=$s->change(1,1,'release');$date=$a['published_at'];check($s->publicPosts()[0]['blocks'][0]['text']==='公開する本文','公開');
$p['blocks'][0]['text']='編集中';$a=$s->change(1,2,'draft',$p);check($s->publicPosts()[0]['blocks'][0]['text']==='公開する本文','下書き非反映');
try{$s->change(1,2,'release');throw new RuntimeException('競合を許可した');}catch(Failure $e){check($e->status===409,'競合拒否');}
check((int)$s->one(1)['revision']===3,'失敗はロールバック');$a=$s->change(1,3,'unpublish');check($s->publicPosts()===[],'非公開');check(str_contains($a['draft_json'],'編集中'),'下書き維持');
$a=$s->change(1,4,'release');check($a['published_at']===$date,'初回日時維持');check($s->publicPosts()[0]['blocks'][0]['text']==='編集中','再公開');
$r=['legacy_id'=>20,'path'=>'/old','status'=>'publish','original_html'=>'<script>原文を保持</script>'];check($s->stageImport([$r])['published']===0,'移行は自動公開しない');check($s->stageImport([$r])['unchanged']===1,'再実行の重複防止');$r['original_html']='変更';try{$s->stageImport([$r]);throw new RuntimeException('差分を上書きした');}catch(Failure $e){check($e->status===409,'移行差分拒否');}
check(count($s->publicPosts())===1,'旧原文非公開');echo "保存・公開分離・競合・移行の検証成功\n";
