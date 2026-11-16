<?php
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Web.php';
use Chronicle\Store;use Chronicle\Works;use Chronicle\Failure;
function check($x){if(!$x)throw new Exception('作品集検証失敗');}
$s=new Store(new PDO('sqlite::memory:'));$s->initialize();$w=new Works($s);
$p=['title'=>'Karen','description'=>'紹介 <script>alert(1)</script>','posts'=>['1','2','3']];
$a=$w->create(null,$p);$v=$w->create((int)$a['id'],['title'=>'初版','description'=>'初版の説明','posts'=>['1']]);
check($w->published([])===[]);
$w->change((int)$v['id'],1,'release',null);check($w->published([])===[]);
$w->change((int)$a['id'],1,'release',null);
$posts=[['id'=>'1','title'=>'写真','tags'=>[],'blocks'=>[],'date'=>null],['id'=>'3','title'=>'ポーズ集','tags'=>[],'blocks'=>[],'date'=>null]];
$public=$w->published($posts);check(count($public)===2&&$public[1]['posts']===['1']);
$w->change((int)$a['id'],2,'draft',['title'=>'秘密','description'=>'非公開の改稿','posts'=>[]]);
check($w->published($posts)[1]['title']==='Karen');
$html=$w->page((int)$a['id'],$posts,'https://example.test');check(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;')&&!str_contains($html,'秘密')&&!str_contains($html,'ポーズ集'));
try{$w->change((int)$a['id'],2,'release',null);throw new Exception('競合検出なし');}catch(Failure $e){check($e->status===409);}
$w->change((int)$a['id'],3,'unpublish',null);check($w->published($posts)===[]);
try{$w->page((int)$v['id'],$posts,'https://example.test');throw new Exception('非公開版漏えい');}catch(Failure $e){check($e->status===404);}
try{$w->create((int)$v['id'],$p);throw new Exception('孫を許可');}catch(Failure $e){check($e->status===400);}
echo "作品・バージョン 公開分離 競合 画像ポーズ除外 XSS 検証成功\n";
