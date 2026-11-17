# moder — стек: PHP 8.5 (FPM) + MySQL 9.4 + phpMyAdmin 5.2 + nginx (Caddy спереди)

## Что снаружи
- Caddy (системный, порты 80/443) проксирует `https://moder.example.com/` → nginx:39010
  и `https://moder.example.com/phpmyadmin/` → phpMyAdmin:39011
- Прямо на хосте: `127.0.0.1:39010` (app) и `127.0.0.1:39011` (phpMyAdmin) — только localhost.
- MySQL снаружи недоступен, только внутри docker-сети `moder_net`.

## Файлы
    /opt/moder/
      docker-compose.yml
      .env                    # пароли, порты (chmod 600)
      moder                   # CLI управления
      nginx/default.conf      # vhost nginx для PHP-FPM
      php/Dockerfile          # php:8.5-fpm + pdo_mysql/mysqli/gd/zip/intl/bcmath/exif
      php/php.ini             # настройки PHP
      www/public/             # DOCROOT приложения (index.php — healthcheck)
      logs/                   # логи сборки/nginx

## Управление
    cd /opt/moder
    ./moder start      # поднять всё
    ./moder stop       # остановить
    ./moder restart    # перезапустить
    ./moder status     # статус
    ./moder logs       # логи (follow)
    ./moder reboot     # down + up, данные сохраняются
    ./moder down -v    # УДАЛИТЬ ВСЁ вместе с БД (осторожно!)

    Эквиваленты: `docker compose up -d` / `stop` / `restart` / `ps` / `logs -f`.

## Доступы
Пароли в `.env`:
- root MySQL: `MYSQL_ROOT_PASSWORD`
- пользователь: `MYSQL_USER` / `MYSQL_PASSWORD`
- БД: `MYSQL_DATABASE` (moder)

phpMyAdmin: https://moder.example.com/phpmyadmin/ — логин root или moder.

## Автозапуск
Все контейнеры с `restart: unless-stopped` — поднимутся после `systemctl restart docker`.
Системный Caddy конфиг: `/etc/caddy/sites/moder.example.com.caddy`.

## Пересборка PHP
Если меняли `php/Dockerfile` или `php/php.ini`:
    ./moder rebuild


## Сервис премодерации для агентов

В этом же стеке живёт сервис модерации (`www/app/`).

- UI модера: `https://moder.example.com/` (логин по `MODER_TOKEN_MODER`)
- API для агентов: `https://moder.example.com/api/queue` (Bearer `MODER_TOKEN_AGENT`)
- Воркер devops: `www/bin/devops-worker.sh` (Bearer `MODER_TOKEN_DEVOPS`)
- Документация: `www/app/API.md`
- Healthcheck: `https://moder.example.com/health`

Схема: одна таблица `moderation_queue` с полиморфным `payload JSONB`,
идемпотентностью и жизненным циклом:

    pending → approved → applied
       ↓          ↓
    rejected   failed

Таблицы создаются автоматически при первом запросе (`app/schema.sql`).

Запуск примера воркера:
    cd /opt/moder
    MODER_BASE=http://127.0.0.1:39010 ./www/bin/devops-worker.sh
