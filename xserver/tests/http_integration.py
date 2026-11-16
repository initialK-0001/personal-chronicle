#!/usr/bin/env python3
"""Exercise the real PHP HTTP/session stack with disposable data only."""
import http.cookiejar,json,os,pathlib,socket,subprocess,tempfile,time,urllib.request,urllib.error
root=pathlib.Path(__file__).resolve().parents[1];php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
 p=pathlib.Path(tmp);(p/'sessions').mkdir();(p/'images').mkdir();sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();origin=f'http://127.0.0.1:{port}'
 password='test-'+os.urandom(16).hex();h=subprocess.check_output([php,'-r','echo password_hash(stream_get_contents(STDIN),PASSWORD_DEFAULT);'],input=password.encode()).decode()
 cfg={'dsn':'sqlite:'+str(p/'db.sqlite'),'origin':origin,'password_hash':h,'rate_secret':os.urandom(32).hex(),'sessions':str(p/'sessions'),'images':str(p/'images')}
 cfg['adsense']={'enabled':True,'account_settings_verified':True,'client':'ca-pub-0000000000000000','slot':'0000000000'}
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
  assert call(anon,'/ads.txt')[0]==404
  assert call(anon,'/ads.txt',{})[0]==405
  assert 'HttpOnly' in head['Set-Cookie'] and 'SameSite=Strict' in head['Set-Cookie']
  assert call(a,'/api/login',{'password':password})[0]==403
  assert call(a,'/api/login',{'password':password},token,{'Origin':'https://other.invalid'})[0]==403
  status,data,_=call(a,'/api/login',{'password':password},token);assert status==200;token=data['csrf']
  assert call(anon,'/api/admin/posts/1',headers={'oai-authenticated-user-id':'owner'})[0]==401
  doc={'title':'','tags':[],'blocks':[{'type':'paragraph','text':'初版'}]}
  stale_token=token
  token=call(a,'/api/login',{'password':password},token)[1]['csrf']
  status,stale,_=call(a,'/api/admin/posts',doc,stale_token)
  assert status==403 and stale['error']=='画面の認証情報が古くなっています 再読み込みしてください'
  assert call(a,'/api/state')[1]['state']['posts']==[]
  token=call(a,'/api/session')[1]['csrf']
  assert call(a,'/api/admin/posts',doc,token,{'Origin':'https://other.invalid'})[0]==403
  assert call(a,'/api/state')[1]['state']['posts']==[]
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
  # Introductions are ordinary posts with one canonical URL and one publication state.
  workdoc={'title':'Karen','tags':[],'blocks':[{'type':'paragraph','text':'公開の紹介'}],'work':{'role':'work','parent':None}}
  assert call(a,'/api/admin/posts',workdoc)[0]==403
  st,result,_=call(a,'/api/admin/posts',workdoc,token);assert st==201;wid=result['post']['id']
  versiondoc=dict(workdoc,title='初版',work={'role':'version','parent':str(wid)})
  st,result,_=call(a,'/api/admin/posts',versiondoc,token);assert st==201;vid=result['post']['id']
  assert call(a,f'/api/admin/posts/{vid}',{'revision':1,'action':'release'},token)[0]==200
  assert call(anon,'/api/public/works')[1]['works']==[]
  assert call(a,f'/api/admin/posts/{wid}',{'revision':1,'action':'release'},token)[0]==200
  public=call(anon,'/api/public/posts')[1]['posts'];assert len(public)==2
  assert len(call(anon,'/api/public/works')[1]['works'])==2
  assert '/?post='+str(wid) in anon.open(origin+'/works').read().decode()
  assert anon.open(origin+f'/works/{wid}').geturl()==origin+'/?post='+str(wid)
  assert '初版' in anon.open(origin+'/?post='+str(wid)).read().decode()
  assert '公開の紹介' in anon.open(origin+'/').read().decode()
  assert call(b,f'/api/admin/posts/{wid}',{'revision':2,'action':'draft','content':dict(workdoc,title='秘密')},t)[0]==200
  assert '秘密' not in anon.open(origin+'/works').read().decode()
  assert call(a,f'/api/admin/posts/{wid}',{'revision':2,'action':'release'},token)[0]==409
  assert call(a,f'/api/admin/posts/{wid}',{'revision':3,'action':'unpublish'},token)[0]==200
  assert call(anon,f'/works/{vid}')[0]==404
  assert call(anon,'/?post='+str(wid))[0]==404
  public=call(anon,'/api/public/posts')[1]['posts'];assert len(public)==1 and public[0]['work'] is None
  assert call(a,f'/api/admin/posts/{vid}',{'revision':2,'action':'unpublish'},token)[0]==200
  assert anon.open(origin+'/admin/works').geturl()==origin+'/login'
  assert a.open(origin+'/admin/works').geturl()==origin+'/admin?view=works'
  assert call(a,'/api/admin/works',{'content':{}},token)[0]==404
  # Real decode/re-encode and public-reference authorization
  import base64
  png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR1kAAAAASUVORK5CYII=')
  # Produce a valid image with GD, independent of the upload implementation
  png=subprocess.check_output([php,'-r','$i=imagecreatetruecolor(2,2);imagepng($i);'])
  req=urllib.request.Request(origin+'/api/upload',data=png,headers={'Origin':origin,'X-CSRF-Token':token,'Content-Type':'image/png'})
  uploaded=json.loads(a.open(req).read());src=uploaded['src'];public=src.replace('/api/images/','/api/public/images/')
  assert call(anon,public)[0]==404
  imagepost={'title':'','tags':[],'blocks':[{'id':'image','type':'image','src':src,'alt':''}]}
  status,data,_=call(a,'/api/admin/posts',imagepost,token);assert status==201;imageid=data['post']['id']
  assert call(a,f'/api/admin/posts/{imageid}',{'action':'release','revision':1},token)[0]==200
  assert anon.open(origin+public).headers['Content-Type']=='image/png'
  assert call(a,f'/api/admin/posts/{imageid}',{'action':'unpublish','revision':2},token)[0]==200
  assert call(anon,public)[0]==404
  assert call(a,f'/api/admin/posts/{imageid}',{'action':'release','revision':3},token)[0]==200
  assert call(anon,f'/api/admin/posts/{imageid}',{'action':'trash','revision':4})[0] in (401,403)
  assert call(a,f'/api/admin/posts/{imageid}',{'action':'trash','revision':4},token)[0]==200
  assert call(anon,public)[0]==404
  assert call(anon,f'/?post={imageid}')[0]==404
  assert str(imageid) not in [p['id'] for p in call(anon,'/api/public/posts')[1]['posts']]
  assert call(a,f'/api/admin/posts/{imageid}',{'action':'release','revision':5},token)[0]==409
  assert call(a,f'/api/admin/posts/{imageid}',{'action':'restore','revision':5},token)[0]==200
  assert call(anon,public)[0]==404
  assert anon.open(origin+f'/admin?edit={imageid}').geturl()==origin+'/login'
  editor_html=a.open(origin+f'/admin?edit={imageid}').read().decode()
  assert '<section id="editor"' in editor_html and '<dialog id="editor"' not in editor_html
  try:anon.open(origin+src);raise AssertionError('private image exposed')
  except urllib.error.HTTPError as e:assert e.code==401
  # Exercise the exact UI bridge, including reopen on a second session and stale saves.
  status,initial,_=call(a,'/api/state');assert status==200 and initial['state']['posts']
  draft=next(x for x in initial['state']['posts'] if x['id']==str(id));draft['blocks'][0]['text']='画面からの保存'
  stale=initial['etag'].copy()
  status,saved,_=call(a,'/api/state',{'action':'draft','post':draft,'etag':stale},token);assert status==200 and saved['state']['draft']['blocks'][0]['text']=='画面からの保存'
  assert call(b,'/api/state')[1]['state']['posts'][-1]['blocks'][0]['text']=='画面からの保存'
  assert call(b,'/api/state',{'action':'draft','post':draft,'etag':stale},t)[0]==409
  assert call(a,'/api/state',{'action':'release','post':draft,'etag':saved['etag']},token)[0]==200
  assert call(anon,'/api/public/posts')[1]['posts'][0]['blocks'][0]['text']=='画面からの保存'
  published_html=anon.open(origin+'/?post='+str(id)).read().decode();assert '画面からの保存' in published_html and 'rel="canonical"' in published_html
  assert call(anon,'/?post=999999')[0]==404
  assert call(anon,'/missing-route')[0]==404
  assert call(anon,'/sitemap.xml')[0]==404
  assert 'Disallow: /\n' in anon.open(origin+'/robots.txt').read().decode()
  indexed=json.loads((p/'config.json').read_text());indexed['public_indexing']=True;(p/'config.json').write_text(json.dumps(indexed))
  indexed_response=anon.open(origin+'/?post='+str(id));assert indexed_response.headers['X-Robots-Tag']=='index, follow';assert 'noindex,nofollow' not in indexed_response.read().decode()
  sitemap=anon.open(origin+'/sitemap.xml').read().decode();assert '/?post='+str(id) in sitemap and '/admin' not in sitemap
  assert anon.open(origin+'/login').headers['X-Robots-Tag']=='noindex, nofollow'
  assert a.open(origin+'/admin').headers['X-Robots-Tag']=='noindex, nofollow'
  fresh=call(a,'/api/state')[1]
  assert call(a,'/api/state',{'action':'unpublish','id':str(id),'etag':fresh['etag']},token)[0]==200
  assert call(anon,'/api/public/posts')[1]['posts']==[]
  assert a.open(origin+'/admin').status==200
  assert '投稿' in a.open(origin+'/admin').read().decode()
  assert 'adsbygoogle' not in a.open(origin+'/admin').read().decode()
  assert 'adsbygoogle' not in anon.open(origin+'/').read().decode()
  assert anon.open(origin+'/admin').geturl()==origin+'/login'
  assert 'noindex' in anon.open(origin+'/login').read().decode()
  # Imported articles: same URL, authenticated editing, public snapshots and image authorization.
  import sqlite3,hashlib
  legacy={'legacy_id':100,'path':'/old-article','status':'publish','type':'post','title':'旧題','terms':[],'original_html':'<p>元本文</p>','published_at':'2022-01-01T00:00:00Z','updated_at':'2022-01-02T00:00:00Z','original_record':{'post_password':''}}
  raw=json.dumps(legacy,ensure_ascii=False);db=sqlite3.connect(p/'db.sqlite');db.execute('INSERT INTO chronicle_imports VALUES(?,?,?,?,?)',(100,'/old-article','publish',raw,hashlib.sha256(raw.encode()).hexdigest()));db.commit();db.close()
  (p/'legacy/download-manager-files').mkdir(parents=True);(p/'legacy/download-manager-files/test.bin').write_bytes(b'legacy download');(p/'legacy/private.png').write_bytes(png)
  private_legacy=dict(legacy,legacy_id=101,path='/private-old',status='private',original_html='<p><img src="/wp-content/uploads/private.png"></p>');private_raw=json.dumps(private_legacy)
  db=sqlite3.connect(p/'db.sqlite');db.execute('INSERT INTO chronicle_imports VALUES(?,?,?,?,?)',(101,'/private-old','private',private_raw,hashlib.sha256(private_raw.encode()).hexdigest()));db.execute('INSERT INTO chronicle_downloads VALUES(?,?)',(7,json.dumps({'public':True,'files':['test.bin']})));db.commit();db.close()
  indexed['legacy_uploads']=str(p/'legacy');indexed['legacy_public_enabled']=True;(p/'config.json').write_text(json.dumps(indexed))
  assert '元本文' in anon.open(origin+'/old-article').read().decode()
  assert call(anon,'/api/admin/legacy/100')[0]==401
  assert anon.open(origin+'/admin/legacy-edit').geturl()==origin+'/login'
  assert 'contenteditable="true"' in a.open(origin+'/admin/legacy-edit').read().decode()
  assert call(anon,'/wp-content/uploads/private.png')[0]==404
  assert call(anon,'/admin/legacy-media/private.png')[0]==401
  assert a.open(origin+'/admin/legacy-media/private.png').headers['Content-Type']=='image/png'
  edit={'title':'旧記事の改稿','html':'<p>未公開の編集</p><a href="/?wpdmdl=7">配布</a><img src="'+src+'"><script>alert(1)</script>'}
  assert call(a,'/api/admin/legacy/100',{'revision':0,'action':'draft','content':edit})[0]==403
  assert call(a,'/api/admin/legacy/100',{'revision':0,'action':'draft','content':edit},token)[0]==200
  assert '未公開の編集' not in anon.open(origin+'/old-article').read().decode()
  assert call(anon,public)[0]==404
  assert call(b,'/api/admin/legacy/100')[1]['draft']==edit
  assert call(b,'/api/admin/legacy/100',{'revision':0,'action':'release'},t)[0]==409
  assert call(a,'/api/admin/legacy/100',{'revision':1,'action':'release'},token)[0]==200
  published=anon.open(origin+'/old-article').read().decode();assert '未公開の編集' in published and '<script>alert' not in published
  assert anon.open(origin+public).status==200
  assert anon.open(origin+'/?wpdmdl=7').read()==b'legacy download'
  assert call(a,'/api/admin/legacy/100',{'revision':2,'action':'unpublish'},token)[0]==200
  assert call(anon,'/old-article')[0]==404 and call(anon,public)[0]==404
  assert call(anon,'/?wpdmdl=7')[0]==404
  assert '/old-article' not in anon.open(origin+'/sitemap.xml').read().decode()
  assert call(a,'/api/admin/legacy/100')[1]['draft']==edit
  assert '<script>' not in call(a,'/api/admin/legacy-preview',{'html':'<p>本文</p><script>bad()</script>'},token)[1]['html']
  # Contact messages never have a public read or approve route.
  ct=call(anon,'/api/session')[1]['csrf']
  assert call(anon,'/api/public/contact',{'body':'無効な受付'},ct)[0]==404
  contact_page=dict(legacy,legacy_id=102,path='/contact-form',type='page',title='お問い合わせ',original_html='[contact-form-7 id="34"]')
  raw=json.dumps(contact_page);db=sqlite3.connect(p/'db.sqlite');db.execute('INSERT INTO chronicle_imports VALUES(?,?,?,?,?)',(102,'/contact-form','publish',raw,hashlib.sha256(raw.encode()).hexdigest()));db.commit();db.close()
  assert 'contactForm' in anon.open(origin+'/contact-form').read().decode()
  assert call(anon,'/api/public/contact',{'body':'秘密の問い合わせ'})[0]==403
  assert call(anon,'/api/public/contact',{'body':'秘密の問い合わせ','email':'test@example.test','status':'public'},ct)[0]==202
  assert call(anon,'/api/public/contact')[0]==401
  assert call(anon,'/api/admin/contact')[0]==401
  assert anon.open(origin+'/admin/contact').geturl()==origin+'/login'
  received=call(a,'/api/admin/contact')[1]['messages'][0];assert received['body']=='秘密の問い合わせ' and received['status']=='unread'
  for path in ['/api/public/posts','/contact-form','/sitemap.xml','/']:
   assert '秘密の問い合わせ' not in anon.open(origin+path).read().decode()
  assert call(a,'/api/admin/contact/'+str(received['id']),{'revision':1,'action':'read'},token)[0]==200
  assert call(a,'/api/admin/contact/'+str(received['id']),{'revision':1,'action':'archived'},token)[0]==409
  assert call(a,'/api/admin/contact/'+str(received['id']),{'revision':2,'action':'archived'},token)[0]==200
  assert call(a,'/api/admin/contact/'+str(received['id']),{'revision':3,'action':'unread'},token)[0]==200
  # Comments use the same anonymous CSRF protection and never expose pending text.
  status,created,_=call(a,'/api/admin/posts',doc,token);commentpost=str(created['post']['id'])
  assert call(a,'/api/admin/posts/'+commentpost,{'action':'release','revision':1},token)[0]==200
  ct=call(anon,'/api/session')[1]['csrf']
  submitted={'post':commentpost,'body':'未承認の匿名コメント','status':'approved','id':999}
  assert call(anon,'/api/public/comments',submitted)[0]==403
  assert call(anon,'/api/public/comments',submitted,ct,{'Origin':'https://wrong.invalid'})[0]==403
  assert call(anon,'/api/public/comments',submitted,ct)[0]==202
  assert call(anon,'/api/public/comments?post='+commentpost)[1]['comments']==[]
  assert call(anon,'/api/admin/comments')[0]==401
  pending=call(a,'/api/admin/comments')[1]['comments'][0];cid=str(pending['id'])
  assert pending['name']=='' and cid!='999'
  assert call(a,'/api/admin/comments/'+cid,{'revision':1,'action':'reply','reply':'先に返信'},token)[0]==409
  assert call(a,'/api/admin/comments/'+cid,{'revision':1,'action':'approve'},token)[0]==200
  assert call(anon,'/api/public/comments?post='+commentpost)[1]['comments'][0]['body']==submitted['body']
  assert call(a,'/api/admin/comments/'+cid,{'revision':2,'action':'reply','reply':'返信'},token)[0]==200
  assert call(anon,'/api/public/comments?post='+commentpost)[1]['comments'][0]['reply']=='返信'
  assert call(a,'/api/admin/comments/'+cid,{'revision':3,'action':'delete'},token)[0]==200
  assert call(anon,'/api/public/comments?post='+commentpost)[1]['comments']==[]
  for _ in range(4):assert call(anon,'/api/public/comments',submitted,ct)[0]==202
  assert call(anon,'/api/public/comments',submitted,ct)[0]==429
  assert a.open(origin+'/admin/comments').status==200
  assert anon.open(origin+'/admin/comments').geturl()==origin+'/login'
  assert call(a,'/api/logout',{},token)[0]==200
  assert call(a,f'/api/admin/posts/{id}')[0]==401
  t=call(anon,'/api/session')[1]['csrf']
  for _ in range(8):assert call(anon,'/api/login',{'password':'wrong'},t)[0]==401
  assert call(anon,'/api/login',{'password':'wrong'},t)[0]==429
  # Deployment auditor also checks anonymous denial without cookies or login.
  import importlib.util
  spec=importlib.util.spec_from_file_location('http_audit',root.parent/'scripts/audit-http.py');auditor=importlib.util.module_from_spec(spec);spec.loader.exec_module(auditor)
  result=auditor.audit(origin,origin,[{'url':origin+'/'}],indexing='on',local=True)
  assert result['failed']==0,result
  # A restore target cannot serve HTTP while offline_restore is enabled.
  restore_cfg=json.loads((p/'config.json').read_text());restore_cfg['offline_restore']=True;(p/'config.json').write_text(json.dumps(restore_cfg))
  assert call(anon,'/api/public/posts')[0]==503
  print('HTTP認証・CSRF・2セッション・公開分離・競合・ログアウト・試行制限の検証成功')
 finally:server.terminate();server.wait(timeout=5)
