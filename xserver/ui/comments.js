const element=(tag,text)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;return e;};
export async function mountComments(parent,post){
 const section=element('section');section.id='comments';section.className='comments';parent.append(section);section.append(element('h2','コメント'));
 const list=element('div'),notice=element('p');notice.setAttribute('role','status');section.append(list,notice);
 try{
  const r=await fetch('/api/public/comments?post='+encodeURIComponent(post),{cache:'no-store'});const data=await r.json();if(!r.ok)throw Error(data.error||'コメントを読み込めません');
  for(const c of data.comments){const item=element('article');item.className='comment';item.append(element('strong',c.name||'匿名'),element('p',c.body));if(c.reply){const reply=element('blockquote');reply.append(element('strong','管理者からの返信'),element('p',c.reply));item.append(reply);}list.append(item);}
  const form=element('form');const name=element('input');name.name='name';name.maxLength=100;name.autocomplete='off';const nameLabel=element('label','名前 任意');nameLabel.append(name);
  const body=element('textarea');body.name='body';body.required=true;body.maxLength=4000;body.rows=5;const bodyLabel=element('label','コメント');bodyLabel.append(body);
  const trap=element('input');trap.name='website';trap.tabIndex=-1;trap.autocomplete='off';trap.setAttribute('aria-hidden','true');trap.hidden=true;
  const send=element('button','送信');send.type='submit';form.append(nameLabel,bodyLabel,trap,element('p','承認後に掲載されます'),send);section.append(form);
  form.onsubmit=async e=>{e.preventDefault();send.disabled=true;notice.textContent='送信中';try{
   const sessionResponse=await fetch('/api/session',{cache:'no-store'});if(!sessionResponse.ok)throw Error('送信できません');const session=await sessionResponse.json();
   const response=await fetch('/api/public/comments',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':session.csrf},body:JSON.stringify({post,name:name.value,body:body.value,website:trap.value})});const result=await response.json();if(!response.ok)throw Error(result.error||'送信できません');body.value='';notice.textContent='受け付けました 承認後に掲載されます';
  }catch(error){notice.textContent=error.message;}finally{send.disabled=false;}};
 }catch(error){notice.textContent=error.message;}
}
const legacy=document.querySelector('[data-comment-post]');if(legacy)mountComments(legacy,legacy.dataset.commentPost);
