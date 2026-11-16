<?php
declare(strict_types=1);
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Contact.php';require __DIR__.'/../src/Legacy.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
function fails(callable $f,$status){try{$f();throw new RuntimeException('拒否されない');}catch(Chronicle\Failure $e){check($e->status===$status,'応答');}}
$s=new Chronicle\Store(new PDO('sqlite::memory:'));$s->initialize();$c=new Chronicle\Contact($s,str_repeat('s',32),true);
fails(fn()=>(new Chronicle\Contact($s,'secret',false))->submit(['body'=>'本文'],'ip'),404);
fails(fn()=>$c->submit(['body'=>''],'ip'),400);fails(fn()=>$c->submit(['body'=>'本文','email'=>'bad'],'ip'),400);
$c->submit(['body'=>'<script>非公開</script>','status'=>'public','id'=>999],'ip');$row=$c->listing()[0];check((int)$row['id']===1&&$row['status']==='unread'&&$row['name']===''&&$row['email']==='','任意項目と入力無視');check($s->publicPosts()===[],'投稿公開経路と分離');
$c->change(1,1,'read');fails(fn()=>$c->change(1,1,'archived'),409);$c->change(1,2,'archived');check($c->listing()[0]['status']==='archived','保管');$c->change(1,3,'unread');check($c->listing()[0]['body']==='<script>非公開</script>','復帰と本文維持');fails(fn()=>$c->change(1,4,'approve'),400);
$c->submit(['body'=>'2'],'ip');$c->submit(['body'=>'3'],'ip');fails(fn()=>$c->submit(['body'=>'4'],'ip'),429);$c->submit(['body'=>'spam','website'=>'trap'],'other');check(count($c->listing())===3,'罠欄');
$l=new Chronicle\Legacy($s,true);$raw='<script src="https://map.yahooapis.jp/MapsService/embedmap/V2/?lat=35.781530648869825&amp;lon=140.3887109107193&amp;hlat=0&amp;hlon=0"></script>';$html=$l->html($raw);check(str_contains($html,'35.781530648869825%2C140.3887109107193'),'表示中心の座標を保持');check(!str_contains($html,'<script'),'スクリプト除去');check(!str_contains($l->html(str_replace('lat=35.781530648869825','lat=999',$raw)),'maps/search'),'不正座標拒否');check(!str_contains($l->html(str_replace('map.yahooapis.jp','evil.example',$raw)),'maps/search'),'偽ホスト非変換');
echo "問い合わせの非公開受付 任意項目 連投制限 既読保管復帰 競合と旧地図の座標保持を確認\n";
