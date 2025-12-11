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
- Индексы (`usernames`, `contacts`) создаются при старте контейнера `app` через `app/mongo-indexes.php` (запуск из `init.sh`).

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
