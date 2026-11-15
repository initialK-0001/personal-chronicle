<?php
declare(strict_types=1);
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Legacy.php';require __DIR__.'/../src/Comments.php';
function check($v,string $m):void{if(!$v)throw new RuntimeException($m);}
function fails(callable $f,int $code):void{try{$f();throw new RuntimeException('操作を許可した');}catch(Chronicle\Failure $e){check($e->status===$code,'応答コード');}}
$s=new Chronicle\Store(new PDO('sqlite::memory:'));$s->initialize();$l=new Chronicle\Legacy($s,true);$c=new Chronicle\Comments($s,$l,str_repeat('x',32));
$p=$s->create(['title'=>'','tags'=>[],'blocks'=>[['type'=>'paragraph','text'=>'投稿']]]);
fails(fn()=>$c->submit(['post'=>'1','body'=>'非公開宛'],'ip'),404);$s->change(1,1,'release');
$c->submit(['post'=>'1','name'=>'','body'=>'<script>本文</script>','status'=>'approved','id'=>999,'email'=>'discard@example.test','url'=>'https://example.test'],'ip');
check($c->published('1')===[],'承認前は非公開');$a=$c->moderation()[0];check((int)$a['id']===1&&$a['name']===''&&$a['status']==='pending','匿名と自動採番');check(!isset($a['email'])&&!isset($a['url']),'個人情報は保管しない');
fails(fn()=>$c->change(1,1,'reply','返信'),409);check((int)$c->moderation()[0]['revision']===1,'返信拒否はロールバック');
$c->change(1,1,'approve');check(count($c->published('1'))===1,'承認');fails(fn()=>$c->change(1,1,'delete'),409);
$c->change(1,2,'reply','管理者返信');check($c->published('1')[0]['reply']==='管理者返信','返信保存');
$s->change(1,2,'unpublish');fails(fn()=>$c->published('1'),404);$s->change(1,3,'release');$c->change(1,3,'delete');check($c->published('1')===[],'削除後非公開');check($c->moderation()===[],'削除は管理一覧から除外');
for($i=0;$i<4;$i++)$c->submit(['post'=>'1','body'=>'追加'],'ip');fails(fn()=>$c->submit(['post'=>'1','body'=>'連投'],'ip'),429);
$before=count($c->moderation());$c->submit(['post'=>'1','body'=>'bot','website'=>'spam'],'other-ip');check(count($c->moderation())===$before,'スパム欄がある投稿は保存しない');
$r=['legacy_id'=>20,'path'=>'/old','status'=>'publish','original_record'=>['post_password'=>''],'original_html'=>'本文'];$s->stageImport([$r]);$c->submit(['post'=>'legacy-20','body'=>'旧記事へのコメント'],'another-ip');check($c->published('legacy-20')===[],'旧記事も承認前非公開');
echo "コメントの匿名受付・承認制・返信制限・競合・削除・連投制限・旧記事対応を検証成功\n";
