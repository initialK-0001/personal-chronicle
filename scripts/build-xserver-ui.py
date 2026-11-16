#!/usr/bin/env python3
"""Generate the approved UI for the independent runtime; no prototype samples."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1];out=root/'xserver/ui';out.mkdir(exist_ok=True)
for name in ['style.css','timeline.js','index.html','public.js','admin.html','admin.js']:
 s=(root/'dist'/name).read_text()
 if name.endswith('.js'):s=s.replace("import {samples} from './samples.js';","const samples=[];")
 if name=='public.js':
  s=s.replace("get('theme')||'';view=","get('theme')||document.body.dataset.theme||'';view=")
  s="import {mountComments} from '/comments.js';\n"+s
  s=s.replace("document.title=p?","if(p)mountComments(articlePage,p.id);\n document.title=p?")
  s=s.replace("const postURL=p=>basePage+'?post='+encodeURIComponent(p.id);","const postURL=p=>p.url||basePage+'?post='+encodeURIComponent(p.id);")
 if name=='timeline.js':s=s.replace('<time>${date}</time>', '<time>${date}</time>${!admin?`<a href="${esc(url)}#comments">コメント</a>`:String()}')
 if name=='admin.html':
  s=s.replace('<a href="report.html">設計資料</a>','<a href="/admin/legacy-edit">旧記事</a><a href="/admin/comments">コメント</a><a href="/admin/contact">お問い合わせ</a><button id="logoutButton">ログアウト</button>')
  s=re.sub(r'<section id="commentManagement".*?</section>','',s)
  s=s.replace('<a href="report.html">調査と設計</a><a href="source.zip" download>ソースを保存</a>','')
  s=s.replace('<script type="module" src="comments-admin.js"></script>','').replace('image/webp,image/gif','image/webp')
  s=re.sub(r'<div class="adposition">.*?</div>','',s)
 if name=='style.css':s+='\n.policy-links{max-width:900px;margin:20px auto;padding:0 20px}.chronicle-ad{max-width:900px;margin:32px auto;padding:16px 20px;min-height:280px;box-sizing:border-box}.chronicle-ad>span{display:block;font-size:12px;color:#666;margin-bottom:8px}\n'
 if name=='style.css':s+='\n.comments{margin-top:40px}.comments label,.comment label{display:block;margin:12px 0}.comments input,.comments textarea,.comment textarea{display:block;width:100%;box-sizing:border-box}.comment{border-top:1px solid #ddd;padding:20px 0}.comment p{white-space:pre-wrap;overflow-wrap:anywhere}.comment button{margin:8px}\n'
 if name=='style.css':s+='\n.legacy-article{max-width:900px;margin:32px auto;padding:0 20px}.legacy-article img{max-width:100%;height:auto}.legacy-article pre{overflow:auto}.legacy-article table{max-width:100%;display:block;overflow:auto}.login{max-width:420px;margin:60px auto;padding:20px}.login label,.login input{display:block;width:100%;margin:12px 0}\n'
 if name=='admin.js':
  s="let csrf='';const session=await fetch('/api/session').then(r=>r.json());if(!session.authenticated)location.href='/login';csrf=session.csrf;\n"+s
  s=s.replace("'X-Chronicle-Request':'1'","'X-CSRF-Token':csrf")
  s=s.replace("editing.date=state.draft.date;editing.updatedAt=state.draft.updatedAt;","editing.date=state.draft.date;editing.updatedAt=state.draft.updatedAt;")
  old="function openEditor(p){editing=structuredClone(p?(state.draft?.id===p.id?state.draft:p):(state.draft||{id:id(),title:'',tags:[],date:null,blocks:[textBlock('')]}));"
  new="async function openEditor(p){if(!p){try{const r=await requestAPI('/api/admin/posts',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({title:'',tags:[],blocks:[textBlock('')]})});const fresh=await requestAPI('/api/state');state=fresh.state;etag=fresh.etag;p=state.posts.find(x=>x.id===String(r.post.id));}catch(e){message(e.message);return;}}editing=structuredClone(p);"
  assert old in s;s=s.replace(old,new)
  s=s.replace("async function openEditor(p){", "async function openEditor(p){if(openEditor.busy)return;openEditor.busy=true;$('#newButton').disabled=true;try{")
  s=s.replace("$('#writing textarea')?.focus();}", "$('#writing textarea')?.focus();}finally{openEditor.busy=false;$('#newButton').disabled=false;}}")
  s=s.replace("if(state.draft&&state.draft.id!==p.id){message('編集中の下書きがあります 下書きから再開してください');return;}",'')
  s=s.replace("$('#draftButton').onclick=()=>state.draft?openEditor():message('下書きはありません');","$('#draftButton').onclick=()=>{location.href=basePage+'?view=drafts';};")
  s=s.replace("pool.filter(p=>(view!=='images'", "pool.filter(p=>(view!=='drafts'||!p.date)&&(view!=='images'")
  s=s.replace("search?'検索結果':tag||'投稿一覧'", "view==='drafts'?'下書き':search?'検索結果':tag||'投稿一覧'")
  s=s.replace("if(!ready||dirty||$('#editor').open||uploading)return;try{const r=await requestAPI('/api/state');state=r.state;etag=r.etag;render();}", "if(!ready||dirty||$('#editor').open||openEditor.busy||uploading)return;const version=editVersion,pending=queued;try{const r=await requestAPI('/api/state');if(dirty||$('#editor').open||openEditor.busy||uploading||editVersion!==version||queued!==pending)return;state=r.state;etag=r.etag;render();}")
  s=s.replace("'image/webp','image/gif'","'image/webp'")
  s+="\n$('#logoutButton').onclick=async()=>{if(dirty||uploading){message('保存が終わってからログアウトしてください');return;}await requestAPI('/api/logout',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});location.href='/login';};\n"
 (out/name).write_text(s)
