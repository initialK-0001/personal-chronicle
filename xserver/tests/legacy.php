<?php
declare(strict_types=1);
require __DIR__.'/../src/Store.php';
require __DIR__.'/../src/Legacy.php';
$s=new Chronicle\Store(new PDO('sqlite::memory:'));$s->initialize();
$r=['legacy_id'=>1,'path'=>'/old','status'=>'publish','type'=>'post','title'=>'旧記事','terms'=>[],'published_at'=>'2022-01-01','updated_at'=>'2022-01-02','original_record'=>['post_password'=>''],'original_html'=>'<p>本文</p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">リンク</a>[wpdm_package id="42"] [blogcard url="https://example.org/"]'];
$private=$r;$private['legacy_id']=2;$private['path']='/private';$private['status']='private';
$locked=$r;$locked['legacy_id']=3;$locked['path']='/locked';$locked['original_record']['post_password']='secret';
$s->stageImport([$r,$private,$locked]);
function check($v){if(!$v)throw new RuntimeException('旧記事検証失敗');}
check((new Chronicle\Legacy($s,false))->records()===[]);
$l=new Chronicle\Legacy($s,true);check(count($l->records())===1);check($l->find('/private')===null);check($l->find('/locked')===null);
$h=$l->html($r['original_html']);foreach(['<script','onerror','javascript:'] as $bad)check(!str_contains($h,$bad));check(str_contains($h,'wpdmdl=42'));check(str_contains($h,'https://example.org/'));
check(count($l->posts())===1);echo "旧記事の公開制御・HTML無害化・リンク変換の検証成功\n";

$tagged=new Chronicle\Legacy($s,true,'test-22');check(str_contains($tagged->html('[amazonjs asin="1234567890" title="商品"]'),'?tag=test-22'));
$embeds=$l->html('<iframe src="https://www.youtube.com/embed/abc"></iframe><iframe src="https://www.google.com/maps/embed?pb=test"></iframe><iframe src="https://evil.example/embed/abc"></iframe><iframe src="https://www.youtube.com.evil.example/embed/abc"></iframe>');
check(substr_count($embeds,'<iframe')===2);check(!str_contains($embeds,'evil.example'));echo "広告タグ保持・埋込先の限定を検証成功\n";
