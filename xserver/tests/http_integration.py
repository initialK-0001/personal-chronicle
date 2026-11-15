#!/usr/bin/env python3
"""Exercise the real PHP HTTP/session stack with disposable data only."""
import http.cookiejar,json,os,pathlib,socket,subprocess,tempfile,time,urllib.request,urllib.error
root=pathlib.Path(__file__).resolve().parents[1];php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
 p=pathlib.Path(tmp);(p/'sessions').mkdir();sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();origin=f'http://127.0.0.1:{port}'
 password='test-'+os.urandom(16).hex();h=subprocess.check_output([php,'-r','echo password_hash(stream_get_contents(STDIN),PASSWORD_DEFAULT);'],input=password.encode()).decode()
 cfg={'dsn':'sqlite:'+str(p/'db.sqlite'),'origin':origin,'password_hash':h,'rate_secret':os.urandom(32).hex(),'sessions':str(p/'sessions')}
 (p/'config.json').write_text(json.dumps(dict(cfg,local_test=True)))
 env=dict(os.environ,CHRONICLE_CONFIG=str(p/'config.json'));subprocess.run([php,str(root/'bin/setup.php')],env=env,check=True)
 server=subprocess.Popen([php,'-S',f'127.0.0.1:{port}','-t',str(root/'public'),str(root/'public/index.php')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 try:
  def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  a=client();b=client();anon=client()
  def call(c,path,body=None,token=None,headers=None):
   hd={'Origin':origin,**(headers or {})}
   if token:hd['X-CSRF-Token']=token
   if body is not None:hd['Content-Type']='application/json'
   req=urllib.request.Request(origin+path,data=None if body is None else json.dumps(body).encode(),headers=hd)
   try:r=c.open(req)
   except urllib.error.HTTPError as e:r=e
   return r.status,json.loads(r.read()),dict(r.headers)
  for _ in range(50):
   try:status,session,head=call(a,'/api/session');break
   except urllib.error.URLError:time.sleep(.05)
  assert status==200 and not session['authenticated'];token=session['csrf']
  assert 'HttpOnly' in head['Set-Cookie'] and 'SameSite=Strict' in head['Set-Cookie']
  assert call(a,'/api/login',{'password':password})[0]==403
  assert call(a,'/api/login',{'password':password},token,{'Origin':'https://other.invalid'})[0]==403
  status,data,_=call(a,'/api/login',{'password':password},token);assert status==200;token=data['csrf']
  assert call(anon,'/api/admin/posts/1',headers={'oai-authenticated-user-id':'owner'})[0]==401
  doc={'title':'','tags':[],'blocks':[{'type':'paragraph','text':'初版'}]}
  status,data,_=call(a,'/api/admin/posts',doc,token);assert status==201;id=data['post']['id']
  assert call(anon,'/api/public/posts')[1]['posts']==[]
  assert call(a,f'/api/admin/posts/{id}',{'action':'release','revision':1},token)[0]==200
  t=call(b,'/api/session')[1]['csrf'];t=call(b,'/api/login',{'password':password},t)[1]['csrf']
  assert call(b,f'/api/admin/posts/{id}')[1]['post']['revision']==2
  doc['blocks'][0]['text']='未公開の改稿'
  assert call(b,f'/api/admin/posts/{id}',{'action':'draft','revision':2,'content':doc},t)[0]==200
  assert call(anon,'/api/public/posts')[1]['posts'][0]['blocks'][0]['text']=='初版'
  assert call(a,f'/api/admin/posts/{id}',{'action':'release','revision':2},token)[0]==409
  assert call(b,f'/api/admin/posts/{id}',{'action':'unpublish','revision':3},t)[0]==200
  assert call(anon,'/api/public/posts')[1]['posts']==[]
  assert call(a,'/api/logout',{},token)[0]==200
  assert call(a,f'/api/admin/posts/{id}')[0]==401
  t=call(anon,'/api/session')[1]['csrf']
  for _ in range(8):assert call(anon,'/api/login',{'password':'wrong'},t)[0]==401
  assert call(anon,'/api/login',{'password':'wrong'},t)[0]==429
  print('HTTP認証・CSRF・2セッション・公開分離・競合・ログアウト・試行制限の検証成功')
 finally:server.terminate();server.wait(timeout=5)
