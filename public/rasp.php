<?php
declare(strict_types=1);

/**
 * SSR timetable page for crawlers / AI: /rasp/{group|teacher|place}/{id}[/{slug}]
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

use MiniKbp\BellSchedule;
use MiniKbp\ClientRateLimit;
use MiniKbp\RequestGuard;
use MiniKbp\Seo;
use MiniKbp\TimetableService;

try {
    ClientRateLimit::enforce('rasp', 120, 60);
} catch (Throwable) {
    // fail open for HTML
}

$type = RequestGuard::category((string) ($_GET['type'] ?? ''));
$id = RequestGuard::entityId((string) ($_GET['id'] ?? ''));
$slugIn = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? ''))) ?? '';

if ($type === null || $id === null || !in_array($type, Seo::INDEX_TYPES, true)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Не найдено — Мини КБиП</title></head><body><h1>Страница не найдена</h1><p><a href="/">На главную</a></p></body></html>';
    exit;
}

$name = '';
foreach (Seo::indexableItems() as $it) {
    if ($it['type'] === $type && $it['id'] === $id) {
        $name = $it['name'];
        break;
    }
}

$result = (new TimetableService())->fetch($type, $id, $name);
$data = ($result['success'] ?? false) ? ($result['data'] ?? null) : null;
if (is_array($data)) {
    $fromTt = RequestGuard::sanitizeLabel((string) ($data['groupName'] ?? ''));
    if ($fromTt !== '' && ($name === '' || preg_match('/^(group|teacher|place|subject)-/i', $name))) {
        $name = $fromTt;
    }
}
if ($name === '') {
    $name = $type . '-' . $id;
}

$canonicalPath = Seo::raspPath($type, $id, $name);
$wantSlug = Seo::slug($name);
if ($wantSlug !== '' && $slugIn !== '' && $slugIn !== $wantSlug) {
    header('Location: ' . $canonicalPath, true, 301);
    exit;
}
if ($wantSlug !== '' && $slugIn === '') {
    // Prefer slugged canonical for SEO without breaking bare /id links (no force-redirect).
}

$canonical = Seo::SITE . $canonicalPath;
$title = Seo::pageTitle($name, $type);
$description = Seo::pageDescription($name, $type);
$typeRu = Seo::typeRu($type);
$appUrl = Seo::appUrl($type, $id, $name);
$stale = !empty($result['stale']);
$pairs = is_array($data) && isset($data['pairs']) && is_array($data['pairs']) ? $data['pairs'] : [];
$byDay = Seo::pairsByDay($pairs);
$dateRange = '';
if (is_array($data) && isset($data['currentWeek']['dateRange'])) {
    $dateRange = RequestGuard::sanitizeLabel((string) $data['currentWeek']['dateRange']);
}

$pairCount = 0;
foreach ($byDay as $dayPairs) {
    foreach ($dayPairs as $p) {
        $subj = trim((string) ($p['subject'] ?? ''));
        if ($subj !== '' && $subj !== 'Урок снят') {
            $pairCount++;
        }
    }
}

$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebPage',
            '@id' => $canonical . '#webpage',
            'url' => $canonical,
            'name' => $title,
            'description' => $description,
            'inLanguage' => 'ru-RU',
            'isPartOf' => ['@id' => Seo::SITE . '/#website'],
            'about' => [
                '@type' => 'Thing',
                'name' => $name,
                'additionalType' => $typeRu,
            ],
            'primaryImageOfPage' => [
                '@type' => 'ImageObject',
                'url' => Seo::SITE . '/og-default.png',
            ],
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Мини КБиП',
                    'item' => Seo::SITE . '/',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => 'Расписание: ' . $name,
                    'item' => $canonical,
                ],
            ],
        ],
        [
            '@type' => 'FAQPage',
            'mainEntity' => [
                [
                    '@type' => 'Question',
                    'name' => 'Где посмотреть расписание «' . $name . '»?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Актуальное расписание занятий КБиП для «' . $name . '» (' . $typeRu . ') доступно на Мини КБиП: ' . $canonical . '. Также можно открыть интерактивное приложение: ' . $appUrl,
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Это официальный сайт Колледжа бизнеса и права?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Нет. Мини КБиП — неофициальный сервис расписания. Официальное расписание: https://kbp.by/rasp/timetable/view_beta_kbp/',
                    ],
                ],
            ],
        ],
    ],
];

if ($pairCount > 0) {
    $items = [];
    $pos = 1;
    foreach ($byDay as $dayIdx => $dayPairs) {
        foreach ($dayPairs as $p) {
            $subj = trim((string) ($p['subject'] ?? ''));
            if ($subj === '' || $subj === 'Урок снят') {
                continue;
            }
            $pn = (int) ($p['pairNumber'] ?? 0);
            $time = BellSchedule::pairTime($pn, (int) $dayIdx);
            $timeStr = ($time['start'] !== '' && $time['end'] !== '')
                ? $time['start'] . '–' . $time['end']
                : '';
            $dayName = Seo::DAYS[$dayIdx] ?? ('День ' . $dayIdx);
            $label = $dayName . ', пара ' . $pn . ($timeStr !== '' ? ' (' . $timeStr . ')' : '') . ': ' . $subj;
            $room = trim((string) ($p['room'] ?? ''));
            $teacher = trim((string) ($p['teacher'] ?? ''));
            if ($teacher !== '') {
                $label .= '; ' . $teacher;
            }
            if ($room !== '') {
                $label .= '; ауд. ' . $room;
            }
            $items[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => $label,
            ];
            if ($pos > 80) {
                break 2;
            }
        }
    }
    if ($items !== []) {
        $jsonLd['@graph'][] = [
            '@type' => 'ItemList',
            'name' => 'Пары на текущую неделю — ' . $name,
            'numberOfItems' => count($items),
            'itemListElement' => $items,
        ];
    }
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, max-age=1800, stale-while-revalidate=86400');
header('X-Robots-Tag: index, follow, max-image-preview:large, max-snippet:-1');

$h = static fn (string $s): string => Seo::h($s);

$refLink = static function (string $label, ?array $ref, string $fallbackType) use ($h): string {
    $label = RequestGuard::sanitizeLabel($label);
    if ($label === '') {
        return '';
    }
    $rid = is_array($ref) ? RequestGuard::entityId((string) ($ref['id'] ?? '')) : null;
    $rname = is_array($ref) ? RequestGuard::sanitizeLabel((string) ($ref['name'] ?? $label)) : $label;
    $rtype = $fallbackType;
    if ($rid !== null && in_array($rtype, Seo::INDEX_TYPES, true)) {
        return '<a href="' . $h(Seo::raspPath($rtype, $rid, $rname)) . '">' . $h($label) . '</a>';
    }
    return $h($label);
};

?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= $h($title) ?></title>
  <meta name="description" content="<?= $h($description) ?>" />
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />
  <link rel="canonical" href="<?= $h($canonical) ?>" />
  <link rel="alternate" type="text/plain" href="<?= $h(Seo::SITE) ?>/llms.txt" title="LLM context" />
  <meta property="og:type" content="website" />
  <meta property="og:locale" content="ru_RU" />
  <meta property="og:url" content="<?= $h($canonical) ?>" />
  <meta property="og:site_name" content="<?= $h(Seo::SITE_NAME) ?>" />
  <meta property="og:title" content="<?= $h($title) ?>" />
  <meta property="og:description" content="<?= $h($description) ?>" />
  <meta property="og:image" content="<?= $h(Seo::SITE) ?>/og-default.png" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="<?= $h($title) ?>" />
  <meta name="twitter:description" content="<?= $h($description) ?>" />
  <meta name="twitter:image" content="<?= $h(Seo::SITE) ?>/og-default.png" />
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/svg+xml" href="/assets/icons/minikbp.svg" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/css/seo.css?v=1" />
  <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?></script>
</head>
<body class="seo-page">
  <header class="seo-header">
    <a class="seo-brand" href="/">Мини КБиП</a>
    <nav class="seo-nav" aria-label="Навигация">
      <a href="/">Поиск</a>
      <a href="<?= $h($appUrl) ?>">В приложении</a>
    </nav>
  </header>

  <main class="seo-main">
    <article>
      <header class="seo-hero">
        <p class="seo-kicker">Расписание КБиП · <?= $h($typeRu) ?></p>
        <h1><?= $h($name) ?></h1>
        <p class="seo-lead">
          Пары Колледжа бизнеса и права<?= $dateRange !== '' ? ' на период ' . $h($dateRange) : '' ?>.
          Неофициальный сервис — данные с kbp.by.
        </p>
        <p class="seo-actions">
          <a class="seo-btn" href="<?= $h($appUrl) ?>">Открыть в приложении</a>
          <a class="seo-btn seo-btn--ghost" href="https://kbp.by/rasp/timetable/view_beta_kbp/?page=stable&amp;cat=<?= $h(rawurlencode($type)) ?>&amp;id=<?= $h(rawurlencode($id)) ?>">Официально на kbp.by</a>
        </p>
        <?php if ($stale): ?>
          <p class="seo-note">Показана сохранённая копия: kbp.by сейчас может быть недоступен.</p>
        <?php elseif ($pairCount === 0): ?>
          <p class="seo-note">Расписание на эту неделю пока не загружено. Откройте в приложении или зайдите позже.</p>
        <?php endif; ?>
      </header>

      <?php foreach (Seo::DAYS as $dayIdx => $dayName): ?>
        <?php
        $dayPairs = $byDay[$dayIdx] ?? [];
        $visible = [];
        foreach ($dayPairs as $p) {
            if (trim((string) ($p['subject'] ?? '')) !== '') {
                $visible[] = $p;
            }
        }
        ?>
        <section class="seo-day" id="day-<?= (int) $dayIdx ?>">
          <h2><?= $h($dayName) ?></h2>
          <?php if ($visible === []): ?>
            <p class="seo-empty">Нет пар</p>
          <?php else: ?>
            <ol class="seo-pairs">
              <?php foreach ($visible as $p): ?>
                <?php
                $pn = (int) ($p['pairNumber'] ?? 0);
                $subj = RequestGuard::sanitizeLabel((string) ($p['subject'] ?? ''));
                $cancelled = $subj === 'Урок снят';
                $time = BellSchedule::pairTime($pn, (int) $dayIdx);
                $timeLine = ($time['start'] !== '' && $time['end'] !== '')
                    ? str_replace('.', ':', $time['start']) . '–' . str_replace('.', ':', $time['end'])
                    : '';
                $refs = is_array($p['refs'] ?? null) ? $p['refs'] : [];
                $subjRef = is_array($refs['subject'] ?? null) ? $refs['subject'] : null;
                $placeRef = is_array($refs['place'] ?? null) ? $refs['place'] : null;
                $teacherRefs = is_array($refs['teachers'] ?? null) ? $refs['teachers'] : [];
                $status = (string) ($p['status'] ?? '');
                ?>
                <li class="seo-pair<?= $cancelled ? ' is-cancelled' : '' ?><?= $status === 'replaced' ? ' is-replaced' : '' ?>">
                  <div class="seo-pair-meta">
                    <span class="seo-pair-num">Пара <?= (int) $pn ?></span>
                    <?php if ($timeLine !== ''): ?><span class="seo-pair-time"><?= $h($timeLine) ?></span><?php endif; ?>
                  </div>
                  <div class="seo-pair-body">
                    <div class="seo-pair-subj"><?= $cancelled ? $h($subj) : $refLink($subj, $subjRef, 'subject') ?></div>
                    <?php if (!$cancelled && $teacherRefs !== []): ?>
                      <div class="seo-pair-teachers">
                        <?php
                        $bits = [];
                        foreach ($teacherRefs as $tr) {
                            if (!is_array($tr)) {
                                continue;
                            }
                            $tname = RequestGuard::sanitizeLabel((string) ($tr['name'] ?? ''));
                            if ($tname === '') {
                                continue;
                            }
                            $bits[] = $refLink($tname, $tr, 'teacher');
                        }
                        echo implode(', ', $bits);
                        ?>
                      </div>
                    <?php elseif (!$cancelled && trim((string) ($p['teacher'] ?? '')) !== ''): ?>
                      <div class="seo-pair-teachers"><?= $h(RequestGuard::sanitizeLabel((string) $p['teacher'])) ?></div>
                    <?php endif; ?>
                    <?php if (!$cancelled && trim((string) ($p['room'] ?? '')) !== ''): ?>
                      <div class="seo-pair-room">Ауд. <?= $refLink((string) $p['room'], $placeRef, 'place') ?></div>
                    <?php endif; ?>
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>

      <aside class="seo-aside">
        <h2>О сервисе</h2>
        <p>
          <strong>Мини КБиП</strong> — неофициальное расписание Колледжа бизнеса и права (Минск).
          Ищите группы, преподавателей и аудитории, смотрите замены и сохраняйте офлайн.
        </p>
        <ul>
          <li><a href="/">Главная — поиск расписания</a></li>
          <li><a href="/sitemap.xml">Карта сайта</a></li>
          <li><a href="/llms.txt">Контекст для ИИ (llms.txt)</a></li>
          <li><a href="https://kbp.by/rasp/timetable/view_beta_kbp/">Официальное расписание kbp.by</a></li>
        </ul>
      </aside>
    </article>
  </main>

  <footer class="seo-footer">
    <p>© Мини КБиП · неофициальный сервис · <a href="<?= $h($canonical) ?>">каноническая страница</a></p>
  </footer>
</body>
</html>
