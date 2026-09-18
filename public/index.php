<?php
declare(strict_types=1);

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
ob_start();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#ffffff" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <title>Расписание Колледжа Бизнеса и Права — Мини КБиП</title>
  <meta name="description" content="Расписание занятий Колледжа бизнеса и права (КБиП): поиск по группе, преподавателю или аудитории. Пары, замены, офлайн-кэш — Мини КБиП." />
  <meta name="keywords" content="Расписание КБиП, расписание КБП, Колледж бизнеса и права, расписание колледжа Минск, Мини КБиП, пары, замены, КБиП" />
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />
  <link rel="canonical" href="https://mini-kbp.site/" />
  <meta property="og:type" content="website" />
  <meta property="og:locale" content="ru_RU" />
  <meta property="og:url" content="https://mini-kbp.site/" />
  <meta property="og:site_name" content="Мини КБиП" />
  <meta property="og:title" content="Расписание Колледжа Бизнеса и Права — Мини КБиП" />
  <meta property="og:description" content="Расписание занятий Колледжа бизнеса и права: группы, преподаватели, аудитории, замены." />
  <meta property="og:image" content="https://mini-kbp.site/og-default.png" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="Расписание Колледжа Бизнеса и Права — Мини КБиП" />
  <meta name="twitter:description" content="Расписание занятий Колледжа бизнеса и права в браузере." />
  <meta name="twitter:image" content="https://mini-kbp.site/og-default.png" />
  <meta name="geo.region" content="BY-MI" />
  <meta name="geo.placename" content="Минск" />
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/icons/favicon-16.png" />
  <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png" />
  <link rel="icon" type="image/svg+xml" href="/assets/icons/minikbp.svg" />
  <link rel="alternate" type="text/plain" href="https://mini-kbp.site/llms.txt" title="LLM context" />
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "WebSite",
        "@id": "https://mini-kbp.site/#website",
        "url": "https://mini-kbp.site/",
        "name": "Расписание Колледжа Бизнеса и Права — Мини КБиП",
        "alternateName": "Мини КБиП",
        "description": "Расписание и поиск пар Колледжа бизнеса и права",
        "inLanguage": "ru-RU",
        "publisher": { "@id": "https://mini-kbp.site/#org" },
        "potentialAction": {
          "@type": "SearchAction",
          "target": "https://mini-kbp.site/?q={search_term_string}",
          "query-input": "required name=search_term_string"
        }
      },
      {
        "@type": "Organization",
        "@id": "https://mini-kbp.site/#org",
        "name": "Мини КБиП",
        "url": "https://mini-kbp.site/",
        "logo": "https://mini-kbp.site/assets/icons/minikbp.png",
        "description": "Неофициальный сервис расписания для студентов КБиП"
      },
      {
        "@type": "WebApplication",
        "name": "Мини КБиП",
        "url": "https://mini-kbp.site/",
        "applicationCategory": "EducationalApplication",
        "operatingSystem": "Web",
        "offers": { "@type": "Offer", "price": "0", "priceCurrency": "BYN" },
        "inLanguage": "ru-RU"
      },
      {
        "@type": "FAQPage",
        "mainEntity": [
          {
            "@type": "Question",
            "name": "Где посмотреть расписание КБиП?",
            "acceptedAnswer": {
              "@type": "Answer",
              "text": "На Мини КБиП (https://mini-kbp.site/) — поиск по группе, преподавателю или аудитории. Отдельные страницы: https://mini-kbp.site/rasp/group/{id}, /rasp/teacher/{id}, /rasp/place/{id}. Официально: https://kbp.by/rasp/timetable/view_beta_kbp/"
            }
          },
          {
            "@type": "Question",
            "name": "Мини КБиП — официальный сайт колледжа?",
            "acceptedAnswer": {
              "@type": "Answer",
              "text": "Нет. Это неофициальный сервис. Официальное расписание публикует kbp.by."
            }
          }
        ]
      }
    ]
  }
  </script>
  <script>
    (function () {
      try {
        var raw = localStorage.getItem("mkbp_web_settings_v1") || localStorage.getItem("app_settings_v1") || "{}";
        var s = JSON.parse(raw);
        var t = s && s.theme;
        if (t !== "light" && t !== "dark" && t !== "oled") t = "light";
        var root = document.documentElement;
        if (t === "dark" || t === "oled") root.classList.add("dark");
        if (t === "oled") root.classList.add("theme-oled");
        var accents = { blue: "#3390ec", green: "#31b545", purple: "#8b5cf6", orange: "#f59e0b", pink: "#ec4899", red: "#ef4444", cyan: "#06b6d4" };
        var hex = accents[s && s.accentColor] || accents.blue;
        root.style.setProperty("--app-accent", hex);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute("content", t === "light" ? "#ffffff" : t === "oled" ? "#000000" : "#141414");
      } catch (e) {}
    })();
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/css/app.css?v=21" />
</head>
<body>
  <div id="bootSplash" class="app-boot-splash" aria-hidden="true">
    <div class="app-boot-splash__track">
      <p id="bootSlogan" class="app-boot-splash__slogan">Мини КБиП</p>
    </div>
  </div>

  <div id="appShell" class="app-shell safe-top">
    <div class="app-shell-content">
      <!-- Settings tab -->
      <section id="tabSettings" class="app-tab" aria-hidden="true">
        <div class="app-scroll tab-scroll">
          <div id="settingsRoot" class="settings-root"></div>
        </div>
      </section>

      <!-- Timetable tab -->
      <section id="tabTimetable" class="app-tab is-active" aria-hidden="false">
        <div class="app-scroll tab-scroll" id="ttScroll">
          <div id="ttInner" class="tt-inner tt-inner--centered">
            <div class="tt-search-block" id="ttSearchBlock">
              <div class="tt-search-row">
                <div class="search-field" id="searchField">
                  <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                  </svg>
                  <input id="searchInput" class="search-input" type="search" placeholder="Найдите расписание" autocomplete="off" />
                  <div id="searchSpinner" class="search-spinner hidden" aria-hidden="true"></div>
                  <div id="searchDropdown" class="search-dropdown hidden"></div>
                </div>
                <button type="button" id="freeRoomsBtn" class="free-rooms-btn hidden" title="Свободные аудитории" aria-label="Свободные аудитории">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                  </svg>
                </button>
                <div id="pcTitle" class="pc-title hidden">
                  <div id="pcTitleName" class="pc-title__name"></div>
                  <div id="pcTitleType" class="pc-title__type"></div>
                </div>
                <div id="weekSwitcher" class="week-switcher hidden">
                  <button type="button" id="weekPrev" class="week-nav" aria-label="Предыдущая неделя">‹</button>
                  <div class="week-center">
                    <div id="weekPrimary" class="week-primary">Текущая неделя</div>
                    <div id="weekSecondary" class="week-secondary"></div>
                  </div>
                  <button type="button" id="weekNext" class="week-nav" aria-label="Следующая неделя">›</button>
                </div>
              </div>
              <div id="recentWrap" class="recent-wrap">
                <div class="recent-label">Недавние:</div>
                <div id="recentChips" class="recent-chips"></div>
              </div>
            </div>

            <p id="kbpNotice" class="kbp-notice hidden"></p>
            <div id="ttContent" class="tt-content hidden"></div>
            <div id="ttEmpty" class="tt-empty-hint hidden"></div>
          </div>
        </div>
      </section>
    </div>

    <nav class="app-nav safe-px" aria-label="Главное меню">
      <div class="app-nav__inner">
        <button type="button" class="app-nav__btn" data-tab="0" id="navSettings">
          <svg class="app-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          <span>Настройки</span>
        </button>
        <button type="button" class="app-nav__btn is-active" data-tab="1" id="navTimetable" aria-current="page">
          <svg class="app-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
          <span>Расписание</span>
        </button>
      </div>
    </nav>
  </div>

  <!-- Free rooms fullscreen -->
  <div id="freeRoomsPanel" class="free-rooms-panel hidden" role="dialog" aria-label="Свободные аудитории" aria-modal="true">
    <header class="free-rooms-header safe-top">
      <button type="button" id="freeRoomsBack" class="fr-back" aria-label="Назад">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6"/></svg>
      </button>
      <div>
        <div class="fr-title">Свободные аудитории</div>
        <div id="frSub" class="fr-sub">Сканируем…</div>
      </div>
    </header>
    <div class="fr-filters">
      <button type="button" class="fr-chip is-active" data-when="now">Сейчас</button>
      <button type="button" class="fr-chip" data-when="tomorrow">Завтра</button>
      <button type="button" class="fr-chip" data-when="date">Дата</button>
      <input type="date" id="frDate" class="fr-date hidden" />
      <select id="frLesson" class="fr-lesson">
        <option value="auto">Текущий/ближ. урок</option>
      </select>
    </div>
    <div id="frList" class="fr-list app-scroll"></div>
  </div>

  <script>
    window.__APP_VERSION__ = "0.3.25";
    window.__APP_VERSION_STAGE__ = "Release";
  </script>

  <!-- Yandex.Metrika counter -->
  <script type="text/javascript">
    (function(m,e,t,r,i,k,a){
        m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
        m[i].l=1*new Date();
        for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
        k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
    })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id=112716389', 'ym');

    ym(112716389, 'init', {ssr:true, webvisor:true, clickmap:true, ecommerce:"dataLayer", referrer: document.referrer, url: location.href, accurateTrackBounce:true, trackLinks:true});
  </script>
  <noscript><div><img src="https://mc.yandex.ru/watch/112716389" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
  <!-- /Yandex.Metrika counter -->

  <script src="/assets/js/app.js?v=28" defer></script>
</body>
</html>

<?php
echo (string) ob_get_clean();
