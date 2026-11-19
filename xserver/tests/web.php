<?php
declare(strict_types=1);
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Legacy.php';require __DIR__.'/../src/Web.php';
use Chronicle\Store;use Chronicle\Legacy;use Chronicle\Web;
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$s=new Store(new PDO('sqlite::memory:'));$s->initialize();
$r=['legacy_id'=>10,'path'=>'/old','status'=>'publish','type'=>'post','title'=>'旧記事','original_html'=>'本文','original_record'=>['post_password'=>''],'terms'=>[['taxonomy'=>'category','slug'=>'%E6%97%A7','name'=>'旧カテゴリー']],'published_at'=>'2022-01-01T00:00:00Z','updated_at'=>'2022-01-01T00:00:00Z'];$s->stageImport([$r]);$l=new Legacy($s,true);check($l->category('/category/旧/')['name']==='旧カテゴリー','日本語カテゴリー');check($l->category('/category/%E6%97%A7')['name']==='旧カテゴリー','エンコードカテゴリー');check((new Legacy($s,false))->categories()===[],'非公開移行の分類を隠す');
$p=['id'=>'1','title'=>'<script>題</script>','tags'=>[],'date'=>'2022-01-01T23:00:00Z','blocks'=>[['type'=>'paragraph','text'=>'公開本文 <script>alert(1)</script>']]];
$tpl=file_get_contents(__DIR__.'/../ui/index.html');$html=Web::page($tpl,[$p],'https://example.test',$p);check(str_contains($html,'公開本文 &lt;script&gt;'),'SSR本文とエスケープ');check(!str_contains($html,'<script>alert(1)'),'スクリプト実行を防止');check(str_contains($html,'2022/01/02'),'日本時間の日時');check(str_contains($html,'https://example.test/?post=1'),'正規URL');
$short=$p;$short['title']='';$short['blocks']=[['type'=>'paragraph','text'=>str_repeat('あ',400)]];check(!str_contains(Web::card($short),'続きを読む'),'400字は省略しない');$short['blocks'][0]['text'].='い';check(str_contains(Web::card($short),'続きを読む'),'401字は省略');check(!str_contains(Web::card($short),'<img'),'NoImageなし');
$xml=Web::sitemap('https://example.test',[$p],$l);check(str_contains($xml,'/category/%E6%97%A7'),'サイトマップ分類');check(str_contains($xml,'https://example.test/old'),'旧URL');check(!str_contains($xml,'admin'),'管理URLを含めない');$dom=new DOMDocument();check($dom->loadXML($xml),'XMLとして妥当');
echo "公開本文SSR・エスケープ・日本時間・カテゴリー・サイトマップの検証成功\n";
$p['title']='';$p['blocks']=[['type'=>'paragraph','text'=>"先頭\n\n次行"],['type'=>'heading','text'=>'見出し']];
check(str_contains(Web::card($p),'<p>先頭 次行 見出し</p>'),'一覧の改行と段落を空白にする');
check(str_contains(Web::blocks($p['blocks']),"先頭\n\n次行"),'個別本文の改行は保持');
$periodPosts=[['id'=>'january','title'=>'年境界の投稿','date'=>'2025-12-31T15:00:00Z','tags'=>[],'blocks'=>[['type'=>'paragraph','text'=>'年明け']]],['id'=>'december','title'=>'前年の投稿','date'=>'2025-12-31T14:59:59Z','tags'=>[],'blocks'=>[['type'=>'paragraph','text'=>'年末']]]];
$periodHtml=Chronicle\Web::page(file_get_contents(__DIR__.'/../ui/index.html'),$periodPosts,'https://example.test',null,'',['year'=>'2026','month'=>'01']);
if(!str_contains($periodHtml,'年境界の投稿')||str_contains($periodHtml,'前年の投稿'))throw new RuntimeException('日本時間の年月SSR');
echo "年月絞り込みのSSRと日本時間境界を確認\n";

$description = '新しい物事と東京生活中心に書いているブログですが、最近はVRM(人型3Dモデル規格)の話が多いです。';
$home = Web::page($tpl, [$p], 'https://example.test');
$dom = new DOMDocument();
$dom->loadHTML($home, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);
$meta = $xpath->query('/html/head/meta[@name="description"]');
check($meta->length === 1 && $meta->item(0)->getAttribute('content') === $description, 'トップの紹介文を初期HTMLの検索用説明に復元');
check($xpath->evaluate('string(//h1[@id="pageTitle"]/following-sibling::p[1])') === $description, '同じ紹介文を見出しの下に表示');
foreach ([$html, Web::page($tpl, [$p], 'https://example.test', null, '旧カテゴリー')] as $otherPage) {
    check(!str_contains($otherPage, $description), '記事とカテゴリーにトップの紹介文を流用しない');
}
