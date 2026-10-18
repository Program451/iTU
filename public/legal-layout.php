<?php
// Public documents deliberately do not load configuration, a session or the database.
if (!isset($legalTitle, $legalLead, $legalSections, $legalPage)) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= $escape($legalLead) ?>">
    <title><?= $escape($legalTitle) ?> — AulieAta AI</title>
    <link rel="stylesheet" href="legal.css">
</head>
<body>
<a class="skip" href="#document">Перейти к документу</a>
<header class="site-header">
    <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">a</span>AulieAta <span>AI</span></a>
    <a class="back" href="login.php">Войти в пространство <span aria-hidden="true">↗</span></a>
</header>
<main id="document">
    <div class="eyebrow">ДОКУМЕНТЫ СЕРВИСА</div>
    <h1><?= $escape($legalTitle) ?></h1>
    <p class="lead"><?= $escape($legalLead) ?></p>
    <p class="version">Редакция от <time datetime="2026-10-18">18 октября 2026 года</time> · Тестовая версия сервиса</p>
    <div class="document-grid">
        <aside class="document-nav">
            <nav aria-label="Документы">
                <a href="terms.php"<?= $legalPage === 'terms' ? ' aria-current="page"' : '' ?>>Условия использования</a>
                <a href="privacy.php"<?= $legalPage === 'privacy' ? ' aria-current="page"' : '' ?>>Конфиденциальность</a>
            </nav>
            <div class="contact">
                <h2>Владелец сервиса</h2>
                <p>Акимов Алимжан</p>
                <a href="mailto:aalimzan610@gmail.com">aalimzan610@gmail.com</a>
                <p class="small">Вопросы о сервисе, данных и удалении аккаунта.</p>
            </div>
            <nav class="contents" aria-label="Содержание документа">
                <h2>В этом документе</h2>
                <?php foreach ($legalSections as $id => $section): ?>
                <a href="#<?= $escape($id) ?>"><?= $escape($section['title']) ?></a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <article class="document-body" aria-label="<?= $escape($legalTitle) ?>">
            <?php foreach ($legalSections as $id => $section): ?>
            <section id="<?= $escape($id) ?>">
                <h2><?= $escape($section['title']) ?></h2>
                <?= $section['html'] /* Trusted, authored HTML from terms.php/privacy.php only. */ ?>
            </section>
            <?php endforeach; ?>
        </article>
    </div>
</main>
<footer class="site-footer">
    <span>AulieAta AI · Помощник для малого бизнеса</span>
    <nav aria-label="Ссылки внизу страницы"><a href="terms.php">Условия использования</a><a href="privacy.php">Политика конфиденциальности</a></nav>
</footer>
</body>
</html>
