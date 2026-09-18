<?php
declare(strict_types=1);

/**
 * Dynamic sitemap from search index (groups, teachers, places).
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

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600, must-revalidate');

$today = gmdate('Y-m-d');
$items = Seo::indexableItems();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
  <url>
    <loc><?= Seo::h(Seo::SITE) ?>/</loc>
    <lastmod><?= Seo::h($today) ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
    <image:image>
      <image:loc><?= Seo::h(Seo::SITE) ?>/og-default.png</image:loc>
      <image:title>Расписание Колледжа Бизнеса и Права — Мини КБиП</image:title>
    </image:image>
  </url>
  <url>
    <loc><?= Seo::h(Seo::SITE) ?>/llms.txt</loc>
    <lastmod><?= Seo::h($today) ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.3</priority>
  </url>
<?php foreach ($items as $it): ?>
<?php
    $loc = Seo::raspUrl($it['type'], $it['id'], $it['name']);
    $prio = match ($it['type']) {
        'group' => '0.8',
        'teacher' => '0.7',
        default => '0.6',
    };
    $freq = $it['type'] === 'group' ? 'daily' : 'weekly';
?>
  <url>
    <loc><?= Seo::h($loc) ?></loc>
    <lastmod><?= Seo::h($today) ?></lastmod>
    <changefreq><?= $freq ?></changefreq>
    <priority><?= $prio ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
