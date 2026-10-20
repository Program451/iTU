/* Shared by the business workspace and the separate administration console. */
const announcementEscape = value => String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const announcementErrors = {
 auth_required:'Войдите в аккаунт заново.',session_expired:'Сессия обновилась. Перезагрузите страницу.',admin_required:'Доступно только администратору.',
 announcement_invalid:'Проверьте поля объявления.',announcement_required:'Заполните заголовок и текст на русском.',announcement_bilingual:'Перед публикацией заполните русский и казахский тексты.',
 announcement_immutable:'Опубликованный текст нельзя изменить. Создайте копию.',announcement_unavailable:'Объявление больше недоступно.',announcement_video_required:'Перед публикацией загрузите видео.',
 video_unavailable:'Видео не найдено.',video_upload_failed:'Видео не загрузилось. Проверьте размер файла и лимиты сервера.',video_too_large:'Размер видео должен быть не больше 100 МБ.',video_invalid:'Выберите видео MP4 или WebM.',video_storage_full:'Хранилище видео заполнено. Обратитесь к владельцу сервера.',profile_invalid:'Укажите имя длиной до 100 символов.',service_unavailable:'Сервис временно недоступен. Повторите позже.',request_invalid:'Некорректный запрос.',method_invalid:'Некорректный запрос.'
};
function announcementText(text){return window.I18n?I18n.t(text):text;}
async function announcementRequest(action,data={},file=null){
 const csrf=typeof ADMIN_BOOT!=='undefined'?ADMIN_BOOT.csrf:BOOT.csrf;
 let body,headers={'X-CSRF-Token':csrf};
 if(file){body=new FormData();body.append('action',action);body.append('video',file);}else{headers['Content-Type']='application/json';body=JSON.stringify({action,...data});}
 const response=await fetch('announcement-api.php',{method:'POST',headers,body});let result;
 try{result=await response.json();}catch{throw Error(announcementText('Сервер вернул неверный ответ. Обновите страницу.'));}
 if(!response.ok||result.error)throw Error(announcementText(announcementErrors[result.error]||announcementErrors.service_unavailable));return result;
}
function announcementMarkup(item,{preview=false,pending=false}={}){
 const language=window.I18n?.language||'ru',e=announcementEscape,t=announcementText;
 const title=item['title_'+language]||item.title_ru,body=item['body_'+language]||item.body_ru;
 const date=item.published_at?new Date(item.published_epoch?Number(item.published_epoch)*1000:item.published_at.replace(' ','T')+'Z').toLocaleDateString(language==='kk'?'kk-KZ':'ru-KZ',{timeZone:'Asia/Qyzylorda',day:'numeric',month:'long',year:'numeric'}):'';
 return '<div class="announcement-top"><span class="announcement-kicker">'+e(t(item.kind==='welcome'?'ДОБРО ПОЖАЛОВАТЬ':'ЧТО НОВОГО'))+'</span><button class="announcement-close" type="button" aria-label="'+e(t('Закрыть'))+'">×</button></div><div class="announcement-heading"><span class="announcement-symbol">'+(item.kind==='welcome'?'✳':'↗')+'</span><h2 id="announcementTitle">'+e(title)+'</h2></div>'+(item.version||date?'<p class="announcement-meta">'+[item.version,date].filter(Boolean).map(e).join(' · ')+'</p>':'')+'<div class="announcement-copy" translate="no">'+e(body)+'</div>'+(item.media_id?'<video class="announcement-video" controls playsinline preload="metadata" aria-label="'+e(t('Видео от администратора'))+'" src="announcement-media.php?id='+encodeURIComponent(item.media_id)+'"></video><p class="announcement-video-error" role="alert" hidden>'+e(t('Не удалось воспроизвести видео. Попробуйте открыть его отдельно.'))+' <a target="_blank" rel="noopener" href="announcement-media.php?id='+encodeURIComponent(item.media_id)+'">'+e(t('Открыть видео'))+'</a></p>':'<div class="announcement-video-empty">▷ <span>'+e(t('Здесь появится загруженное видео'))+'</span></div>')+'<p class="announcement-error" role="alert"></p><div class="announcement-footer"><small>'+e(t(preview?'Предпросмотр объявления.':pending?(item.kind==='welcome'?'Покажем это приветствие только один раз.':'После закрытия это обновление больше не появится автоматически.'):'Вы можете вернуться к этому обзору в любое время.'))+'</small><button type="button" class="admin-btn announcement-done">'+e(t(item.kind==='welcome'?'Начать работу':'Понятно, спасибо'))+' <span>↗</span></button></div>';
}
let announcementQueue=[],announcementCurrent=null,announcementBusy=false,announcementHistory=false;
function announcementDialog(){let d=document.getElementById('announcementDialog');if(!d){d=document.createElement('dialog');d.id='announcementDialog';d.className='announcement-dialog';d.setAttribute('aria-labelledby','announcementTitle');document.body.append(d);}return d;}
function announcementShow(item,{preview=false,pending=false}={}){
 const dialog=announcementDialog();announcementCurrent=item;
 dialog.innerHTML=announcementMarkup(item,{preview,pending});
 const close=async()=>{
  if(announcementBusy)return;announcementBusy=true;
  dialog.querySelectorAll('button').forEach(b=>b.disabled=true);
  try{if(pending)await announcementRequest('acknowledge',{id:Number(item.id)});dialog.querySelector('video')?.pause();dialog.close();announcementCurrent=null;if(pending){announcementQueue.shift();announcementNext();}}
  catch(e){dialog.querySelector('.announcement-error').textContent=e.message;}
  finally{announcementBusy=false;dialog.querySelectorAll('button').forEach(b=>b.disabled=false);}
 };
 dialog.querySelectorAll('.announcement-close,.announcement-done').forEach(b=>b.onclick=close);
 dialog.oncancel=e=>{e.preventDefault();close();};
 dialog.querySelector('video')?.addEventListener('error',()=>{dialog.querySelector('.announcement-video-error').hidden=false;});
 if(!dialog.open)dialog.showModal();
}
function announcementNext(){if(!announcementQueue.length)return;const other=[...document.querySelectorAll('dialog[open]')].find(d=>d.id!=='announcementDialog');if(other){other.addEventListener('close',announcementNext,{once:true});return;}announcementShow(announcementQueue[0],{pending:true});}
async function announcementStart(){try{announcementQueue=(await announcementRequest('pending')).items;announcementNext();}catch(e){console.warn('Announcements unavailable');const b=document.getElementById('announcementHistoryButton');if(b)b.title=e.message;}}
async function announcementOpenHistory(){try{const rows=(await announcementRequest('history')).items;if(!rows.length){toast(announcementText('Пока нет обновлений.'));return;}modal(announcementText('Обновления платформы'),'<div class="announcement-history">'+rows.map(r=>'<button type="button" class="btn light" data-announcement="'+Number(r.id)+'">'+announcementEscape(r['title_'+I18n.language]||r.title_ru)+'</button>').join('')+'</div>');document.querySelectorAll('[data-announcement]').forEach(b=>b.onclick=()=>{document.getElementById('modal').close();announcementShow(rows.find(r=>Number(r.id)===Number(b.dataset.announcement)));});}catch(e){toast(e.message);}}
