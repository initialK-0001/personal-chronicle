import {publicAPI} from './public.mjs';
import {adminStatus,accessDenied} from './access.mjs';
import {api} from './api.mjs';
const publicAssets=new Set(['/index.html','/public.js','/timeline.js','/style.css','/assets/pose.png','/assets/peace.png','/assets/style.png','/assets/cover.jpg','/assets/expression.jpg']);
export async function handle(request,env,assets){
 const url=new URL(request.url);
 if(url.pathname.startsWith('/api/public/'))return publicAPI(request,env);
 if(url.pathname.startsWith('/api/'))return api(request,env);
 if(!['GET','HEAD'].includes(request.method))return new Response('',{status:405});
 const path=url.pathname==='/'?'/index.html':['/admin','/admin/'].includes(url.pathname)?'/admin.html':url.pathname;
 const a=assets[path];if(!a)return new Response('見つかりません',{status:404});
 if(!publicAssets.has(path)){const status=adminStatus(request,env);if(status!==200)return accessDenied(status);}
 return new Response(request.method==='HEAD'?null:Uint8Array.from(atob(a.body),c=>c.charCodeAt(0)),{headers:{'Content-Type':a.type,'Cache-Control':publicAssets.has(path)?'no-cache':'private, no-store','X-Content-Type-Options':'nosniff','Referrer-Policy':'same-origin'}});
}
