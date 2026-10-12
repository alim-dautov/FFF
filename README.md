# FOODFORFIT CRM / ERP

Рабочая CRM сервиса доставки питания: клиенты, подписки, воронка, оплаты, доставки, склад, роли и Grok Agent в режиме чтения.

## Запуск

Нужны PHP 8.2+ и Node.js.

```bash
npm install
npm run dev
```

Откройте http://localhost:5173

Демо-пароль для всех сотрудников: `FitDemo!2026`

- admin@foodforfit.kz — супер-админ
- sales@foodforfit.kz — менеджер продаж, только свои клиенты
- warehouse@foodforfit.kz — склад, без финансов
- courier@foodforfit.kz — свои доставки
- finance@foodforfit.kz — бухгалтер

База локально: `server/data/fff.sqlite`. Пересоздать: `php server/bin/install.php --fresh`

Проверка сценариев: `npm run verify`

## Ключи

Скопируйте `.env.example` в `.env`.

- `GROK_API_KEY` — только сервер. Без ключа CRM работает, а агент пишет «AI Agent временно недоступен». Команды `/renewals` и другие всё равно читают базу.
- `KASPI_API_KEY` — пока пусто, счета создаются вручную. Фальшивых ответов Kaspi нет.
- `WHATSAPP_TOKEN` и `WHATSAPP_PHONE_ID` — пока пусто, исходящие остаются черновиками.
- `VAPID_PUBLIC` и `VAPID_PRIVATE` — для Web Push. Разрешение спрашивается в настройках.

Системный промпт агента: `server/ai/system_prompt.txt` и `server/ai/business_rules.txt`. Супер-админ может сохранить свою версию в настройках.

## Хостинг

На hoster.kz поставьте `DB_DRIVER=mysql` и доступы MySQL в `.env` или `server/api/config.php` (пример в `config.example.php`). Нужно расширение `pdo_mysql`.

```bash
npm run build
```

В корень сайта положите содержимое `dist`, папку `server` и `hosting.htaccess` как `.htaccess`. Правило должно вести `/api` в `server/api/index.php`.

Ежедневная проверка продлений: `php server/cron/daily.php`. Она же запускается при входе, если за сегодня ещё не шла.
