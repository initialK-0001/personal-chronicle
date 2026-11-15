// Stage-one moderation store. Called only after the administrator gate.
export async function comments(request,env,prefix){
 const respond=(value,status=200)=>Response.json(value,{status,headers:{'Cache-Control':'private, no-store'}});
 const key=prefix+'comments.json',file=await env.BUCKET.get(key),state=file?await file.json():{items:[]};
 if(request.method==='GET')return respond({items:state.items,etag:file?.etag||null});
 if(request.method!=='POST')return respond({error:'未対応の操作です'},405);
 // Stream cap protects chunked requests as well as Content-Length.
 let size=0,text='';const reader=request.body?.getReader();if(!reader)return respond({error:'入力がありません'},400);const decoder=new TextDecoder();
 while(true){const {done,value}=await reader.read();if(done)break;size+=value.length;if(size>16384){await reader.cancel();return respond({error:'入力が長すぎます'},413);}text+=decoder.decode(value,{stream:true});}text+=decoder.decode();
 let input;try{input=JSON.parse(text);}catch{return respond({error:'入力形式を確認してください'},400);}
 if(input.etag!==(file?.etag||null))return respond({error:'別の画面で変更されています 再読み込みしてください'},409);
 const valid=(s,max)=>typeof s==='string'&&s.trim().length>0&&s.length<=max;
 if(input.action==='submit'){
  if((input.name!=null&&(typeof input.name!=='string'||input.name.length>80))||!valid(input.body,4000)||!valid(input.postId,80)||!/^[-\w]+$/.test(input.postId))return respond({error:'投稿先 名前 本文を確認してください'},400);
  const postsFile=await env.BUCKET.get(prefix+'state.json');const posts=postsFile?(await postsFile.json()).posts:[];
  if(!['sample-article','sample-pose','sample-gallery','sample-cover','sample-note','sample-thought'].includes(input.postId)&&!posts.some(p=>p.id===input.postId))return respond({error:'投稿がありません'},404);
  if(state.items.length>=1000)return respond({error:'コメントの保管上限に達しました'},413);
  if(state.items.some(x=>Date.now()-Date.parse(x.createdAt)<30000))return respond({error:'少し時間をおいて送信してください'},429);
  state.items.push({id:crypto.randomUUID(),postId:input.postId,name:(input.name||'').trim()||'匿名',body:input.body.trim(),status:'pending',createdAt:new Date().toISOString(),reply:''});
 }else{
  const item=state.items.find(x=>x.id===input.id);if(!item)return respond({error:'コメントがありません'},404);
  if(['approve','delete'].includes(input.action)){item.status={approve:'approved',reject:'rejected',spam:'spam',delete:'deleted'}[input.action];}
  else if(input.action==='reply'&&item.status==='approved'&&typeof input.body==='string'&&input.body.length<=4000)item.reply=input.body.trim();
  else return respond({error:'操作を確認してください'},400);
  item.updatedAt=new Date().toISOString();
 }
 const saved=await env.BUCKET.put(key,JSON.stringify(state),{onlyIf:new Headers(file?{'If-Match':file.httpEtag}:{'If-None-Match':'*'}),httpMetadata:{contentType:'application/json'}});
 if(!saved)return respond({error:'別の画面で変更されています'},409);
 return respond({items:state.items,etag:saved.etag});
}
export const approvedComments=items=>items.filter(x=>x.status==='approved').map(({name,body,reply,createdAt})=>({name,body,reply,createdAt}));
