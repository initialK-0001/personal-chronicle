<?php
declare(strict_types=1);
require __DIR__.'/../src/WordPressExport.php';
use Chronicle\WordPressExport as E;
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
check(iterator_to_array(E::rows("(1,'a\\n日本語 🟢',NULL),(-2,'it''s\\\\ok','')"))===[[1,"a\n日本語 🟢",null],[-2,"it's\\ok",'']],'literal preservation');
foreach(["(1,NOW())","(1,'unterminated)","(1,'x'),","(1,0x12)","(1,'x');DROP TABLE t"] as $bad){try{iterator_to_array(E::rows($bad));throw new LogicException('unsafe accepted');}catch(RuntimeException $e){}}
$tables=[
 'posts'=>[['ID','post_type','post_status','post_name','post_title','post_content','post_date','post_modified'],[[1,'post','publish','first','題名','本文','2022-10-23 10:00:00','2022-10-24 12:00:00'],[2,'post','draft','','','下書き','0000-00-00 00:00:00','2022-10-24 12:00:00'],[3,'wpdmpro','publish','file','','','2022-10-23 10:00:00','2022-10-23 10:00:00']]],
 'postmeta'=>[['post_id','meta_key','meta_value'],[[1,'_thumbnail_id','99'],[99,'_wp_attached_file','2022/10/photo.png'],[3,'__wpdm_files','a:1:{i:0;s:8:"pose.zip";}']]],
 'terms'=>[['term_id','name','slug'],[[10,'作品','works']]],
 'term_taxonomy'=>[['term_taxonomy_id','term_id','taxonomy'],[[20,10,'category']]],
 'term_relationships'=>[['object_id','term_taxonomy_id'],[[1,20]]],
 'comments'=>[['comment_ID'],[]],
 'options'=>[['option_name','option_value'],[['home','https://brand-new.tokyo'],['permalink_structure','/%postname%'],['timezone_string','Asia/Tokyo'],['secret-option','excluded']]],
];
$sql='';foreach($tables as $name=>[$cols,$rows]){$sql.='CREATE TABLE `wp'.$name."` (\n";foreach($cols as $col)$sql.='  `'.$col."` text,\n";$sql.=") ENGINE=InnoDB;\n";if($rows){$encoded=[];foreach($rows as $row)$encoded[]='('.implode(',',array_map(fn($v)=>is_int($v)?(string)$v:"'".str_replace(['\\',"'"],['\\\\',"\\'"],$v)."'",$row)).')';$sql.='INSERT INTO `wp'.$name.'` VALUES '.implode(',',$encoded).";\n";}}
$path=tempnam(sys_get_temp_dir(),'wp-export-');try{file_put_contents($path,gzencode($sql));$sha=hash_file('sha256',$path);$data=E::read($path,'wp',$sha);check(count($data['records'])===2,'post count');check($data['records'][0]['thumbnail']==='/wp-content/uploads/2022/10/photo.png','string image id');check($data['records'][0]['published_at']==='2022-10-23T01:00:00+00:00','timezone');check($data['records'][1]['published_at']===null,'zero date');check($data['records'][1]['status']==='draft'&&!$data['publication_enabled'],'draft state');check($data['downloads'][0]['files']===['pose.zip'],'download array');check(!str_contains(E::canonical($data),'secret-option'),'private options excluded');try{E::read($path,'wp',str_repeat('0',64));throw new LogicException('hash accepted');}catch(RuntimeException $e){} }finally{unlink($path);}
echo "SQL非実行抽出・画像ID・日時・状態・配布ファイル・原本ハッシュを確認\n";
