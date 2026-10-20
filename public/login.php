<?php
require_once __DIR__.'/database.php';
require_once __DIR__.'/announcements.php';
require_once __DIR__.'/business.php';
appSessionStart();
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
if (isset($_GET['logout'])) { session_unset(); session_destroy(); header('Location: login.php'); exit; }
if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));
$error = ''; $success = '';
// Keep non-secret input when validation fails; never render a submitted password.
$loginValues=[];
foreach (['phone'=>25,'biz_name'=>180,'biz_category'=>60,'location'=>100] as $key=>$limit) {
    $value=$_POST[$key]??'';
    $loginValues[$key]=is_string($value)?mb_substr(trim($value),0,$limit):'';
}
function loginValue($key){global $loginValues;return htmlspecialchars($loginValues[$key]??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}

try {
    $pdo = appDatabase(); announcementSchema($pdo);
    if (announcementAdmin($pdo)) { header('Location: admin.php'); exit; }
    unset($_SESSION['admin_id']);
} catch (Throwable $e) { http_response_code(503); exit('Вход временно недоступен. Повторите позже.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['login_csrf'], $csrf)) {
        $error = 'Обновите страницу и повторите вход.';
    } else {
        $raw = is_string($_POST['phone'] ?? null) ? trim($_POST['phone']) : '';
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 11 && $digits[0] === '8') $digits = '7'.substr($digits, 1);
        $phone = '+'.$digits; $alt = '8'.substr($digits, 1);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $action = $_POST['action'] ?? '';
        if (!preg_match('/^7\d{10}$/D', $digits)) {
            $error = 'Введите казахстанский номер: +7 или 8 и ещё 10 цифр.';
        } else try {
            if ($action === 'login') {
                if (($_SESSION['login_wait'] ?? 0) > time()) {
                    $error = 'Слишком много попыток. Подождите минуту.';
                } else {
                    // The shared form keeps the administrator's database-backed attempt limits.
                    require_once __DIR__.'/operations.php'; opsSchema($pdo);
                    opsRateLimit($pdo, 'login-ip:'.($_SERVER['REMOTE_ADDR'] ?? ''), 30, 900);
                    opsRateLimit($pdo, 'login-phone:'.$digits, 10, 900);
                    $q = $pdo->prepare('SELECT id,password_hash FROM app_admins WHERE phone=? AND active=1');
                    $q->execute([$phone]); $admin = $q->fetch();
                    // Privileges come from a verified admin account, never from the phone alone.
                    if ($admin && password_verify($password, $admin['password_hash'])) {
                        session_regenerate_id(true);
                        $_SESSION = ['admin_id'=>(int)$admin['id'], 'csrf'=>bin2hex(random_bytes(32))];
                        session_write_close(); header('Location: admin.php'); exit;
                    }
                    $q = $pdo->prepare('SELECT * FROM users WHERE phone IN (?,?,?,?) LIMIT 1');
                    $q->execute([$phone, $digits, $alt, $raw]); $u = $q->fetch();
                    if ($u && password_verify($password, $u['password'])) {
                        session_regenerate_id(true);
                        $_SESSION = ['csrf'=>bin2hex(random_bytes(32))];
                        foreach (['user_id'=>'id', 'user_phone'=>'phone', 'biz_name'=>'biz_name', 'biz_category'=>'biz_category', 'location'=>'location'] as $key=>$column) $_SESSION[$key] = $u[$column];
                        session_write_close(); header('Location: index.php'); exit;
                    }
                    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                    if ($_SESSION['login_attempts'] >= 8) { $_SESSION['login_wait'] = time()+60; $_SESSION['login_attempts'] = 0; }
                    $error = 'Неверный номер телефона или пароль.';
                }
            } elseif ($action === 'register') {
                $name = mb_substr(trim((string)($_POST['biz_name'] ?? '')), 0, 180);
                $category = mb_substr(trim((string)($_POST['biz_category'] ?? 'services')), 0, 60);
                $location = mb_substr(trim((string)($_POST['location'] ?? 'Тараз')), 0, 100);
                if (!isset(businessActivityTypes()[$category])) {
                    $error = 'Выберите сферу бизнеса';
                } elseif (!$name || mb_strlen($password) < 8 || mb_strlen($password) > 128) {
                    $error = 'Укажите название бизнеса и пароль от 8 до 128 символов.';
                } else {
                    $q = $pdo->prepare('SELECT id FROM users WHERE phone IN (?,?,?) LIMIT 1');
                    $q->execute([$phone, $digits, $alt]); $existingUser = $q->fetchColumn();
                    $q = $pdo->prepare('SELECT id FROM app_admins WHERE phone=? LIMIT 1');
                    $q->execute([$phone]); $existingAdmin = $q->fetchColumn();
                    if ($existingUser || $existingAdmin) {
                        $error = 'Этот номер уже зарегистрирован. Войдите в аккаунт.';
                    } else {
                        $pdo->beginTransaction();
                        $q = $pdo->prepare('INSERT INTO users(phone,password,biz_name,biz_category,location) VALUES(?,?,?,?,?)');
                        $q->execute([$phone, password_hash($password, PASSWORD_DEFAULT), $name, $category, $location]);
                        announcementRegister($pdo, (int)$pdo->lastInsertId()); $pdo->commit();
                        $success = 'Аккаунт создан. Войдите с вашим номером и паролем.';
                    }
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage() === 'Слишком много попыток. Повторите позже.' ? $e->getMessage() : ($action === 'register' ? 'Не удалось создать аккаунт. Проверьте данные и повторите.' : 'Не удалось выполнить вход. Повторите позже.');
        }
    }
}
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AulieAta AI — вход в рабочее пространство</title><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"><script src="i18n-catalog.js?v=20261020i"></script><script src="i18n.js"></script><style>
*{box-sizing:border-box}body{margin:0;font:14px Manrope,Arial,sans-serif;background:#f6f4ee;color:#182d24}.layout{min-height:100vh;display:grid;grid-template-columns:1.03fr 1fr}aside{background:#0f291e;color:white;padding:50px 64px;display:flex;flex-direction:column;position:relative;overflow:hidden}aside:after{content:'✳';font-size:640px;line-height:1;position:absolute;bottom:-300px;right:-160px;color:#c5f2770b;pointer-events:none}.brand{display:flex;align-items:center;gap:12px;font-size:24px;font-weight:800}.mark{width:42px;height:42px;display:grid;place-items:center;border-radius:13px;background:#c5f277;color:#0f291e;font-size:30px}.brand small{font-size:12px;color:#c5f277}.story{margin:auto 0;max-width:470px;padding:70px 0}.tag{font-size:10px;letter-spacing:2px;color:#c5f277;font-weight:800}.story h1{font-size:47px;line-height:1.16;letter-spacing:-2px;font-weight:600;margin:24px 0}.story h1 span{color:#c5f277}.story p{color:#b5c8bc;line-height:1.8;font-size:14px;max-width:400px}.features{display:flex;gap:10px;flex-wrap:wrap;margin-top:30px}.features span{padding:10px 12px;border:1px solid #ffffff28;border-radius:8px;font-size:10px;color:#d5e1d9}aside footer{font-size:11px;color:#8aa392;display:flex;justify-content:space-between;gap:15px}main{display:flex;align-items:center;justify-content:center;padding:50px 38px;position:relative}.formbox{width:100%;max-width:400px}.eyebrow{font-size:10px;font-weight:800;letter-spacing:1.5px;color:#7d887e;text-transform:uppercase}h2{font-size:30px;letter-spacing:-1px;margin:12px 0}main p{font-size:12px;color:#788277;line-height:1.7}.tabs{display:flex;padding:5px;background:#e9ece3;border-radius:10px;margin:28px 0 25px}.tabs button{flex:1;padding:10px;border:0;background:transparent;color:#788277;border-radius:7px;font:700 12px Manrope;cursor:pointer}.tabs button.active{background:white;color:#182d24;box-shadow:0 2px 6px #0001}.field{margin-bottom:16px}.field label{display:block;font-size:11px;font-weight:700;margin-bottom:8px}input,select{width:100%;padding:13px 14px;font:13px Manrope;border:1px solid #dce2d6;border-radius:9px;background:#fff;color:#182d24;outline-offset:4px}.submit{display:block;width:100%;padding:14px;background:#0f291e;color:white;border:0;border-radius:9px;font:700 12px Manrope;cursor:pointer;margin-top:22px}.submit:hover{background:#1e4331}.hint{font-size:10px!important;text-align:center;margin-top:17px}.hidden{display:none}.error,.success{padding:13px 15px;border-radius:9px;font-size:12px;line-height:1.6;margin:16px 0}.error{background:#fff0ec;color:#ad3e30;border:1px solid #ebc8c0}.success{background:#eaf4de;color:#43652e;border:1px solid #d3e3c2}.lang{position:absolute;top:28px;right:32px;font-size:11px;color:#718077}.lang button{background:transparent;border:0;cursor:pointer;font:inherit;color:inherit}.foot{border-top:1px solid #e0e4d9;margin-top:30px;padding-top:18px;display:flex;justify-content:space-between;font-size:10px;color:#8a9486}.password{position:relative}.password input{padding-right:65px}.password button{position:absolute;right:8px;top:9px;border:0;background:#eff3e9;border-radius:5px;padding:5px;font:10px Manrope;cursor:pointer}@media(max-width:1000px){aside{padding:36px}.story h1{font-size:37px}}@media(max-width:700px){.layout{grid-template-columns:1fr}aside{min-height:270px;padding:28px}.story{padding:30px 0}.story h1{font-size:32px;margin:14px 0}.story p,.features,aside footer{display:none}main{padding:55px 25px 35px}.lang{top:20px;right:25px}h2{font-size:27px}}
.legal-links{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:24px;font-size:12px;line-height:1.6}.legal-links a{color:#356248;text-underline-offset:3px}.legal-links a:focus-visible{outline:2px solid #356248;outline-offset:4px}</style><link rel="stylesheet" href="workspace-polish.css?v=20261020i"></head><body><div class="layout"><aside><div class="brand"><span class="mark">a</span>AulieAta <small>AI</small></div><div class="story"><div class="tag" data-i18n>БИЗНЕСУ ЖАМБЫЛА • БИЗНЕСІҢІЗГЕ</div><h1><span data-i18n>Ваш бизнес.</span><br><span data-i18n>Ваши возможности.</span><br><span data-i18n>Ваш AI-помощник.</span></h1><p data-i18n>Продажи, команда, финансы и идеи для роста — в одном рабочем пространстве. На русском и қазақша.</p><div class="features"><span data-i18n>✳ Умные инструменты</span><span data-i18n>↗ Реальные данные</span><span data-i18n>◇ Локальный контекст</span></div></div><footer><span data-i18n>Создано для малого бизнеса Казахстана</span><span data-i18n>Тараз · UTC+5</span></footer></aside><main><div class="lang"><button type="button" onclick="loginLang('ru')" data-i18n>РУС</button> / <button type="button" onclick="loginLang('kk')" data-i18n>ҚАЗ</button></div><div class="formbox"><div class="eyebrow" id="welcome" data-i18n>Добро пожаловать</div><h2 id="heading">Вход в пространство</h2><p id="intro" data-i18n>Продолжите работу с вашим бизнесом.</p><?php if($error): ?><div class="error" role="alert" data-i18n><?php echo htmlspecialchars($error,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?><?php if($success): ?><div class="success" role="status" data-i18n><?php echo htmlspecialchars($success,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?><div class="tabs"><button type="button" class="active" id="tabLogin" onclick="switchTab(false)" data-i18n>Вход</button><button type="button" id="tabRegister" onclick="switchTab(true)" data-i18n>Регистрация</button></div><form id="loginForm" method="post"><input type="hidden" name="csrf" value="<?php echo $_SESSION['login_csrf']; ?>"><input type="hidden" name="action" value="login"><div class="field"><label for="phone" data-i18n>Номер телефона</label><input type="tel" id="phone" name="phone" value="<?php echo loginValue('phone'); ?>" autocomplete="username" required placeholder="+7 (___) ___-__-__" maxlength="25"></div><div class="field"><label for="password" data-i18n>Пароль</label><div class="password"><input type="password" id="password" name="password" autocomplete="current-password" required placeholder="Ваш пароль" data-i18n-placeholder><button type="button" onclick="togglePassword('password')" aria-label="Показать пароль" data-i18n>Показать</button></div></div><button type="submit" class="submit" id="enter" data-i18n>Войти в кабинет ↗</button><p class="hint" data-i18n>Вход по вашему номеру телефона и паролю.</p></form><form id="registerForm" method="post" class="hidden"><input type="hidden" name="csrf" value="<?php echo $_SESSION['login_csrf']; ?>"><input type="hidden" name="action" value="register"><div class="field"><label for="biz" data-i18n>Название бизнеса или проекта</label><input id="biz" name="biz_name" value="<?php echo loginValue('biz_name'); ?>" required maxlength="180" placeholder="Например, кофейня «Шерхан»" data-i18n-placeholder></div><div class="field"><label for="regPhone" data-i18n>Номер телефона</label><input id="regPhone" type="tel" name="phone" value="<?php echo loginValue('phone'); ?>" autocomplete="username" required placeholder="+7 (___) ___-__-__" maxlength="25"></div><div class="field"><label for="regPassword" data-i18n>Пароль — минимум 8 символов</label><input id="regPassword" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="128" required placeholder="Придумайте пароль" data-i18n-placeholder></div><div class="field"><label for="category" data-i18n>Сфера деятельности</label><select id="category" name="biz_category"><option value="beginner" data-i18n>Новичок в бизнесе</option><option value="cafe">Кафе и рестораны</option><option value="toyhana">Тойхана и банкетный зал</option><option value="fashion">Одежда, обувь и бутики</option><option value="jewelry">Ювелирные изделия и подарки</option><option value="cosmetics">Косметика и парфюмерия</option><option value="beauty">Салоны красоты и барбершопы</option><option value="gadgets">Электроника и ремонт</option><option value="home">Мебель и интерьер</option><option value="kids">Детские товары и садики</option><option value="flowers">Цветы и праздники</option><option value="fitness">Фитнес и спорт</option><option value="auto">Автосервис и автомойки</option><option value="grocery">Продукты и супермаркеты</option><option value="medical">Аптеки и клиники</option><option value="edu">Образовательные центры</option><option value="it">IT, SMM и Digital</option><option value="print">Реклама и полиграфия</option><option value="sewing">Ателье и пошив</option><option value="repair">Ремонт и строительство</option><option value="realty">Недвижимость и аренда</option><option value="entertainment">Клубы и досуг</option><option value="pets">Зоомагазины и ветклиники</option><option value="garden">Сады и агробизнес</option><option value="logistics">Логистика и карго</option><option value="finance">Бухгалтерия и юристы</option><option value="industry">Производство</option><option value="tenders">Госзакупки и тендеры</option><option value="services">Бытовые услуги</option></select></div><div class="field"><label for="location" data-i18n>Город / район</label><select id="location" name="location"><option value="г. Тараз" data-i18n>г. Тараз</option><option value="Кордайский район" data-i18n>Кордайский район</option><option value="Меркенский район" data-i18n>Меркенский район</option><option value="Шуский район" data-i18n>Шуский район</option><option value="Байзакский район" data-i18n>Байзакский район</option><option value="Жамбылский район" data-i18n>Жамбылский район</option><option value="Таласский район" data-i18n>Таласский район</option><option value="Жуалынский район" data-i18n>Жуалынский район</option><option value="Сарысуский район" data-i18n>Сарысуский район</option><option value="Район Т. Рыскулова" data-i18n>Район Т. Рыскулова</option><option value="Мойынкумский район" data-i18n>Мойынкумский район</option></select></div><button type="submit" class="submit" data-i18n>Создать рабочее пространство ↗</button></form><nav aria-label="Документы сервиса" class="legal-links"><a href="terms.php" data-i18n>Условия использования</a><a href="privacy.php" data-i18n>Политика конфиденциальности</a></nav><div class="foot"><span>AulieAta AI</span><span data-i18n>Для вашего бизнеса</span></div></div></main></div><script>
let loginRegistration=false;
function switchTab(reg){loginRegistration=reg;document.getElementById('loginForm').classList.toggle('hidden',reg);document.getElementById('registerForm').classList.toggle('hidden',!reg);document.getElementById('tabLogin').classList.toggle('active',!reg);document.getElementById('tabRegister').classList.toggle('active',reg);loginHeading();}
function loginHeading(){document.getElementById('heading').textContent=I18n.t(loginRegistration?'Создайте пространство':'Вход в пространство');}
function togglePassword(id){const e=document.getElementById(id),b=e.parentElement.querySelector('button');e.type=e.type==='password'?'text':'password';b.textContent=I18n.t(e.type==='password'?'Показать':'Скрыть');b.setAttribute('aria-label',I18n.t(e.type==='password'?'Показать пароль':'Скрыть пароль'));}
function loginLang(l){I18n.set(l);loginHeading();const e=document.getElementById('password'),b=e.parentElement.querySelector('button');b.textContent=I18n.t(e.type==='password'?'Показать':'Скрыть');b.setAttribute('aria-label',I18n.t(e.type==='password'?'Показать пароль':'Скрыть пароль'));document.querySelectorAll('.lang button').forEach(b=>b.setAttribute('aria-pressed',String(b.textContent===(l==='kk'?'ҚАЗ':'РУС'))));}
document.addEventListener('DOMContentLoaded',()=>{const saved=<?php echo json_encode(['biz_category'=>$loginValues['biz_category'],'location'=>$loginValues['location']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE); ?>;for(const [name,value] of Object.entries(saved)){const select=document.querySelector('#registerForm [name="'+name+'"]');if(value&&[...select.options].some(o=>o.value===value))select.value=value;}loginLang(I18n.language);<?php if(($_POST['action']??'')==='register'&&$error): ?>switchTab(true);<?php endif; ?>});
</script></body></html>
