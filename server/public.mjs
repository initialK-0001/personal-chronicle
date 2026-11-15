// Public responses are an allowlist projection of explicitly released snapshots only.
export async function publicAPI(request,env){
 const headers={'Cache-Control':'no-store','X-Content-Type-Options':'nosniff'};
 const result=(data,status=200)=>Response.json(data,{status,headers});
 if(request.method!=='GET')return result({error:'未対応の操作です'},405);
 if(!env.BUCKET)return result({error:'保存先に接続できません'},503);
 try{
 const pointer=await env.BUCKET.get('public-owner.json');const prefix=pointer?(await pointer.json()).prefix:null;
 const file=prefix?await env.BUCKET.get(prefix+'state.json'):null;
 const posts=file?(await file.json()).posts.filter(p=>p.visibility==='public'):[];
 const path=new URL(request.url).pathname;
 if(path==='/api/public/posts')return result({posts:posts.map(p=>({id:p.id,title:p.title,tags:p.tags,date:p.date,updatedAt:p.updatedAt,blocks:p.blocks.map(b=>b.type==='image'?{type:'image',src:b.src.startsWith('/api/images/')?b.src.replace('/api/images/','/api/public/images/'):b.src,alt:b.alt}:{type:b.type,text:b.text,...(b.type==='link'?{url:b.url}:{})})}))});
 if(path.startsWith('/api/public/images/')){
 const id=path.slice('/api/public/images/'.length);if(!/^[a-f0-9]{64}$/.test(id)||!posts.some(p=>p.blocks.some(b=>b.type==='image'&&b.src==='/api/images/'+id)))return result({error:'画像がありません'},404);
 const image=await env.BUCKET.get(prefix+'images/'+id);if(!image)return result({error:'画像がありません'},404);
 return new Response(image.body,{headers:{...headers,'Content-Type':image.httpMetadata.contentType}});
 }return result({error:'見つかりません'},404);
 }catch{return result({error:'読み込めません'},503);}
}
