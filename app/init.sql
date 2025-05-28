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
