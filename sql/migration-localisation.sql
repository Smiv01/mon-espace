-- À exécuter une seule fois sur une base déjà installée (les nouvelles installations ont tout dans schema.sql).

-- Phase 7b : Localisation avancée (personnalisation, lieux, événements, masquage, liens temporaires, trajet)
CREATE TABLE IF NOT EXISTS location_prefs (
    user_id     BIGINT UNSIGNED PRIMARY KEY,
    pin_emoji   VARCHAR(16) NULL,
    pin_color   VARCHAR(7) NOT NULL DEFAULT '#6c5ce7',
    map_theme   VARCHAR(12) NOT NULL DEFAULT 'classic',
    trail_on    TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_lprefs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS circle_places (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    circle_id   BIGINT UNSIGNED NOT NULL,
    created_by  BIGINT UNSIGNED NOT NULL,
    kind        ENUM('place','meeting') NOT NULL DEFAULT 'place',
    name        VARCHAR(60) NOT NULL,
    emoji       VARCHAR(16) NULL,
    lat         DOUBLE NOT NULL,
    lng         DOUBLE NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cplaces_circle (circle_id),
    CONSTRAINT fk_cplace_circle FOREIGN KEY (circle_id) REFERENCES circles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS location_events (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    circle_id   BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    kind        ENUM('arrived','safe','sos') NOT NULL,
    place_id    BIGINT UNSIGNED NULL,
    place_name  VARCHAR(60) NULL,
    lat         DOUBLE NULL,
    lng         DOUBLE NULL,
    created_at  DATETIME NOT NULL,
    KEY idx_levents_circle (circle_id, id),
    KEY idx_levents_created (created_at),
    CONSTRAINT fk_levent_circle FOREIGN KEY (circle_id) REFERENCES circles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS location_hidden (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    from_user_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_location_hidden (user_id, from_user_id),
    CONSTRAINT fk_lhidden_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS location_links (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    lat         DOUBLE NULL,
    lng         DOUBLE NULL,
    accuracy    INT UNSIGNED NULL,
    updated_at  DATETIME NULL,
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_location_link_token (token_hash),
    KEY idx_location_link_user (user_id),
    CONSTRAINT fk_llink_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS location_trail (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    lat         DOUBLE NOT NULL,
    lng         DOUBLE NOT NULL,
    at          DATETIME NOT NULL,
    KEY idx_ltrail_user (user_id, at),
    CONSTRAINT fk_ltrail_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE day_posts ADD COLUMN lat DOUBLE NULL, ADD COLUMN lng DOUBLE NULL;
