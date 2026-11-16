import {createRequire} from 'node:module';
import fs from 'node:fs';import assert from 'node:assert/strict';
const require=createRequire(import.meta.url);const {chromium}=require(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES?process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES+'/playwright':'playwright');
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
try{
 const page=await browser.newPage();let saved=null;const src='/api/images/'+'c'.repeat(64);
 const html=fs.readFileSync(new URL('../ui/legacy-admin.html',import.meta.url),'utf8');const script=fs.readFileSync(new URL('../ui/legacy-admin.js',import.meta.url),'utf8');
 const post={id:1,revision:1,visibility:'public',draft:{title:'検証',html:'<p>前半後半</p><p>末尾</p>'},html:'<p>前半後半</p><p>末尾</p>',thumbnailSrc:null,path:'/test'};
 await page.route('**/*',async route=>{const path=new URL(route.request().url()).pathname;
 if(path==='/admin/legacy-edit')return route.fulfill({contentType:'text/html',body:html});
 if(path==='/legacy-admin.js')return route.fulfill({contentType:'text/javascript',body:script});
 if(path==='/api/session')return route.fulfill({json:{authenticated:true,csrf:'test'}});
 if(path==='/api/upload'){await new Promise(r=>setTimeout(r,100));return route.fulfill({json:{src}});}
 if(path==='/api/admin/legacy/1'){if(route.request().method()==='POST'){saved=route.request().postDataJSON().content;post.draft=saved;post.revision++;}return route.fulfill({json:post});}
 return route.fulfill({status:200,body:''});});
 await page.goto('http://127.0.0.1/admin/legacy-edit?id=1');await page.getByText('保存済み',{exact:true}).waitFor();
 await page.evaluate(()=>{const text=document.querySelector('#legacyBody p').firstChild;const range=document.createRange();range.setStart(text,2);range.collapse(true);const s=getSelection();s.removeAllRanges();s.addRange(range);document.querySelector('#legacyBody').focus();});
 const chooser=page.waitForEvent('filechooser');await page.getByRole('button',{name:'画像を追加',exact:true}).click();await (await chooser).setFiles({name:'test.png',mimeType:'image/png',buffer:Buffer.from('test')});
 await page.waitForFunction(()=>document.querySelector('#legacyBody p img'));
 assert.equal(await page.locator('#legacyBody p').first().evaluate(e=>e.innerHTML),'前半<img src="'+src+'" alt="">後半');
 assert.equal(await page.locator('#legacyBody p').last().textContent(),'末尾');
 await page.getByText('保存済み',{exact:true}).waitFor();assert.ok(saved.html.includes('前半<img'));assert.ok(!('thumbnail' in saved));
 await page.locator('#legacyBody img').click();const replace=page.waitForEvent('filechooser');await page.getByRole('button',{name:'画像を追加',exact:true}).click();await (await replace).setFiles({name:'replacement.png',mimeType:'image/png',buffer:Buffer.from('test')});await page.getByText('保存済み',{exact:true}).waitFor();assert.equal(await page.locator('#legacyBody img').count(),1);
 const thumb=page.waitForEvent('filechooser');await page.getByRole('button',{name:'サムネイルを変更',exact:true}).click();await (await thumb).setFiles({name:'thumb.png',mimeType:'image/png',buffer:Buffer.from('test')});await page.waitForFunction(()=>document.querySelector('#thumbnailPreview').getAttribute('src')?.includes('/api/images/'));await page.getByText('保存済み',{exact:true}).waitFor();assert.equal(saved.thumbnail,src);
 await page.getByRole('button',{name:'本文先頭の画像を使う',exact:true}).click();await page.getByText('保存済み',{exact:true}).waitFor();assert.equal(saved.thumbnail,null);
 console.log('本文途中への挿入・末尾保持・選択画像差替え・サムネイル変更と自動設定を確認');
}finally{await browser.close();}
