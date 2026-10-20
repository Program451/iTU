const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<textarea id="draft">Незавершённый текст</textarea>',{url:'https://example.test/admin.php',runScripts:'outside-only'});
 const w=dom.window,run=s=>vm.runInContext(s,dom.getInternalVMContext());
 const token='a'.repeat(64),next='b'.repeat(64),boot={profile:{id:17},csrf:'old',upload_limit:100};
 w.ADMIN_BOOT=boot;w.I18n={t:s=>s};w.BOOT={csrf:'user-token'};
 for(const file of ['announcement-client.js','lessons-ui.js'])run(fs.readFileSync('public/'+file,'utf8'));
 const reply=(body,status=200)=>({ok:status<400,status,json:async()=>body});
 const session=(csrf=token)=>reply({csrf,admin_id:17,upload_limit:100});
 let calls=[];
 const mock=handler=>{calls=[];w.fetch=async(url,options)=>{calls.push({url,options});return handler(url,options);};};
 const video=new w.File(['valid-test-video'],'intro.mp4',{type:'video/mp4'});
 mock((url,options)=>url.includes('?session=')?session():reply({id:'media'}));
 assert.equal((await w.announcementRequest('admin_upload',{},video)).id,'media');
 assert.equal(calls.length,2);assert.equal(calls[0].options.headers['X-Admin-Id'],'17');
 assert.equal(calls[1].options.headers['X-CSRF-Token'],token);assert.equal(calls[1].options.headers['X-Admin-Id'],'17');
 assert.equal(calls[1].options.body.get('video'),video);assert.equal(w.document.querySelector('#draft').value,'Незавершённый текст');
 // Account changes and expired sign-in stop before sending a large file.
 for(const response of [reply({error:'admin_session_changed'},409),reply({error:'auth_required'},401),reply({csrf:token,admin_id:18,upload_limit:100})]){
  mock(()=>response);await assert.rejects(w.announcementRequest('admin_upload',{},video));assert.equal(calls.length,1);
 }
 mock(()=>session());await assert.rejects(w.announcementRequest('admin_upload',{},new w.File(['x'.repeat(101)],'large.mp4')));assert.equal(calls.length,1);
 // One safe retry after explicit CSRF rejection; never retry uncertain writes.
 let reads=0,writes=0;
 mock((url,options)=>url.includes('?session=')?session(++reads===1?token:next):++writes===1?reply({error:'session_expired'},403):reply({id:'media'}));
 await w.announcementRequest('admin_upload',{},video);assert.equal(writes,2);assert.equal(calls[3].options.headers['X-CSRF-Token'],next);
 mock(url=>url.includes('?session=')?session():reply({error:'session_expired'},403));
 await assert.rejects(w.announcementRequest('admin_upload',{},video));assert.equal(calls.length,4,'Retry must be bounded');
 mock(url=>{if(url.includes('?session='))return session();throw Error('Network lost');});
 await assert.rejects(w.announcementRequest('admin_upload',{},video));assert.equal(calls.length,2);
 mock(url=>url.includes('?session=')?session():{status:413,ok:false,json:async()=>{throw Error('HTML response');}});
 await assert.rejects(w.announcementRequest('admin_upload',{},video),/лимит сервера/);assert.equal(calls.length,2);
 mock(url=>url.includes('?session=')?session():{status:502,ok:false,json:async()=>{throw Error('HTML response');}});
 await assert.rejects(w.announcementRequest('admin_upload',{},video));assert.equal(calls.length,2);
 writes=0;
 mock(url=>url.includes('?session=')?session(next):++writes===1?reply({error:'Обновите страницу',code:'session_expired'},403):reply({id:3}));
 assert.equal((await w.lessonRequest('admin_save',{title_ru:'Урок'})).id,3);assert.equal(calls.length,4);
 assert.equal(calls[3].options.headers['X-Admin-Id'],'17');assert.equal(calls[3].options.headers['X-CSRF-Token'],next);
 delete w.ADMIN_BOOT;mock(()=>reply({items:[]}));await w.announcementRequest('pending');await w.lessonRequest('list');
 assert(calls.every(c=>c.options.headers['X-CSRF-Token']==='user-token'));assert(calls.every(c=>!c.url.includes('?session=')));
 dom.window.close();console.log('PASS stale admin token recovery, upload preflight, account isolation, preserved text, bounded safe retries, server limits and lesson saves.');
})().catch(error=>{console.error(error);process.exitCode=1;});
