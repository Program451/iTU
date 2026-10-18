let socialPosts=[],socialEditing=null,socialPhoto=null,socialRequestKey='',socialBusy=false,socialShowArchived=false;
const socialStatuses={draft:'Черновик',queued:'В очереди',processing:'Готовится в Instagram',publishing:'Отправляется',published:'Опубликован',uncertain:'Нужно проверить результат',failed:'Требует внимания',archived:'В архиве'};
function socialDate(value){return value?new Intl.DateTimeFormat('ru-RU',{dateStyle:'short',timeStyle:'short',timeZone:'Asia/Qyzylorda'}).format(new Date(value.replace(' ','T')+'Z')):'—';}
function socialWorkerStatus(){const value=state.worker_last_seen;if(!value)return 'Планировщик ещё не подтвердил запуск.';const age=Date.now()-Date.parse(value.replace(' ','T')+'Z');return (age>=-120000&&age<180000?'Планировщик активен.':'Планировщик давно не подтверждал запуск — проверьте cron на хостинге.')+' Последний запуск: '+socialDate(value)+' · UTC+5.';}
function socialStudioPage(){
    if(opsState.role!=='owner')return aiWorkspace('marketing');
    socialEditing=null;socialPhoto=null;socialShowArchived=false;socialRequestKey=crypto.randomUUID();
    return head('Контент-студия · '+(state.profile||BOOT.profile).label,'Карточки, темы и сценарии под ваш бизнес.')+businessContentPanel()+
        '<div class="notice"><b>Карточки без платной генерации.</b> Изображение собирается из вашего текста и фото прямо в браузере. Это оформление по шаблону, а не нейросетевая генерация фотографии. Публикуются только подтверждённые вами материалы.</div>'+
        '<div class="grid twocol" style="align-items:start;margin-top:20px"><section class="card"><div class="sectiontitle"><h2 id="socialEditorTitle">Новый пост</h2><span class="badge">Instagram</span></div>'+
        '<p class="hint">Выберите товар или заполните предложение для своей сферы: услуга, свободная запись, новинка, событие.</p>'+
        '<div class="field"><label for="socialCatalog">Взять из каталога</label><select id="socialCatalog" onchange="socialUseCatalog(this.value)"><option value="">Своя идея</option>'+businessCatalog().map(x=>'<option value="'+Number(x.id)+'">'+esc(x.item_name)+(x.details?.data_mode==='demo'?' · пример':'')+'</option>').join('')+'</select></div>'+
        '<form id="socialForm" onsubmit="socialSaveDraft(event)"><div class="fields">'+
        socialInput('brand','Название бизнеса',BOOT.biz,70)+socialInput('headline','Заголовок карточки','',100)+socialInput('detail','Короткое описание','',160)+socialInput('price','Цена или условия','',40)+socialInput('cta','Призыв к действию',businessCta(),70)+
        '<div class="field"><label for="socialTheme">Оформление</label><select id="socialTheme"><option value="forest">Лесной зелёный</option><option value="cream">Тёплый минимализм</option><option value="night">Ночной индиго</option></select></div>'+
        '<div class="field"><label for="socialLayout">Композиция</label><select id="socialLayout" onchange="socialDraw()"><option value="card">Карточка с фото</option><option value="photo">Фото на весь пост</option></select></div><div class="field"><label for="socialLanguage">Язык шаблона</label><select id="socialLanguage"><option value="ru">Русский</option><option value="kk">Қазақша</option></select><small>Ваш текст сохраняется на языке ввода.</small></div>'+
        '<div class="field"><label for="socialPhoto">Фото товара или услуги</label><input id="socialPhoto" type="file" accept="image/jpeg,image/png,image/webp" onchange="socialLoadPhoto(this)"></div></div>'+
        '<p id="socialSource" class="hint"></p><div class="actions"><button type="button" class="btn light" onclick="socialBuild()">Собрать изображение и подпись</button><button type="button" class="btn light" onclick="socialPhoto=null;socialDraw()">Без фото</button></div>'+
        '<div class="field" style="margin-top:20px"><label for="socialCaption">Текст публикации</label><textarea id="socialCaption" maxlength="2200" rows="7" required oninput="socialCount()"></textarea><small id="socialCount">0 / 2200</small></div>'+
        '<div class="actions"><button type="submit" class="btn" id="socialSave">Сохранить черновик</button><button type="button" class="btn light" onclick="render()">Новый пост</button></div><p class="hint">Сохранение не публикует пост. Перед отправкой появится отдельный предпросмотр. Если меняете заголовок или фото, пересоберите изображение.</p></form></section>'+
        '<section class="card"><div class="sectiontitle"><h2>Предпросмотр</h2><span class="hint">1080 × 1350</span></div><canvas id="socialCanvas" width="1080" height="1350" aria-label="Предпросмотр изображения Instagram" style="width:100%;height:auto;border-radius:16px;background:#f4f0e6"></canvas><p class="hint">Цена, доступность и условия должны соответствовать реальному предложению. Примеры каталога помечаются на изображении и в подписи.</p><button class="btn light" type="button" onclick="socialDownload()">Скачать JPEG</button></section></div>'+
        '<section class="card" style="margin-top:24px"><div class="sectiontitle"><h2>Посты и расписание</h2><button class="btn light small" type="button" onclick="socialRefresh()">Обновить статусы</button></div><p class="hint">Время Казахстана, UTC+5. Планировщик запускается раз в минуту; подготовка в Instagram может занять несколько минут. При истечении токена отправка останавливается.</p><p><label><input type="checkbox" onchange="socialShowArchived=this.checked;socialRenderList()"> Показать архив</label></p><p class="hint">'+esc(socialWorkerStatus())+'</p><div id="socialPostList">Загружаем ваши посты…</div></section>'+
        creativeHelpPanel()+'<details style="margin-top:24px"><summary>Другие инструменты для текстов и идей</summary>'+aiWorkspace('marketing')+'</details>';
}
function socialInput(key,label,value,max){return '<div class="field"><label for="social_'+key+'">'+label+'</label><input id="social_'+key+'" maxlength="'+max+'" value="'+esc(value)+'" '+(key==='headline'?'required':'')+'></div>';}
function socialDesign(){return {brand:$('social_brand').value.trim(),headline:$('social_headline').value.trim(),detail:$('social_detail').value.trim(),price:$('social_price').value.trim(),cta:$('social_cta').value.trim(),layout:$('socialLayout').value,theme:$('socialTheme').value,language:$('socialLanguage').value,demo:$('socialSource').dataset.demo==='1',source_label:$('socialSource').textContent};}
function socialUseCatalog(id){
    const item=businessCatalog().find(x=>Number(x.id)===Number(id));
    if(!item)return;
    if(item.details?.availability==='out_of_stock'){toast('Позиции нет в наличии. Сначала уточните наличие в каталоге.');$('socialCatalog').value='';return;}
    $('social_headline').value=item.item_name.slice(0,100);$('social_price').value=money(item.item_price);$('social_detail').value=(item.details?.description||'').slice(0,160);
    const demo=item.details?.data_mode==='demo';$('socialSource').dataset.demo=demo?'1':'0';
    $('socialSource').textContent=demo?'Демонстрационные данные — не реальное предложение.':'Источник: '+item.item_name+'. Проверьте наличие и актуальность цены.';
    socialBuild();
}
function socialCount(){if($('socialCount'))$('socialCount').textContent=Array.from($('socialCaption').value).length+' / 2200';}
function socialBuild(){
    const d=socialDesign();if(!d.headline){toast('Добавьте заголовок карточки');return;}
    const kk=d.language==='kk';
    $('socialCaption').value=[d.demo?(kk?'ДЕМОНСТРАЦИЯ · нақты ұсыныс емес':'ДЕМОНСТРАЦИЯ · не реальное предложение'):'',d.brand,d.headline,d.detail,d.price?(kk?'Бағасы / шарттары: ':'Цена / условия: ')+d.price:'',d.cta].filter(Boolean).join('\n\n');socialCount();socialDraw();
}
async function socialLoadPhoto(input){
    const file=input.files[0];if(!file)return;
    if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>10*1024*1024){input.value='';toast('Загрузите JPEG, PNG или WebP до 10 МБ');return;}
    const url=URL.createObjectURL(file),img=new Image();
    try{await new Promise((resolve,reject)=>{img.onload=resolve;img.onerror=reject;img.src=url;});if(img.naturalWidth*img.naturalHeight>24000000)throw Error('Фото до 24 мегапикселей');socialPhoto=img;socialDraw();}
    catch(e){toast(e.message||'Не удалось открыть фото');}finally{URL.revokeObjectURL(url);input.value='';}
}
function socialLines(ctx,text,maxWidth){
    const lines=[];let line='';
    for(const word of String(text).split(/\s+/)){
        if(ctx.measureText((line?line+' ':'')+word).width<=maxWidth){line+=(line?' ':'')+word;continue;}
        if(line){lines.push(line);line='';}
        for(const char of word){if(ctx.measureText(line+char).width>maxWidth&&line){lines.push(line);line='';}line+=char;}
    }
    if(line)lines.push(line);return lines;
}
function socialText(ctx,text,x,y,width,size,maxLines,color){
    let lines=[];do{ctx.font='600 '+size+'px Arial, sans-serif';lines=socialLines(ctx,text,width);if(lines.length<=maxLines)break;size-=2;}while(size>18);
    ctx.fillStyle=color;lines.slice(0,maxLines).forEach((line,i)=>ctx.fillText(line,x,y+i*size*1.18));return y+Math.min(lines.length,maxLines)*size*1.18;
}
function socialDraw(){
    const canvas=$('socialCanvas');if(!canvas)return;const d=socialDesign(),ctx=canvas.getContext('2d');
    const palette={forest:['#102d23','#d7f56a','#f5f2e9','#213e33'],cream:['#f4eddf','#854c2e','#352c24','#e4d6bf'],night:['#1d2042','#c4b5fd','#f5f3ff','#343659']}[d.theme]||['#102d23','#d7f56a','#f5f2e9','#213e33'];
    const [bg,accent,fg,soft]=palette;ctx.fillStyle=bg;ctx.fillRect(0,0,1080,1350);
    if(d.layout==='photo'&&socialPhoto){
        const scale=Math.max(1080/socialPhoto.naturalWidth,1350/socialPhoto.naturalHeight),w=socialPhoto.naturalWidth*scale,h=socialPhoto.naturalHeight*scale;ctx.drawImage(socialPhoto,(1080-w)/2,(1350-h)/2,w,h);
        const shade=ctx.createLinearGradient(0,0,0,1350);shade.addColorStop(0,'rgba(0,0,0,.84)');shade.addColorStop(.35,'rgba(0,0,0,.08)');shade.addColorStop(.60,'rgba(0,0,0,.05)');shade.addColorStop(1,'rgba(0,0,0,.92)');ctx.fillStyle=shade;ctx.fillRect(0,0,1080,1350);
        socialText(ctx,d.brand,70,85,940,30,2,'#fff');socialText(ctx,d.headline,70,195,940,70,3,'#fff');socialText(ctx,d.detail,70,975,940,32,3,'#fff');socialText(ctx,d.price,70,1145,940,58,1,accent);socialText(ctx,d.cta,70,1240,940,29,2,'#fff');
        if(d.demo){ctx.fillStyle='#ffdf80';ctx.fillRect(0,1298,1080,52);socialText(ctx,'ДЕМОНСТРАЦИЯ · НЕ РЕАЛЬНОЕ ПРЕДЛОЖЕНИЕ',45,1332,990,24,1,'#302511');}return;
    }
    ctx.fillStyle=accent;ctx.fillRect(70,65,42,8);socialText(ctx,d.brand||'Ваш бизнес',135,82,850,30,2,fg);
    let y=180;y=socialText(ctx,d.headline||'Ваше следующее\nпредложение',70,y,940,64,3,fg)+20;
    if(d.detail)y=socialText(ctx,d.detail,70,y,940,30,3,fg)+24;
    const photoTop=570,photoHeight=480;
    ctx.save();ctx.beginPath();ctx.roundRect(70,photoTop,940,photoHeight,28);ctx.clip();
    if(socialPhoto){const scale=Math.max(940/socialPhoto.naturalWidth,photoHeight/socialPhoto.naturalHeight),w=socialPhoto.naturalWidth*scale,h=socialPhoto.naturalHeight*scale;ctx.drawImage(socialPhoto,70+(940-w)/2,photoTop+(photoHeight-h)/2,w,h);}
    else{
        ctx.fillStyle=soft;ctx.fillRect(70,photoTop,940,photoHeight);ctx.fillStyle=accent;ctx.beginPath();ctx.arc(800,760,230,0,Math.PI*2);ctx.fill();ctx.fillStyle=bg;ctx.beginPath();ctx.arc(800,760,148,0,Math.PI*2);ctx.fill();ctx.fillStyle=accent;ctx.fillRect(120,860,290,14);ctx.fillRect(120,900,210,14);socialText(ctx,(d.brand||'AulieAta').charAt(0).toUpperCase(),120,800,420,210,1,fg);
    }ctx.restore();
    socialText(ctx,d.price||'Узнайте подробности',70,1145,940,58,1,accent);socialText(ctx,d.cta,70,1240,940,29,2,fg);
    if(d.demo){ctx.fillStyle='#ffdf80';ctx.fillRect(0,1298,1080,52);ctx.font='bold 22px Arial';ctx.fillStyle='#302511';ctx.textAlign='center';ctx.fillText('ДЕМОНСТРАЦИЯ · НЕ РЕАЛЬНОЕ ПРЕДЛОЖЕНИЕ',540,1332);ctx.textAlign='left';}
}
function socialDownload(){const a=document.createElement('a');a.href=$('socialCanvas').toDataURL('image/jpeg',0.9);a.download='instagram-post.jpg';a.click();}
async function socialRefresh(){
    try{socialPosts=(await api('social_list')).posts;socialRenderList();}catch(e){if($('socialPostList'))$('socialPostList').textContent=e.message;}
}
function socialRenderList(){
    const target=$('socialPostList');if(!target)return;
    const shown=socialPosts.filter(p=>socialShowArchived||p.status!=='archived');target.innerHTML=shown.length?shown.map(p=>'<article class="row" style="align-items:start;gap:16px"><img src="'+esc(p.image_url)+'" alt="'+esc(p.title)+'" width="88" height="110" style="object-fit:cover;border-radius:10px"><div style="flex:1;min-width:0"><b>'+esc(p.title)+'</b><small>'+esc(socialStatuses[p.status]||p.status)+(p.account_name?' · @'+esc(p.account_name):'')+'</small>'+(p.due_at?'<small>'+socialDate(p.due_at)+' · UTC+5</small>':'')+(p.last_error?'<p class="notice">'+esc(p.last_error)+'</p>':'')+'<div class="actions"><button class="btn light small" onclick="socialPreview('+p.id+')">Предпросмотр</button>'+(p.status==='draft'?'<button class="btn light small" onclick="socialEdit('+p.id+')">Изменить</button><button class="btn small" onclick="socialApprove('+p.id+')">Публикация / время</button><button class="btn light small" onclick="socialChangePost('+p.id+',\'archive\')">В архив</button>':'')+(['queued','processing','failed'].includes(p.status)?'<button class="btn light small" onclick="socialChangePost('+p.id+',\'cancel\')">Вернуть в черновики</button>':'')+(p.status==='archived'?'<button class="btn light small" onclick="socialChangePost('+p.id+',\'restore\')">Восстановить</button>':'')+(p.permalink?'<a class="btn light small" target="_blank" rel="noopener noreferrer" href="'+esc(p.permalink)+'">Открыть пост ↗</a>':'')+'</div></div></article>').join(''):empty('Сохраните первый пост. Он появится здесь до публикации.');
}
function socialPreview(id){const p=socialPosts.find(x=>x.id===id);if(!p)return;modal(p.title,'<img src="'+esc(p.image_url)+'" alt="Изображение поста" style="width:100%;max-height:55vh;object-fit:contain;border-radius:14px"><p class="result">'+esc(p.caption)+'</p><p>'+esc(socialStatuses[p.status])+'</p><button class="btn light" onclick="$(\'modal\').close()">Закрыть</button>');}
async function socialEdit(id){
    const p=socialPosts.find(x=>x.id===id);if(!p||p.status!=='draft')return;
    socialEditing=p;socialPhoto=null;socialRequestKey=crypto.randomUUID();
    for(const key of ['brand','headline','detail','price','cta'])$('social_'+key).value=p.design[key]||'';
    $('socialLayout').value=p.design.layout||'card';$('socialTheme').value=p.design.theme||'forest';$('socialLanguage').value=p.design.language||'ru';$('socialSource').textContent=p.design.source_label||'';$('socialSource').dataset.demo=p.design.demo?'1':'0';$('socialCaption').value=p.caption;socialCount();$('socialEditorTitle').textContent='Редактирование поста';
    const img=new Image();try{await new Promise((resolve,reject)=>{img.onload=resolve;img.onerror=reject;img.src=p.image_url;});$('socialCanvas').getContext('2d').drawImage(img,0,0,1080,1350);$('socialForm').scrollIntoView({behavior:'smooth',block:'start'});toast('Сохранённое изображение загружено. Для новой композиции добавьте фото заново.');}catch(e){toast('Не удалось загрузить изображение');}
}
async function socialSaveDraft(event){
    event.preventDefault();if(socialBusy)return;socialBusy=true;$('socialSave').disabled=true;
    try{const d=socialDesign();const result=await api('social_save',{id:socialEditing?.id||0,version:socialEditing?.version||0,request_key:socialRequestKey,title:d.headline,caption:$('socialCaption').value,design:d,image:$('socialCanvas').toDataURL('image/jpeg',0.88)});socialEditing=result.post;socialRequestKey=crypto.randomUUID();await socialRefresh();toast('Черновик сохранён. Для отправки нажмите «Публикация / время».');}
    catch(e){toast(e.message);}finally{socialBusy=false;if($('socialSave'))$('socialSave').disabled=false;}
}
function socialApprove(id){
    const p=socialPosts.find(x=>x.id===id),c=state.instagram;if(!p)return;
    if(!c||c.check_status!=='verified'){toast('Проверьте подключение Instagram в настройках');return;}
    modal('Публикация в @'+c.username,'<img src="'+esc(p.image_url)+'" alt="Изображение для публикации" style="width:100%;max-height:38vh;object-fit:contain;border-radius:12px"><p class="result">'+esc(p.caption)+'</p><form onsubmit="socialEnqueue(event,'+id+','+p.version+',\''+esc(c.account_id)+'\')"><div class="field"><label for="socialSchedule">Время публикации · Казахстан, UTC+5</label><input id="socialSchedule" type="datetime-local" name="scheduled_at"><small>Оставьте пустым, чтобы отправить в ближайший запуск планировщика. До 30 дней вперёд.</small></div><p><label><input type="checkbox" name="approved" required> Я проверил изображение, текст, цены и наличие, имею право использовать фото и разрешаю публикацию в @'+esc(c.username)+'.</label></p><p class="hint">Материал будет публичным в Instagram. После начала отправки отмена может быть недоступна. Реклама не запускается.</p><div class="actions"><button class="btn" type="submit">Подтвердить публикацию</button><button class="btn light" type="button" onclick="$(\'modal\').close()">Назад</button></div></form>');
}
async function socialEnqueue(event,id,version,account_id){event.preventDefault();const f=event.target,b=f.querySelector('button');b.disabled=true;try{await api('social_queue',{id,version,account_id,approved:f.elements.approved.checked,scheduled_at:f.elements.scheduled_at.value});$('modal').close();await socialRefresh();toast('Пост поставлен в очередь. Статус появится в списке.');}catch(e){toast(e.message);}finally{b.disabled=false;}}
async function socialChangePost(id,action){try{await api('social_'+action,{id});await socialRefresh();if(socialEditing?.id===id){socialEditing=null;socialRequestKey=crypto.randomUUID();}toast(action==='archive'?'Черновик перемещён в архив':action==='restore'?'Черновик восстановлен':'Отправка отменена. Пост снова в черновиках.');}catch(e){toast(e.message);}}
function socialMount(){if(current==='marketing'&&opsState.role==='owner'){const p=businessPromotion();$('socialTheme').value=p.theme;$('socialLanguage').value=p.language;socialDraw();socialRefresh();}}
