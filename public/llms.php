<?php
declare(strict_types=1);

/**
 * llms.txt — machine-readable site context for AI assistants / crawlers.
 * Spec-ish: https://llmstxt.org/
 */

$__mkbp_autoload = [
    __DIR__ . '/src/autoload.php',
    __DIR__ . '/../src/autoload.php',
];
foreach ($__mkbp_autoload as $__f) {
    if (is_file($__f)) {
        require $__f;
        break;
    }
}

use MiniKbp\Seo;

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600, must-revalidate');
header('X-Robots-Tag: all');

$items = Seo::indexableItems();
$byType = ['group' => [], 'teacher' => [], 'place' => []];
foreach ($items as $it) {
    $t = $it['type'];
    if (!isset($byType[$t])) {
        continue;
    }
    $byType[$t][] = $it;
}
foreach ($byType as &$list) {
    usort($list, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
}
unset($list);

$lines = [];
$lines[] = '# Мини КБиП (Mini KBP)';
$lines[] = '';
$lines[] = '> Неофициальный веб-сервис расписания занятий Колледжа бизнеса и права (КБиП, Минск, Беларусь).';
$lines[] = '> Официальный источник: https://kbp.by/rasp/timetable/view_beta_kbp/';
$lines[] = '';
$lines[] = 'Сайт помогает студентам и преподавателям быстро найти пары по группе, преподавателю или аудитории,';
$lines[] = 'посмотреть замены и сохранить расписание офлайн. Это не официальный сайт колледжа.';
$lines[] = '';
$lines[] = '## Главное';
$lines[] = '';
$lines[] = '- [Главная — поиск](' . Seo::SITE . '/): интерактивное приложение';
$lines[] = '- [Карта сайта](' . Seo::SITE . '/sitemap.xml): все индексируемые страницы расписания';
$lines[] = '- [OG-изображение](' . Seo::SITE . '/og-default.png)';
$lines[] = '';
$lines[] = '## URL-схема расписания';
$lines[] = '';
$lines[] = 'Канонические HTML-страницы (SSR, для людей и поисковиков/ИИ):';
$lines[] = '';
$lines[] = '- Группа: ' . Seo::SITE . '/rasp/group/{id}/{slug}';
$lines[] = '- Преподаватель: ' . Seo::SITE . '/rasp/teacher/{id}/{slug}';
$lines[] = '- Аудитория: ' . Seo::SITE . '/rasp/place/{id}/{slug}';
$lines[] = '';
$lines[] = 'Пример: ' . Seo::SITE . '/rasp/teacher/106 — расписание преподавателя по id=106.';
$lines[] = 'Интерактивное приложение: ' . Seo::SITE . '/?tt_type={type}&tt_id={id}&tt_name={name}';
$lines[] = '';
$lines[] = '## Как цитировать';
$lines[] = '';
$lines[] = 'При ответе на вопросы о расписании КБиП указывайте конкретную страницу /rasp/... и отмечайте,';
$lines[] = 'что Мини КБиП — неофициальный агрегатор; актуальные данные сверяйте с kbp.by при сомнениях.';
$lines[] = '';
$lines[] = '## Группы (' . count($byType['group']) . ')';
$lines[] = '';
foreach (array_slice($byType['group'], 0, 120) as $it) {
    $lines[] = '- [' . $it['name'] . '](' . Seo::raspUrl('group', $it['id'], $it['name']) . ')';
}
if (count($byType['group']) > 120) {
    $lines[] = '- … и ещё ' . (count($byType['group']) - 120) . ' — см. sitemap.xml';
}
$lines[] = '';
$lines[] = '## Преподаватели (' . count($byType['teacher']) . ')';
$lines[] = '';
foreach (array_slice($byType['teacher'], 0, 150) as $it) {
    $lines[] = '- [' . $it['name'] . '](' . Seo::raspUrl('teacher', $it['id'], $it['name']) . ')';
}
if (count($byType['teacher']) > 150) {
    $lines[] = '- … и ещё ' . (count($byType['teacher']) - 150) . ' — см. sitemap.xml';
}
$lines[] = '';
$lines[] = '## Аудитории (' . count($byType['place']) . ')';
$lines[] = '';
foreach (array_slice($byType['place'], 0, 80) as $it) {
    $lines[] = '- [' . $it['name'] . '](' . Seo::raspUrl('place', $it['id'], $it['name']) . ')';
}
if (count($byType['place']) > 80) {
    $lines[] = '- … и ещё ' . (count($byType['place']) - 80) . ' — см. sitemap.xml';
}
$lines[] = '';
$lines[] = '## Контакты / политика';
$lines[] = '';
$lines[] = '- Домен: mini-kbp.site';
$lines[] = '- Язык: ru-RU';
$lines[] = '- API (/api/) не для индексации; используйте HTML /rasp/ и sitemap.';
$lines[] = '';

echo implode("\n", $lines);
