# BSN.Expert

Обозреватель и редактор BSN данных, а также набор вспомогательных инструментов 
для работы с блокчейном Stellar для нужд, прежде всего, участников Ассоциации Монтелиберо.

## Концепция

Минималистичный no-js-first сервис. Часть данных берёт из bsn_crawler, часть подгружает в процессе.

## Запуск для разработки

1. Запустить хотя бы раз [bsn_crawler](https://github.com/Montelibero/bsn_crawler).
2. Создать `.env` на основе `.env.example`.
3. Запустить `docker compose up`.

### MongoDB

- В docker-compose добавлен сервис `mongo` (порт `27017`, volume `mongo_data`). Обновите `.env` по образцу и поднимите контейнеры `docker compose up`.
- Быстрый доступ из терминала: `docker compose exec mongo mongosh -u "$MONGO_ROOT_USERNAME" -p "$MONGO_ROOT_PASSWORD"`.
- Индексы (`usernames`, `contacts`) создаются при старте контейнера `app` через `app/cli/mongo-indexes.php` (запуск из `init.sh`).

### Синхронизация с Grist

Grist webhooks используют существующие POST-адреса:

- `/tokens/reload_known_tokens`;
- `/mtla/reload_members`;
- `/documents/update_from_grist`.

Webhook только планирует полное обновление соответствующей таблицы. Каждое следующее событие переносит запуск ещё на 60 секунд; `app-cron` обрабатывает готовые задачи раз в минуту. Дополнительно раз в час планируется полная сверка всех трёх источников.

Для каждого Grist-документа предусмотрен отдельный bearer secret:

- `GRIST_WEBHOOK_SECRET_KNOWN_TOKENS`;
- `GRIST_WEBHOOK_SECRET_MTLA_MEMBERS`;
- `GRIST_WEBHOOK_SECRET_DOCUMENTS`.

Секрет задаётся в Grist в поле `Header Authorization` как `Bearer <secret>`. Пока соответствующая переменная окружения пуста, endpoint принимает webhook без проверки — это режим миграции, а не рекомендуемая постоянная конфигурация.

Операторы, чьи Stellar-аккаунты перечислены в `ADMINS`, могут посмотреть состояние синхронизаций и запустить любую из них немедленно на странице `/admin/caches/`.

## Контрибуция

Стоит согласовать с @sozidatel (в телеграме одноимённый аккаунт) планируемый пул реквест.
Приветствуется оптимизация и улучшение архитектуры, добавление новых tools в ответ на 
боли участников МТЛА.

## License

This project is dedicated to the public domain under the [CC0 1.0 Universal license](https://creativecommons.org/publicdomain/zero/1.0/).

You are free to copy, modify, and use it for any purpose, without permission.

**На русском:**  
Проект BSN Expert передан в общественное достояние по лицензии CC0 1.0 Universal.

Вы можете свободно копировать, изменять и использовать материалы без ограничений и без разрешения автора.
