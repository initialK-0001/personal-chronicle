const status=document.querySelector('#status'),list=document.querySelector('#commentsList');let csrf='';
const element=(tag,text)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;return e;};
async function api(path,body){const r=await fetch(path,{cache:'no-store',...(body?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)}:{})});const d=await r.json();if(!r.ok)throw Error(d.error||'処理できません');return d;}
async function render(){const data=await api('/api/admin/comments');list.replaceChildren();status.textContent=data.comments.length?'':'コメントはありません';
 for(const c of data.comments){const row=element('article');row.className='comment';row.append(element('strong',c.name||'匿名'),element('p',c.status==='pending'?'未承認':'承認済み'),element('p',c.body));
  const link=element('a','記事を開く');link.href=c.post_key.startsWith('legacy-')?'/admin/legacy-id?id='+encodeURIComponent(c.post_key.slice(7)):'/admin?post='+encodeURIComponent(c.post_key);row.append(link);
  const change=async(action,reply)=>{const buttons=row.querySelectorAll('button');buttons.forEach(b=>b.disabled=true);try{await api('/api/admin/comments/'+c.id,{revision:Number(c.revision),action,reply});await render();status.textContent=action==='delete'?'削除しました':action==='approve'?'承認しました':'返信を保存しました';}catch(e){status.textContent=e.message;buttons.forEach(b=>b.disabled=false);}};
  if(c.status==='pending'){const approve=element('button','承認');approve.onclick=()=>change('approve');row.append(approve);}
  if(c.status==='approved'){const form=element('form'),label=element('label','返信'),reply=element('textarea');reply.value=c.reply||'';reply.required=true;reply.maxLength=4000;label.append(reply);const send=element('button','返信を保存');send.type='submit';form.append(label,send);form.onsubmit=e=>{e.preventDefault();change('reply',reply.value);};row.append(form);}
  const remove=element('button','削除');remove.onclick=()=>change('delete');row.append(remove);list.append(row);
 }
}
try{const session=await api('/api/session');if(!session.authenticated){location.href='/login';}else{csrf=session.csrf;await render();}}catch(e){status.textContent=e.message;}
