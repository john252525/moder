# moder mobile (SPA)

Адаптивный UI модера для телефона. **Изолирован** — старый серверный UI на `/`
не тронут, API общий.

## Доступ

    https://moder.apitly.ru/m/

Логин — токен модератора (`MODER_TOKEN_MODER`). Хранится только в `sessionStorage`
этой вкладки, на сервер не пишется.

## Как устроено

- Чистая статика: `index.html`, `app.css`, `app.js`. Сборки нет.
- Слева узкий постоянный сайдбар с задачами (тап-зона ≥56px), скроллится отдельно.
- Справа деталь задачи со своим независимым скроллом; кнопки действий закреплены внизу.
- URL меняется на `/m/<uuid>` (History API, back/forward работают) — без перезагрузки.
- Фильтры — bottom-sheet по кнопке ☰.
- Рендер payload повторяет серверный: письмо (iframe), статья (markdown), diff сайта, реклама.

## Снести

    rm -rf /opt/moder/www/public/m

и удалить блок `location /m/ { ... }` из `nginx/default.conf`, затем
`docker compose restart nginx`. Больше ничего не затронуто.

## API

Тот же REST, авторизация заголовком `X-Moder-Token` (альтернатива `Authorization: Bearer`).
