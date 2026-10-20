function instagramConnectionTool(){
    if(opsState.role!=='owner')return '';
    const c=state.instagram,ok=c?.check_status==='verified';
    const status=!c?'Не подключён':ok?'Аккаунт проверен':c.check_status==='invalid'?'Замените токен':'Проверка временно недоступна';
    const account=c?'<p><a target="_blank" rel="noopener noreferrer" href="https://www.instagram.com/'+encodeURIComponent(c.username)+'/">@'+esc(c.username)+'</a> · '+(c.account_type==='BUSINESS'?I18n.t("Компания"):I18n.t("Автор"))+I18n.html("</p><p class=\"hint\">Проверено: ")+esc(c.checked_at)+I18n.html(" (время Казахстана)</p>"):'';
    const quota=c&&c.quota_total!==null?I18n.html("<p class=\"hint\">Instagram: использовано ")+Number(c.quota_usage||0)+I18n.t(" из ")+Number(c.quota_total)+I18n.t(" публикаций через API")+(c.quota_duration?I18n.t(" за ")+Math.round(c.quota_duration/3600)+I18n.t(" ч"):'')+I18n.html(". Лимит получен при последней проверке.</p>"):'';
    return '<section class="card" id="instagramConnection" style="margin-bottom:24px"><div class="sectiontitle"><h2>Instagram</h2><span class="badge '+(ok?'':'pending')+'">'+I18n.t(status)+'</span></div>'+account+quota+
        I18n.html("<p>Подключите профессиональный аккаунт своего бизнеса. Токен хранится зашифрованным и после сохранения не показывается. Доступ к настройке есть только у владельца.</p>")+
        I18n.html("<p class=\"hint\">Создавайте карточки и подтверждайте публикацию сразу или по расписанию в <a href=\"#marketing\">Контент-студии</a>. Сохранение токена само по себе не отправляет посты и не запускает рекламу.</p>")+
        (c?I18n.html("<div class=\"actions\"><button type=\"button\" class=\"btn\" onclick=\"checkInstagram(this)\">Проверить подключение</button><button type=\"button\" class=\"btn light\" onclick=\"askDisconnectInstagram()\">Отключить от сайта</button></div>"):'')+
        '<details '+(!c||c.check_status==='invalid'?'open':'')+' style="margin-top:16px"><summary>'+(c?I18n.t("Заменить токен"):I18n.t("Подключить по маркеру доступа"))+I18n.html("</summary><form onsubmit=\"connectInstagram(event)\" style=\"margin-top:16px\"><div class=\"field\"><label for=\"instagramToken\">Маркер доступа Instagram</label><input id=\"instagramToken\" name=\"token\" type=\"password\" autocomplete=\"off\" spellcheck=\"false\" maxlength=\"4096\" placeholder=\"Вставьте маркер из Meta Developers\" required></div><button type=\"submit\" class=\"btn\">Проверить и сохранить</button></form><p class=\"hint\">Для тестирования: Meta Developers → Instagram API → Сгенерируйте маркеры доступа. При истечении или отзыве доступа замените маркер здесь. Автоматическое продление пока не настроено.</p></details></section>");
}
async function connectInstagram(event){
    event.preventDefault();const form=event.target,button=form.querySelector('button');button.disabled=true;
    const token=form.elements.token.value;form.elements.token.value='';
    try{const r=await api('instagram_connect',{token});state.instagram=r.connection;render();toast('Instagram @'+r.connection.username+' подключён к сайту');}
    catch(e){toast(e.message);}finally{button.disabled=false;}
}
async function checkInstagram(button){
    button.disabled=true;
    try{const r=await api('instagram_check');state.instagram=r.connection;render();toast('Instagram подтвердил подключение');}
    catch(e){try{await reload();render();}catch(_){}toast(e.message);}finally{button.disabled=false;}
}
function askDisconnectInstagram(){
    modal('Отключить Instagram от сайта',I18n.html("<p>Сохранённый токен будет удалён из этого рабочего пространства. Для повторного подключения потребуется маркер доступа.</p><p>Аккаунт и публикации Instagram сохранятся. Разрешения приложения можно отдельно отозвать в Instagram → Приложения и сайты.</p><div class=\"actions\"><button class=\"btn\" onclick=\"disconnectInstagram(this)\">Отключить</button><button class=\"btn light\" onclick=\"$('modal').close()\">Отмена</button></div>"));
}
async function disconnectInstagram(button){
    button.disabled=true;try{await api('instagram_disconnect');state.instagram=null;$('modal').close();render();toast('Instagram отключён от сайта');}catch(e){toast(e.message);button.disabled=false;}
}
