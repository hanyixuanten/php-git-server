CREATE TABLE pgit_repositories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    repository_name VARCHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    is_private TINYINT(1) NOT NULL DEFAULT 0,
    is_ready TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY pgit_repositories_name_unique (repository_name),
    KEY pgit_repositories_owner (owner_user_id),
    CONSTRAINT pgit_repositories_owner_foreign
        FOREIGN KEY (owner_user_id) REFERENCES pgit_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;