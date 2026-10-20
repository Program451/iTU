function tiktokConnectionTool(){
    if(opsState.role!=='owner')return '';
    const t=state.tiktok||{},c=t.connection,ok=c?.check_status==='verified';
    const status=c?(ok?'Аккаунт проверен':c.check_status==='invalid'?'Подключите заново':'Проверка недоступна'):(t.configured?'Ожидает входа в TikTok':'Нужны данные приложения');
    const checked=c?new Date(c.checked_at.replace(' ','T')+'Z').toLocaleString('ru-RU',{timeZone:'Asia/Almaty'}):'';
    return '<section class="card" id="tiktokConnection" style="margin-bottom:24px"><div class="sectiontitle"><h2>TikTok</h2><span class="badge '+(ok?'':'pending')+'">'+I18n.t(status)+'</span></div>'+
        (c?'<p><b>'+esc(c.display_name)+I18n.html("</b></p><p class=\"hint\">Последняя проверка: ")+esc(checked)+' · UTC+5</p>':'')+
        I18n.html("<p>Подключите аккаунт через официальный вход TikTok. Для публикации фото-постов отдельно разрешите отправку материалов. Доступ хранится зашифрованным на сервере.</p>")+
        I18n.html("<p class=\"hint\">Фото-посты можно отправлять сразу или по расписанию. Добавьте Content Posting API → Direct Post и video.publish, подтвердите домен в URL properties. Публичные посты доступны после проверки приложения TikTok.</p>")+
        (t.configured?'<p><span class="badge">'+esc(I18n.t(t.publishing_authorized?'Публикация разрешена':'Нужно разрешение на публикацию'))+'</span></p><div class="actions"><button class="btn" onclick="tiktokConnect(this,true)">'+esc(I18n.t('Разрешить публикацию в TikTok'))+'</button>'+'<button class="btn" onclick="tiktokConnect(this)">'+(c?I18n.t("Подключить заново"):I18n.t("Войти через TikTok"))+'</button>'+(c?I18n.html("<button class=\"btn light\" onclick=\"tiktokCheckAccount(this)\">Проверить подключение</button>"):'')+'<button class="btn light" onclick="tiktokAskDisconnect()">'+(c?I18n.t("Отключить"):I18n.t("Удалить настройки"))+'</button></div>':'')+
        '<details '+(!t.configured?'open':'')+I18n.html(" style=\"margin-top:18px\"><summary>Настройка приложения TikTok</summary><ol><li>На <a href=\"https://developers.tiktok.com/\" target=\"_blank\" rel=\"noopener noreferrer\">TikTok for Developers</a> откройте Manage apps → Create app.</li><li>Название: <b>AulieAta AI</b>, платформа: <b>Web</b>. Укажите адрес своего сайта.</li><li>Добавьте <b>Login Kit</b> и разрешение <code>user.info.basic</code>. Для тестирования используйте Sandbox и добавьте свой TikTok как Target user, если кабинет предлагает этот режим.</li><li>В Login Kit внесите Redirect URI точно как показано ниже.</li><li>Сохраните Client key и Client secret здесь, затем нажмите «Войти через TikTok».</li></ol>")+
        I18n.html("<p class=\"hint\">Документы сервиса для настройки TikTok: <a href=\"terms.php\" target=\"_blank\" rel=\"noopener\">Условия использования</a> · <a href=\"privacy.php\" target=\"_blank\" rel=\"noopener\">Политика конфиденциальности</a>.</p>")+
        '<div class="field"><label for="tiktokRedirect">Redirect URI</label><input id="tiktokRedirect" readonly value="'+esc(t.redirect_uri||'')+'" onclick="this.select()"></div>'+
        (c?I18n.html("<p class=\"hint\">Чтобы заменить Client key или Client secret, сначала отключите аккаунт.</p>"):'<form autocomplete="off" onsubmit="tiktokSaveSettings(event)"><div class="field"><label for="tiktokClientKey">Client key</label><input id="tiktokClientKey" name="client_key" maxlength="128" autocomplete="off" value="'+esc(t.client_key||'')+I18n.html("\" required></div><div class=\"field\"><label for=\"tiktokClientSecret\">Client secret</label><input id=\"tiktokClientSecret\" name=\"client_secret\" type=\"password\" maxlength=\"512\" autocomplete=\"new-password\" spellcheck=\"false\" required placeholder=\"Секрет приложения из TikTok for Developers\"></div><p class=\"hint\">Секрет после сохранения не отображается. Не вставляйте его в публичные сообщения или GitHub. Сохранение настроек ещё не подтверждает подключение.</p><button class=\"btn\" type=\"submit\">Сохранить данные приложения</button></form>"))+
        I18n.html("<p class=\"hint\">Тестовый вход зависит от доступа, предоставленного TikTok. Для подключения аккаунтов других бизнесов потребуется проверка приложения. <a href=\"https://developers.tiktok.com/docs/en/login-kit-web\" target=\"_blank\" rel=\"noopener noreferrer\">Инструкция TikTok</a></p></details></section>");
}
async function tiktokSaveSettings(event){
    event.preventDefault();const form=event.target,b=form.querySelector('button');b.disabled=true;
    const client_key=form.elements.client_key.value,client_secret=form.elements.client_secret.value;form.elements.client_secret.value='';
    try{const r=await api('tiktok_save_app',{client_key,client_secret});state.tiktok=r.tiktok;render();toast('Данные сохранены. Теперь войдите через TikTok.');}catch(e){toast(e.message);}finally{b.disabled=false;}
}
async function tiktokConnect(button,publishing=false){
    button.disabled=true;
    try{const r=await api('tiktok_begin',{publishing});const u=new URL(r.url);if(u.origin!=='https://www.tiktok.com'||u.pathname!=='/v2/auth/authorize/')throw Error('Не удалось открыть вход TikTok.');window.location.assign(u.href);}catch(e){toast(e.message);button.disabled=false;}
}
async function tiktokCheckAccount(button){
    button.disabled=true;
    try{const r=await api('tiktok_check');state.tiktok=r.tiktok;render();toast('Профиль TikTok подтверждён');}catch(e){try{await reload();render();}catch(_){}toast(e.message);}finally{button.disabled=false;}
}
function tiktokAskDisconnect(){
    const c=state.tiktok?.connection;
    modal(c?'Отключить TikTok':'Удалить настройки TikTok','<p>'+(c?I18n.t("Сайт удалит сохранённый доступ и запросит его отзыв в TikTok. Ваш аккаунт и материалы останутся в TikTok."):I18n.t("Client key и Client secret будут удалены из рабочего пространства."))+'</p>'+(c?I18n.html("<p><label><input type=\"checkbox\" id=\"tiktokRemoveApp\"> Также удалить Client key и Client secret</label></p>"):'')+'<div class="actions"><button class="btn" onclick="tiktokDisconnectAccount(this)">'+(c?I18n.t("Отключить"):I18n.t("Удалить настройки"))+I18n.html("</button><button class=\"btn light\" onclick=\"$('modal').close()\">Отмена</button></div>"));
}
async function tiktokDisconnectAccount(button){
    button.disabled=true;
    try{const r=await api('tiktok_disconnect',{remove_app:!state.tiktok?.connection||!!$('tiktokRemoveApp')?.checked});state.tiktok=r.tiktok;$('modal').close();render();
        if(r.revoked)toast('TikTok отключён от сайта');else modal('Доступ удалён с сайта',I18n.html("<p>TikTok не подтвердил отзыв разрешений. Откройте настройки TikTok → Безопасность и разрешения → Разрешения приложений и удалите AulieAta AI вручную.</p>"));
    }catch(e){toast(e.message);button.disabled=false;}
}
