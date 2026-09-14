# Миграция WWM Cabinet в отдельный репозиторий

Документ для переноса личного кабинета из `jackybrave7/bl-school` (папка `wwm-cabinet/`) в самостоятельный репозиторий `jackybrave7/wwm-cabinet`.

## Зачем отдельный репо

| Компонент | Репозиторий | Домен |
|-----------|-------------|-------|
| Маркетинг (Tilda → позже статика) | Tilda / будущий static | `worldwatercolormasters.art` |
| **Личный кабинет (этот проект)** | `wwm-cabinet` | `my.worldwatercolormasters.art` |
| Webhooks Tilda → AVO, CRM | `bl-school` | `bl-school.com/api/*` |
| Видео | Kinescope | embed в уроках |

Аудитория WWM — за рубежом. Кабинет на Sweb + Cloudflare Proxied; AVO (`my.bl-school.com`) остаётся для CRM, прогрева и учёта заказов.

## Архитектура

```
worldwatercolormasters.art          → лендинги курсов (Tilda)
my.worldwatercolormasters.art       → WWM Cabinet (PHP + SQLite)
bl-school.com/api/tilda-avo-*       → оплата → AVO (без изменений)
my.worldwatercolormasters.art/api/demo → демо из AVO autofunnel (прямой webhook)
kinescope.io                        → видео в уроках
```

Письма AVO: домен `@bl-school.com`, но ссылки на вход — **`https://my.worldwatercolormasters.art/login`**, не `my.bl-school.com`.

## Что переносить

Скопировать **содержимое** папки `wwm-cabinet/` в корень нового репо:

```
wwm-cabinet/          →  корень нового репо
├── .github/workflows/deploy.yml
├── MIGRATION.md
├── README.md
├── install-windows.ps1
├── app/
├── config/
├── data/
├── public/           ← document root на Sweb
├── scripts/
├── templates/
└── prototype/        ← HTML-макеты (не обязательно на прод)
```

**Не переносить** из `bl-school`: Astro/Sanity `src/`, `public/api/tilda-*.php`, Edsofa и прочие webhook'и — они остаются в `bl-school`.

## Шаги создания репозитория

### 1. GitHub

1. Создать пустой репозиторий: `jackybrave7/wwm-cabinet` (без README/license — добавим из кода).
2. Локально:

```bash
git clone https://github.com/jackybrave7/bl-school.git bl-school-tmp
cd bl-school-tmp
git checkout cursor/wwm-cabinet-06c4

# Новый репо
mkdir ../wwm-cabinet-repo && cd ../wwm-cabinet-repo
git init
cp -a ../bl-school-tmp/wwm-cabinet/. .
git add .
git commit -m "Initial import from bl-school (wwm-cabinet MVP + prototype)"
git branch -M master
git remote add origin https://github.com/jackybrave7/wwm-cabinet.git
git push -u origin master
```

### 2. GitHub Secrets (Actions → Secrets)

| Secret | Описание |
|--------|----------|
| `FTP_HOST` | Хост FTP Sweb |
| `FTP_PORT` | Порт (обычно `21`) |
| `FTP_USERNAME` | FTP-логин |
| `FTP_PASSWORD` | FTP-пароль |
| `FTP_SERVER_DIR` | Каталог на сервере, напр. `./my.worldwatercolormasters.art/` |
| `WWM_APP_SECRET` | Случайная строка ≥ 32 символов (сессии, reset token) |
| `WWM_SMTP_USER` | (фаза 2) SMTP для сброса пароля |
| `WWM_SMTP_PASS` | (фаза 2) |
| `WWM_SMTP_HOST` | (фаза 2) |
| `WWM_WEBHOOK_PAYMENT_TOKEN` | Секрет для `/api/payment` (письмо robot@ для EN/DE курсов) |
| `WWM_WEBHOOK_DEMO_TOKEN` | Секрет для AVO → `/api/demo` (тот же `token` в URL воронки) |
| `WWM_WEBHOOKS_ENABLED` | `true` — включить `/api/demo` и `/api/payment` |

Деплой **не включать** до настройки DNS и первого ручного `migrate.php` на сервере.

### 3. Sweb

1. Поддомен `my.worldwatercolormasters.art` → document root = `.../public_html` (стандарт Spaceweb).
2. GitHub Actions перед деплоем копирует `public/` → `public_html/`.
3. PHP 8.1+.
4. Права на запись: `data/` (SQLite, логи).
5. SSL Let's Encrypt.
6. Первый раз по SSH (опционально — bootstrap создаёт БД при первом запросе):

```bash
cd /path/to/site
cp config/config.example.php config/config.php
# отредактировать app_secret вручную ИЛИ дождаться deploy из Actions
php scripts/migrate.php
# НЕ запускать seed-demo.php на проде
```

### 4. Cloudflare (зона `worldwatercolormasters.art`)

| Запись | Значение | Proxy |
|--------|----------|-------|
| `@`, `www` | Tilda | по текущей схеме |
| `my` | IP Sweb | **Proxied** |

SSL/TLS: **Full (strict)**.

Cache Rules — bypass для динамики:

- `/login`, `/logout`, `/forgot`, `/reset`
- `/c/*`
- `/api/*`

Cache для `/assets/*`.

### 5. Kinescope

- Privacy → allowed embed domains: `my.worldwatercolormasters.art`
- Geo: **All**
- В `data/courses/*.json` заменить `REPLACE_WITH_REAL_ID` на реальные embed URL

### 6. AVO

- Воронки и письма: ссылка входа → `https://my.worldwatercolormasters.art/login`
- Оплата по-прежнему через webhook в `bl-school` (`tilda-avo-webhook.php`)
- **Демо:** блок «Отправить вебхук» в автоворонке Elke → прямой URL:
  ```
  https://my.worldwatercolormasters.art/api/demo?email={email}&name={name}&course=elke-en&token=WWM_WEBHOOK_DEMO_TOKEN
  ```
- **Оплата (EN/DE):** вебхук на товаре AVO (вкладка «Дополнительно»), не автоворонка:
  ```
  https://my.worldwatercolormasters.art/api/payment?token=WWM_WEBHOOK_PAYMENT_TOKEN
  ```
  Товары: 188 (elke-en), 191 (elke-de), 193 (alvaro), 199 (la-fe), 201 (angus), 321 (votsmush), 329 (nono). Отключить дублирующее письмо «после оплаты» в AVO, если кабинет шлёт `paid_email`.
  Альтернатива: вызов `avo-payment-cabinet.php` из `tilda-avo-webhook.php` на bl-school.
- Secrets: `WWM_WEBHOOKS_ENABLED=true`, `WWM_WEBHOOK_DEMO_TOKEN`, `WWM_WEBHOOK_PAYMENT_TOKEN`

## Курсы (slug → AVO id_goods)

| Slug | id_goods | Статус |
|------|----------|--------|
| `alvaro` | 193 | JSON есть, видео частично-заглушки |
| `angus` | 201 | оболочка + AVO payment webhook |
| `la-fe` | 199 | оболочка + AVO payment webhook |
| `votsmush` | 321 | оболочка + AVO payment webhook |
| `nono` | 329 | оболочка + AVO payment webhook |
| `elke-en` | 188 | контент + AVO payment/demo |
| `elke-de` | 191 | контент + AVO payment/demo |

## Локальная разработка (Windows)

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force
irm https://raw.githubusercontent.com/jackybrave7/wwm-cabinet/master/install-windows.ps1 | iex
```

Или клонировать репо в `C:\projects\wwm-cabinet` и запустить `install-windows.ps1` из корня.

**Прототип (HTML):** `prototype\start.bat`  
**PHP MVP:** `config\config.example.php` → `config.php`, `php scripts\migrate.php`, `php -S localhost:8080` в `public\`.

Демо-логин: `demo@wwm.test` / `demo-demo-demo` (только локально).

## Дорожная карта

| Фаза | Задача | Статус |
|------|--------|--------|
| 0 | Отдельный репо + локальный `C:\projects\wwm-cabinet` | этот документ |
| 1 | Деплой Sweb + CF + Kinescope whitelist + реальные embed | следующий |
| 2 | Секции в модели данных + UI студента | план |
| 3 | Админка PHP (по прототипу `prototype/admin/`) | план |
| 4 | Webhooks → grant access (+ синхронизация с AVO) | план |
| 5 | Экспорт уроков из AVO → JSON | план |
| 6 | 7 курсов + импорт legacy-покупателей | план |

## Связь с bl-school PR

- [PR #66](https://github.com/jackybrave7/bl-school/pull/66) — исходный MVP в подпапке `wwm-cabinet/`
- [PR #64](https://github.com/jackybrave7/bl-school/pull/64) — демо webhook Tilda → AVO (`enabled: false`)

После создания `wwm-cabinet` репо PR #66 можно закрыть или оставить как историю; дальнейшая разработка — только в новом репо.

## Чеклист перед продакшеном

- [ ] Репозиторий `wwm-cabinet` создан и запушен
- [ ] Secrets в GitHub настроены
- [ ] DNS `my` → Sweb, CF Proxied
- [ ] `migrate.php` выполнен на сервере
- [ ] `config.php` с уникальным `app_secret`
- [ ] Kinescope whitelist + реальные embed URL
- [ ] AVO-письма с правильной ссылкой на login
- [ ] `seed-demo.php` **не** запускался на проде
- [ ] Cache bypass в Cloudflare проверен
