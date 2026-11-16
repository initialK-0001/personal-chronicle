<?php
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Web.php';
use Chronicle\Store;use Chronicle\Works;use Chronicle\Failure;
function check($x){if(!$x)throw new Exception('作品集検証失敗');}
$s=new Store(new PDO('sqlite::memory:'));$s->initialize();$w=new Works($s);
function doc(string $title,?array $work=null):array{return ['title'=>$title,'tags'=>[],'blocks'=>[['type'=>'paragraph','text'=>'紹介 <script>alert(1)</script>']],'work'=>$work];}
$a=$s->create(doc('Karen',['role'=>'work','parent'=>null]));$aid=(int)$a['id'];
$v=$s->create(doc('初版',['role'=>'version','parent'=>(string)$aid]));$vid=(int)$v['id'];
$r=$s->create(doc('撮影',['role'=>'related','parent'=>(string)$vid]));$rid=(int)$r['id'];
check($w->published($s->publicPosts())===[]);
$s->change($vid,1,'release');check(count($s->publicPosts())===1&&$s->publicPosts()[0]['work']===null);check($w->published($s->publicPosts())===[]);
$s->change($aid,1,'release');$s->change($rid,1,'release');$posts=$s->publicPosts();check(count($posts)===3&&count($w->published($posts))===2);
$html=$w->page($posts,'https://example.test');check(str_contains($html,'/?post='.$aid)&&!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'));
check(str_contains($w->navigation((string)$aid,$posts),'初版')&&str_contains($w->navigation((string)$aid,$posts),'撮影'));
$s->change($aid,2,'draft',doc('未公開の改稿',['role'=>'work','parent'=>null]));check(!str_contains($w->page($s->publicPosts(),'https://example.test'),'未公開'));
try{$s->change($aid,2,'release');throw new Exception('競合検出なし');}catch(Failure $e){check($e->status===409);}
$s->change($aid,3,'unpublish');$posts=$s->publicPosts();check(count($posts)===2&&$w->published($posts)===[]);foreach($posts as $p)check($p['work']===null);
$s->change($aid,4,'release');check(count($s->publicPosts())===3&&count($w->published($s->publicPosts()))===2);
try{$s->create(doc('孫',['role'=>'version','parent'=>(string)$vid]));throw new Exception('孫を許可');}catch(Failure $e){check($e->status===400);}
try{$s->change($aid,5,'draft',doc('循環',['role'=>'version','parent'=>(string)$aid]));throw new Exception('自己参照を許可');}catch(Failure $e){check($e->status===400);}
$s->change($aid,5,'release',doc('通常の記事'));check(count($s->publicPosts())===3&&$w->published($s->publicPosts())===[]);
$pose=doc('ポーズ',['role'=>'work','parent'=>null]);$pose['tags']=['ポーズ'];try{$s->create($pose);throw new Exception('ポーズを作品に登録');}catch(Failure $e){check($e->status===400);}
$pose['work']=null;$s->create($pose); // Normal pose posts remain allowed.
check((int)$s->db->query('SELECT COUNT(*) FROM chronicle_works')->fetchColumn()===0);
echo "紹介は通常投稿として一度だけ保存 タイムライン・作品集同一URL 公開分離 親非公開 競合 ポーズ除外を確認\n";
