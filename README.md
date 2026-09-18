# Мини КБиП — веб-версия (PHP)

Веб-клиент расписания [Колледжа бизнеса и права](https://kbp.by): группы, преподаватели, аудитории и предметы в одном экране.

Сайт смотрит сетку и замены с `kbp.by`, подтягивает полные названия предметов и ФИО с `rasp.kbp.by`, кэширует ответы и отдаёт их через своё API. В браузере — поиск, расписание по дням, свободные аудитории, подписка на календарь (ICS) и уведомления о заменах.

Живой сайт: [mini-kbp.site](https://mini-kbp.site)

---

## Стек

<p>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://httpd.apache.org/"><img src="https://img.shields.io/badge/Apache-mod__rewrite-D22128?style=for-the-badge&logo=apache&logoColor=white" alt="Apache"></a>
  <a href="https://www.docker.com/"><img src="https://img.shields.io/badge/Docker-Compose-2496ED?style=for-the-badge&logo=docker&logoColor=white" alt="Docker"></a>
  <a href="https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API"><img src="https://img.shields.io/badge/PWA-Service%20Worker-FF2D20?style=for-the-badge&logo=pwa&logoColor=white" alt="PWA"></a>
</p>

Чистый PHP без фреймворка: UI в `public/`, логика в `src/`, JSON API в `public/api/`.

---

## Docker

Локальный запуск одной командой. DocumentRoot — папка `public/`.

```bash
cp .env.example .env
cp -n config.example.php config.php
docker compose up -d --build
```

- Приложение: http://localhost/
- Health: http://localhost/api/health.php

Конфиг: `config.example.php` → `config.php` (часовой пояс, кэш, URL `kbp.by` / `rasp.kbp.by`). Переменные из `.env` перекрывают файл, если заданы.

---

## API

| Метод | Описание |
|-------|----------|
| `GET /api/search.php?q=…` | Поиск групп, преподавателей, аудиторий, предметов |
| `GET /api/timetable.php?cat=group\|teacher\|auditory&id=…&name=…` | Расписание сущности |
| `GET /api/free-rooms.php?when=now&lesson=auto` | Свободные аудитории |
| `GET /api/calendar.ics.php?cat=…&id=…&name=…` | ICS-фид для Google / Apple Calendar |
| `GET /api/health.php` | Проверка живости |
| `GET /api/notify-broadcast.php` | Текущий broadcast-пинг (если есть) |
