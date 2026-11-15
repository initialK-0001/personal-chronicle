export function adminStatus(request,env){
 if(!request.headers.get('oai-authenticated-user-id'))return 401;
 const configured=env.CHRONICLE_ADMIN_EMAIL?.trim().toLowerCase();
 if(!configured)return 503;
 return request.headers.get('oai-authenticated-user-email')?.trim().toLowerCase()===configured?200:403;
}
export function accessDenied(status){return new Response(status===401?'<!doctype html><html lang="ja"><meta charset="utf-8"><title>管理者ログイン</title><p>管理者のログインが必要です</p><a href="/signin-with-chatgpt?return_to=%2Fadmin" target="_top">ログイン</a></html>':status===503?'管理者設定を確認してください':'管理者のみ利用できます',{status,headers:{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store','X-Content-Type-Options':'nosniff'}});}
