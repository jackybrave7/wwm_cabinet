# WWM Cabinet

Личный кабинет World Watercolor Masters — `my.worldwatercolormasters.art`.

PHP + SQLite на Sweb, Cloudflare Proxied. Курсы в JSON, видео через Kinescope/Vimeo/YouTube.

**Статус:** MVP (фаза 1) — вход, дашборд, один курс-заглушка (`alvaro`).  
**Не в продакшене** до DNS, Cloudflare, Kinescope и настройки Secrets.

> Перенос из `bl-school`: см. [MIGRATION.md](MIGRATION.md).

## Архитектура

```
worldwatercolormasters.art          → маркетинг (Tilda)
my.worldwatercolormasters.art       → этот репозиторий
bl-school.com/api/tilda-avo-*       → webhooks оплаты → AVO
```

## Стек

- PHP 8.1+
- SQLite (`data/wwm.sqlite`)
- Document root: `public/`
- Курсы: `data/courses/*.json`
- HTML-прототип админки: `prototype/`

## Быстрый старт (Windows)

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force
irm https://raw.githubusercontent.com/jackybrave7/wwm-cabinet/master/install-windows.ps1 | iex
```

Проект установится в `C:\projects\wwm-cabinet`.

### Прототип (HTML, без PHP)

```powershell
cd C:\projects\wwm-cabinet\prototype
.\start.bat
```

### PHP-приложение

```bash
cp config/config.example.php config/config.php
# app_secret и base_url

php scripts/migrate.php
php scripts/seed-demo.php        # demo: только урок 1
# php scripts/seed-demo.php --paid

cd public && php -S localhost:8080
```

http://localhost:8080/login — **demo@wwm.test** / **demo-demo-demo**

## Деплой (GitHub Actions → Sweb FTP)

1. Создать репозиторий и настроить Secrets (список в [MIGRATION.md](MIGRATION.md)).
2. Document root на Sweb → `public/`.
3. Первый раз на сервере: `php scripts/migrate.php` (SQLite не перезаписывается при деплое).
4. Push в `master` или ручной запуск workflow **Deploy WWM Cabinet to Sweb**.

`config/config.php` генерируется из Secrets при каждом деплое (`scripts/write-deploy-config.mjs`).

### Cloudflare

- `my` → A-запись IP Sweb, **Proxied**
- SSL: Full (strict)
- Cache bypass: `/login`, `/logout`, `/forgot`, `/reset`, `/c/*`, `/api/*`
- Cache: `/assets/*`

### Kinescope

- Embed domain: `my.worldwatercolormasters.art`
- Geo: All
- Заменить `REPLACE_WITH_REAL_ID` в `data/courses/*.json`

### Demo webhook (AVO autofunnel → cabinet)

AVO вызывает кабинет **напрямую** (блок «Отправить вебхук» в автоворонке):

```
AVO autofunnel → my.worldwatercolormasters.art/api/demo
```

**URL для AVO** (Elke ENG):

```
https://my.worldwatercolormasters.art/api/demo?email={email}&name={name}&course=elke-en&token=ВАШ_СЕКРЕТ
```

Elke DE: `course=elke-de`. Альтернатива: `id_goods=188` вместо `course`.

**Secrets / config.php:**

- `WWM_WEBHOOKS_ENABLED=true`
- `WWM_WEBHOOK_DEMO_TOKEN` — длинная случайная строка (тот же `token` в URL AVO)
- `avo_goods_to_course` — маппинг `id_goods` → slug (уже в deploy-config)

Endpoint принимает **GET** (AVO) и **POST** (JSON + заголовок `X-WWM-Demo-Token`).

**Проверка после деплоя:**

```bash
curl "https://my.worldwatercolormasters.art/api/demo?email=test@example.com&name=Test&course=elke-en&token=ВАШ_СЕКРЕТ"
```

Ожидание: `{"ok":true,...}` и письмо со ссылкой на `/reset`.

**Локальный тест** (без HTTP):

```powershell
# config.php: webhooks.enabled=true, demo_token=local-test-token
.\.tools\php\php.exe scripts\test-demo-webhook.php test@example.com elke-en
```

### AVO

Ссылки в письмах: `https://my.worldwatercolormasters.art/login`

## Структура

| Путь | Назначение |
|------|------------|
| `public/index.php` | Front controller |
| `app/Router.php` | Маршруты |
| `app/Controllers/*` | Auth, dashboard, course, lesson |
| `data/courses/*.json` | Контент курсов |
| `prototype/` | HTML-макеты студента и админки |
| `scripts/migrate.php` | Схема БД |
| `app/Services/DemoAccess.php` | Выдача demo-доступа (user + access) |
| `scripts/bl-school/avo-demo-cabinet.php` | Опциональный прокси (не нужен при прямом URL в AVO) |
| `scripts/test-demo-webhook.php` | Локальный тест demo без HTTP |

## Админка (локально)

1. Запустите сид (даёт `demo@wwm.test` роль admin):

```powershell
.\.tools\php\php.exe scripts\migrate.php
.\.tools\php\php.exe scripts\seed-demo.php --paid
```

2. Войдите как `demo@wwm.test` / `demo-demo-demo`
3. Откройте http://localhost:8080/admin/courses

| URL | Назначение |
|-----|------------|
| `/admin/courses` | Список курсов |
| `/admin/courses/{slug}` | Настройки курса, демо, список уроков |
| `/admin/courses/{slug}/lessons/{num}` | Редактор урока (видео, материалы) |
| `/admin/students` | Ученики и прогресс |
| `/admin/students/{id}` | Профиль ученика |

Контент курсов хранится в `data/courses/*.json`. Прогресс уроков пишется в таблицу `lesson_opens` при просмотре урока.

## Курсы (slug → AVO id_goods)

| Slug | id_goods |
|------|----------|
| alvaro | 193 |
| angus | 201 |
| la-fe | 199 |
| votsmush | 321 |
| nono | 329 |
| elke-en | 188 |
| elke-de | 191 |

## Дорожная карта

- [x] Фаза 0: отдельный репо, прототип, MVP auth + 1 курс
- [ ] Фаза 1: прод-деплой, реальные Kinescope embed
- [ ] Фаза 2: секции в данных и UI
- [ ] Фаза 3: админка PHP
- [ ] Фаза 4: webhooks оплаты/демо → access
- [ ] Фаза 5: экспорт из AVO
- [ ] Фаза 6: все 7 курсов + legacy buyers

## Безопасность

- `config/config.php` не коммитить (генерируется при деплое)
- `data/` закрыт от HTTP (`.htaccess`)
- На проде не запускать `seed-demo.php`
