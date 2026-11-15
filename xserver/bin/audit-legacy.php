<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../src/Store.php';require __DIR__.'/../src/Legacy.php';
if(count($argv)!==2)throw new RuntimeException('非公開の移行JSONを指定してください');
$data=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$s=new Chronicle\Store(new PDO('sqlite::memory:'));$s->initialize();$s->stageImport($data['records']);
$l=new Chronicle\Legacy($s,true,'verification-22');$counts=['public_records'=>0,'images_before'=>0,'images_after'=>0,'iframes_before'=>0,'iframes_after'=>0,'amazon_before'=>0,'amazon_after'=>0,'downloads_before'=>0,'downloads_after'=>0,'scripts_removed'=>0];$issues=[];
foreach($l->records() as $r){$raw=$r['original_html'];$html=$l->html($raw);$counts['public_records']++;
 foreach(['images'=>'img','iframes'=>'iframe'] as $key=>$tag){$before=preg_match_all('/<'.$tag.'\b/i',$raw);$after=preg_match_all('/<'.$tag.'\b/i',$html);$counts[$key.'_before']+=$before;$counts[$key.'_after']+=$after;if($before!==$after)$issues[]=['id'=>$r['legacy_id'],'kind'=>$key,'before'=>$before,'after'=>$after];}
 $counts['amazon_before']+=preg_match_all('/\[amazonjs\b/',$raw);$counts['amazon_after']+=substr_count($html,'tag=verification-22');$counts['downloads_before']+=preg_match_all('/\[wpdm_package\b/',$raw);$counts['downloads_after']+=substr_count($html,'/?wpdmdl=');$counts['scripts_removed']+=preg_match_all('/<script\b/i',$raw);
 if(preg_match('/<(script)\b|\bonerror\s*=/i',$html))throw new RuntimeException('HTML処理を確認してください');
}
echo json_encode(['counts'=>$counts,'differences'=>$issues],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
