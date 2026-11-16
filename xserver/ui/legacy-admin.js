const $=s=>document.querySelector(s),status=$('#status'),body=$('#legacyBody'),title=$('#legacyTitle');
let csrf='',post=null,dirty=false,bodyDirty=false,busy=false,timer,conflict=false,changeNumber=0,thumbnail,bodyRange=null,imageRange=null;
async function api(path,data){const r=await fetch(path,{cache:'no-store',...(data?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)}:{})});const d=await r.json();if(!r.ok){const e=Error(d.error||'処理できません');e.code=r.status;throw e;}return d;}
function latestDate(p){
 const published=p.publishedAt?new Date(p.publishedAt):null,updated=p.updatedAt?new Date(p.updatedAt):null;
 const valid=d=>d&&!Number.isNaN(d.getTime());
 const isUpdate=valid(updated)&&(!valid(published)||updated>published),date=isUpdate?updated:published;
 if(!valid(date))return null;
 return {iso:date.toISOString(),text:(isUpdate?'更新 ':'投稿 ')+new Intl.DateTimeFormat('ja-JP',{timeZone:'Asia/Tokyo',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(date)};
}
function firstPublishedFirst(a,b){const stamp=p=>{const value=p.firstPublishedAt?Date.parse(p.firstPublishedAt):NaN;return Number.isFinite(value)?value:-Infinity;};return (stamp(b)-stamp(a))||(Number(b.id)-Number(a.id));}
function localImages(html){const t=document.createElement('template');t.innerHTML=html;for(const img of t.content.querySelectorAll('img')){const u=new URL(img.getAttribute('src'),location.origin);if(u.origin===location.origin||u.hostname==='brand-new.tokyo'){if(u.pathname.startsWith('/wp-content/uploads/'))img.setAttribute('src','/admin/legacy-media/'+u.pathname.slice('/wp-content/uploads/'.length));} }return t.innerHTML;}
function source(){if(!bodyDirty)return post.draft.html;const t=document.createElement('template');t.innerHTML=body.innerHTML;for(const img of t.content.querySelectorAll('img')){const u=new URL(img.getAttribute('src'),location.origin);if(u.origin===location.origin&&u.pathname.startsWith('/admin/legacy-media/'))img.setAttribute('src','/wp-content/uploads/'+u.pathname.slice('/admin/legacy-media/'.length));}return t.innerHTML;}
function changed(){dirty=true;changeNumber++;status.textContent='未保存';clearTimeout(timer);if(!conflict)timer=setTimeout(()=>save('draft'),1000);}
function buttons(){for(const b of document.querySelectorAll('#legacyEditor button'))b.disabled=busy;$('#release').textContent=post.visibility==='public'?'公開内容を更新':'公開';$('#unpublish').hidden=post.visibility!=='public';$('#publicLink').hidden=post.visibility!=='public';}
async function save(action){clearTimeout(timer);if(busy)return;if(conflict){status.textContent='別の端末で変更されています 入力を残して読み直してください';return;}busy=true;buttons();const version=changeNumber;const content={title:title.value,html:source(),...(thumbnail!==undefined?{thumbnail}:{})};status.textContent='保存中';
 try{post=await api('/api/admin/legacy/'+post.id,{revision:post.revision,action,...(action==='unpublish'?{}:{content})});if(action!=='unpublish'&&changeNumber===version)dirty=false;status.textContent=dirty?'未保存':action==='release'?'公開しました':action==='unpublish'?'非公開にしました':'保存済み';}
 catch(e){conflict=e.code===409;status.textContent=e.message;}
 finally{busy=false;buttons();if(dirty&&!conflict&&(changeNumber!==version||action==='unpublish'))timer=setTimeout(()=>save('draft'),1000);}
}
body.addEventListener('input',()=>{bodyDirty=true;changed();renderThumbnail();});title.addEventListener('input',changed);
// Paste plain text so clipboard HTML cannot execute or introduce tracking elements in the editor.
body.addEventListener('paste',e=>{e.preventDefault();document.execCommand('insertText',false,e.clipboardData.getData('text/plain'));bodyDirty=true;changed();});
body.addEventListener('drop',e=>e.preventDefault());
for(const b of document.querySelectorAll('[data-command]')){b.onmousedown=e=>e.preventDefault();b.onclick=()=>{body.focus();document.execCommand(b.dataset.command,false,b.dataset.value||null);bodyDirty=true;changed();};}
$('#addLink').onmousedown=e=>e.preventDefault();$('#addLink').onclick=()=>{const url=prompt('リンク先のURL');if(!url)return;try{if(!['https:','http:'].includes(new URL(url).protocol))throw Error();body.focus();document.execCommand('createLink',false,url);bodyDirty=true;changed();}catch{status.textContent='リンク先を確認してください';}};
function rememberBodyRange(){const sel=window.getSelection();if(sel?.rangeCount){const range=sel.getRangeAt(0);if(body.contains(range.startContainer)&&body.contains(range.endContainer))bodyRange=range.cloneRange();}}
document.addEventListener('selectionchange',rememberBodyRange);
body.addEventListener('click',e=>{if(e.target.tagName==='IMG'){const range=document.createRange();range.selectNode(e.target);const sel=window.getSelection();sel.removeAllRanges();sel.addRange(range);bodyRange=range.cloneRange();}});
function insertImageAtRange(src,range){
 if(!range||!body.contains(range.startContainer)||!body.contains(range.endContainer))throw Error('本文の挿入位置をクリックしてから画像を追加してください');
 const img=document.createElement('img');img.src=src;img.alt='';range.deleteContents();range.insertNode(img);range.setStartAfter(img);range.collapse(true);bodyRange=range.cloneRange();body.focus();const sel=window.getSelection();sel.removeAllRanges();sel.addRange(range);bodyDirty=true;changed();renderThumbnail();
}
function thumbnailURL(src){if(!src)return '';const u=new URL(src,location.origin);return (u.origin===location.origin||u.hostname==='brand-new.tokyo')&&u.pathname.startsWith('/wp-content/uploads/')?'/admin/legacy-media/'+u.pathname.slice('/wp-content/uploads/'.length):src;}
function renderThumbnail(){const src=thumbnail===undefined?post?.thumbnailSrc:thumbnail===null?body.querySelector('img')?.getAttribute('src'):thumbnail;const preview=$('#thumbnailPreview');preview.hidden=!src;if(src)preview.src=thumbnailURL(src);else preview.removeAttribute('src');$('#thumbnailHint').textContent=thumbnail===null?'本文の先頭画像を使用します':src?'指定されたサムネイルを使用します':'サムネイル画像はありません';}
async function uploadLegacyImage(file,target,range){
 if(!file||busy)return;
 if(file.size>30*1024*1024){status.textContent='画像は1枚30MBまでです';return;}
 clearTimeout(timer);busy=true;buttons();body.contentEditable='false';status.textContent='画像を保存中';
 try{const r=await fetch('/api/upload',{method:'POST',headers:{'X-CSRF-Token':csrf},body:file}),d=await r.json();if(!r.ok)throw Error(d.error||'画像を保存できません');if(target==='thumbnail'){thumbnail=d.src;changed();renderThumbnail();}else{body.contentEditable='true';insertImageAtRange(d.src,range);}}
 catch(e){status.textContent=e.message;}
 finally{body.contentEditable='true';busy=false;buttons();$('#imageFile').value='';$('#thumbnailFile').value='';if(dirty&&!conflict){clearTimeout(timer);timer=setTimeout(()=>save('draft'),1000);}}
}
$('#addImage').onmousedown=e=>{rememberBodyRange();e.preventDefault();};
$('#addImage').onclick=()=>{rememberBodyRange();if(!bodyRange||!body.contains(bodyRange.startContainer)){status.textContent='本文の挿入位置をクリックしてから画像を追加してください';return;}imageRange=bodyRange.cloneRange();$('#imageFile').click();};
$('#imageFile').onchange=()=>uploadLegacyImage($('#imageFile').files[0],'body',imageRange);
$('#changeThumbnail').onclick=()=>$('#thumbnailFile').click();
$('#thumbnailFile').onchange=()=>uploadLegacyImage($('#thumbnailFile').files[0],'thumbnail');
$('#automaticThumbnail').onclick=()=>{thumbnail=null;changed();renderThumbnail();};
$('#saveDraft').onclick=()=>save('draft');$('#release').onclick=()=>save('release');$('#unpublish').onclick=()=>save('unpublish');
$('#preview').onclick=async()=>{try{const d=await api('/api/admin/legacy-preview',{html:source()});$('#legacyPreview').innerHTML=localImages(d.html);$('#legacyPreview').hidden=false;}catch(e){status.textContent=e.message;}};
window.addEventListener('beforeunload',e=>{if(dirty||busy){e.preventDefault();e.returnValue='';}});
try{const session=await api('/api/session');if(!session.authenticated)location.href='/login';else{csrf=session.csrf;const id=new URLSearchParams(location.search).get('id');if(id){post=await api('/api/admin/legacy/'+encodeURIComponent(id));title.value=post.draft.title;body.innerHTML=localImages(post.html);thumbnail=post.draft.thumbnail;renderThumbnail();$('#publicLink').href=post.path;$('#legacyListing').hidden=true;$('#legacyEditor').hidden=false;buttons();status.textContent='保存済み';}else{const data=await api('/api/admin/legacy');data.posts.sort(firstPublishedFirst);let limit=100;const more=document.createElement('button');more.textContent='さらに表示';$('#legacyList').after(more);const render=()=>{$('#legacyList').replaceChildren();const matches=data.posts.filter(p=>p.title.includes($('#legacySearch').value));more.hidden=matches.length<=limit;for(const p of matches.slice(0,limit)){const a=document.createElement('a');a.href='/admin/legacy-edit?id='+p.id;a.dataset.firstPublishedAt=p.firstPublishedAt||'';a.textContent=(p.title||'タイトルなし')+(p.visibility==='public'?'':'　非公開');const latest=latestDate(p);if(latest){const time=document.createElement('time');time.dateTime=latest.iso;time.textContent=latest.text;a.append(time);}$('#legacyList').append(a);}};more.onclick=()=>{limit+=100;render();};$('#legacySearch').oninput=()=>{limit=100;render();};render();status.textContent='';}}}catch(e){status.textContent=e.message;}
