const lessonTopics={general:'Начало работы',cloudflare:'Cloudflare · изображения',gemini:'Gemini · помощник',instagram:'Instagram',tiktok:'TikTok',telegram:'Telegram'};
let lessonItems=[],lessonFilter='all',lessonQuery='',lessonRequestVersion=0;
async function lessonRequest(action,data={}){
    const admin=typeof ADMIN_BOOT!=='undefined'&&action.startsWith('admin_');
    if(admin)await announcementAdminSession();
    return lessonSend(action,data,admin);
}
async function lessonSend(action,data,admin,retried=false){
    const csrf=typeof ADMIN_BOOT!=='undefined'?ADMIN_BOOT.csrf:BOOT.csrf;
    const headers={'Content-Type':'application/json','X-CSRF-Token':csrf};
    if(admin)headers['X-Admin-Id']=String(ADMIN_BOOT.profile.id);
    const response=await fetch('lessons-api.php',{method:'POST',headers,body:JSON.stringify({action,...data})});
    let result;try{result=await response.json();}catch(_){throw Error(I18n.t('Не удалось загрузить уроки. Попробуйте позже.'));}
    if(admin&&!retried&&response.status===403&&result.code==='session_expired'){await announcementAdminSession();return lessonSend(action,data,admin,true);}
    if(!response.ok||result.error)throw Error(I18n.t(result.error||'Не удалось загрузить уроки. Попробуйте позже.'));return result;
}
function lessonText(row,key){return row[key+'_'+I18n.language]||row[key+'_ru']||'';}
function lessonsPage(){
    return head('Видеоуроки · подключение API','Подключайте сервисы шаг за шагом. Инструкции и видео от администратора.')+
        '<section class="lesson-hero"><div><span class="badge">'+esc(I18n.t('Библиотека знаний'))+'</span><h2>'+esc(I18n.t('Сначала посмотрите. Затем подключите.'))+'</h2><p>'+esc(I18n.t('Выберите сервис, посмотрите урок и вернитесь к настройкам. Пройденные уроки сохраняются в вашем аккаунте.'))+'</p><a class="btn" href="#settings">'+esc(I18n.t('К подключениям'))+' ↗</a></div><div class="lesson-orbit" aria-hidden="true"><span>▷</span><i>✦</i></div></section>'+
        '<section class="card lesson-library"><div class="lesson-tools"><div class="field"><label for="lessonSearch">'+esc(I18n.t('Найти урок'))+'</label><input id="lessonSearch" type="search" value="'+esc(lessonQuery)+'" oninput="lessonQuery=this.value;lessonsRenderList()"></div><div class="field"><label for="lessonTopic">'+esc(I18n.t('Сервис'))+'</label><select id="lessonTopic" onchange="lessonFilter=this.value;lessonsRenderList()"><option value="all">'+esc(I18n.t('Все уроки'))+'</option>'+Object.entries(lessonTopics).map(([key,label])=>'<option value="'+key+'" '+(lessonFilter===key?'selected':'')+'>'+esc(I18n.t(label))+'</option>').join('')+'</select></div><button class="btn light" onclick="lessonsMount()">'+esc(I18n.t('Обновить'))+'</button></div><p id="lessonProgress" class="hint" aria-live="polite"></p><div id="lessonGrid" class="lesson-grid" aria-live="polite">'+esc(I18n.t('Загрузка…'))+'</div></section>';
}
async function lessonsMount(){
    const version=++lessonRequestVersion,target=$('lessonGrid');if(current!=='lessons'||!target)return;
    try{const data=await lessonRequest('list');if(version!==lessonRequestVersion||!target.isConnected)return;lessonItems=data.items;lessonsRenderList();}
    catch(e){if(version===lessonRequestVersion&&target.isConnected)target.textContent=e.message;}
}
function lessonsRenderList(){
    const target=$('lessonGrid');if(!target)return;
    const rows=lessonItems.filter(r=>(lessonFilter==='all'||r.category===lessonFilter)&&(lessonText(r,'title')+' '+lessonText(r,'body')).toLocaleLowerCase().includes(lessonQuery.toLocaleLowerCase().trim()));
    $('lessonProgress').textContent=I18n.t('Пройдено уроков')+': '+lessonItems.filter(r=>Number(r.completed)).length+' / '+lessonItems.length;
    target.innerHTML=rows.length?rows.map((r,i)=>'<article class="lesson-card"><button class="lesson-cover" onclick="lessonOpen('+Number(r.id)+')" aria-label="'+esc(I18n.t('Смотреть урок')+': '+lessonText(r,'title'))+'"><span class="lesson-number">'+String(i+1).padStart(2,'0')+'</span><span class="lesson-play" aria-hidden="true">▷</span><span class="lesson-topic">'+esc(I18n.t(lessonTopics[r.category]))+'</span></button><div class="lesson-card-body"><span class="badge">'+esc(I18n.t(Number(r.completed)?'Пройдено':'Видеоурок'))+'</span><h3 translate="no">'+esc(lessonText(r,'title'))+'</h3><p translate="no">'+esc(lessonText(r,'body').slice(0,160))+'</p><button class="btn light small" onclick="lessonOpen('+Number(r.id)+')">'+esc(I18n.t(Number(r.completed)?'Посмотреть ещё раз':'Смотреть урок'))+' ↗</button></div></article>').join(''):'<div class="lesson-empty"><span>▷</span><h3>'+esc(I18n.t(lessonItems.length?'Уроки не найдены':'Скоро здесь появятся видеоуроки'))+'</h3><p>'+esc(I18n.t(lessonItems.length?'Попробуйте другое название или сервис.':'Администратор добавит инструкции по подключению сервисов.'))+'</p></div>';
}
function lessonOpen(id){const row=lessonItems.find(r=>Number(r.id)===id);if(!row)return;lessonPlayer(row,false);}
function lessonPlayer(row,preview=false){
    const e=typeof esc==='function'?esc:announcementEscape,t=s=>e(I18n.t(s));
    let dialog=document.getElementById('lessonDialog');if(!dialog){dialog=document.createElement('dialog');dialog.id='lessonDialog';dialog.className='announcement-dialog lesson-dialog';document.body.append(dialog);}
    dialog.innerHTML='<div class="announcement-top"><span class="announcement-kicker">'+t(lessonTopics[row.category]||'Видеоурок')+'</span><button class="announcement-close" aria-label="'+t('Закрыть')+'">×</button></div><h2 id="lessonDialogTitle" translate="no">'+e(lessonText(row,'title'))+'</h2>'+(row.media_id?'<video controls playsinline preload="metadata" class="lesson-video" aria-label="'+t('Видеоурок')+'" src="announcement-media.php?id='+encodeURIComponent(row.media_id)+'"></video>':'<p>'+t('Сначала загрузите видео.')+'</p>')+'<p class="lesson-video-error" hidden role="alert">'+t('Видео не удалось загрузить. Обновите список уроков и попробуйте снова.')+'</p><div class="lesson-body" translate="no">'+e(lessonText(row,'body'))+'</div><div class="actions">'+(preview?'<span class="hint">'+t(row.status==='published'?'Опубликовано':'Предпросмотр урока. Он ещё не опубликован.')+'</span>':'<button class="btn" id="lessonComplete">'+t(Number(row.completed)?'Отметить непройденным':'Урок пройден')+'</button><a class="btn light" href="#settings" id="lessonSettings">'+t('К подключениям')+' ↗</a>')+'</div>';
    dialog.setAttribute('aria-labelledby','lessonDialogTitle');dialog.querySelector('.announcement-close').onclick=()=>dialog.close();dialog.onclose=()=>dialog.querySelector('video')?.pause();
    dialog.querySelector('video')?.addEventListener('error',()=>dialog.querySelector('.lesson-video-error').hidden=false);
    dialog.querySelector('#lessonSettings')?.addEventListener('click',()=>dialog.close());
    dialog.querySelector('#lessonComplete')?.addEventListener('click',async event=>{const b=event.currentTarget;b.disabled=true;try{const completed=!Number(row.completed);await lessonRequest('complete',{id:Number(row.id),completed});row.completed=completed?1:0;b.textContent=I18n.t(completed?'Отметить непройденным':'Урок пройден');lessonsRenderList();}catch(e){const error=dialog.querySelector('.lesson-video-error');error.hidden=false;error.textContent=e.message;}finally{b.disabled=false;}});
    if(!dialog.open)dialog.showModal();
}
