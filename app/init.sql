CREATE TABLE `usernames` (
    `username` varchar(32) NOT NULL,
    `account_id` varchar(56) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    `the_last` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `usernames`
    ADD PRIMARY KEY (`username`) USING BTREE,
    ADD KEY `account_id_idx` (`account_id`),
    ADD KEY `last_idx` (`account_id`,`the_last`);


CREATE TABLE `contacts` (
    `account_id` char(56) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    `stellar_address` char(56) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    `name` varchar(64) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `contacts`
    ADD PRIMARY KEY (`account_id`,`stellar_address`),
    ADD KEY `idx_account_id` (`account_id`);


CREATE TABLE `documents` (
    `hash` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL COMMENT 'SHA-256 hash of document in lowercase hex notation',
    `name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Document name, case-insensitive',
    `text` text COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Document text content',
    `url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Related URL',
    `creater` char(56) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL COMMENT 'Creator account ID',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Creation timestamp',
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Last update timestamp',
    `new_version_hash` char(64) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL COMMENT 'Hash of newer version that replaces this document'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `documents`
    ADD PRIMARY KEY (`hash`),
    ADD KEY `idx_creater` (`creater`),
    ADD KEY `idx_created_at` (`created_at`),
    ADD KEY `idx_name` (`name`),
    ADD KEY `idx_new_version` (`new_version_hash`),
    ADD CONSTRAINT `fk_documents_new_version` FOREIGN KEY (`new_version_hash`) REFERENCES `documents` (`hash`) ON DELETE SET NULL ON UPDATE CASCADE;

