-- Phase 1 : fondation (comptes, profils, préférences, sessions, sécurité).
-- Importable dans phpMyAdmin, ou créé automatiquement par install.php.

CREATE TABLE IF NOT EXISTS users (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    email_verified_at DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profiles (
    user_id     BIGINT UNSIGNED PRIMARY KEY,
    first_name  VARCHAR(60) NOT NULL,
    last_name   VARCHAR(60) NULL,
    avatar_path VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_preferences (
    user_id      BIGINT UNSIGNED PRIMARY KEY,
    theme        ENUM('dark','light') NOT NULL DEFAULT 'dark',
    language     VARCHAR(10) NOT NULL DEFAULT 'fr',
    currency     VARCHAR(10) NOT NULL DEFAULT 'XOF',
    timezone     VARCHAR(64) NOT NULL DEFAULT 'Africa/Abidjan',
    date_format  VARCHAR(20) NOT NULL DEFAULT 'DD/MM/YYYY',
    hide_amounts TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_prefs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions connectées : permet de lister les appareils et de les fermer à distance.
-- Seule l'empreinte de l'identifiant de session est stockée.
CREATE TABLE IF NOT EXISTS user_sessions (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    session_hash CHAR(64) NOT NULL,
    user_agent  VARCHAR(255) NULL,
    ip_address  VARCHAR(45) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NOT NULL,
    UNIQUE KEY uq_session_hash (session_hash),
    KEY idx_sessions_user (user_id),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Codes de vérification de l'adresse e-mail (6 chiffres, hachés, à usage unique).
CREATE TABLE IF NOT EXISTS email_verifications (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    code_hash   CHAR(64) NOT NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_verif_user (user_id),
    CONSTRAINT fk_verif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jetons de récupération du mot de passe (empreinte SHA-256, expiration, usage unique).
CREATE TABLE IF NOT EXISTS password_resets (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reset_token (token_hash),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentatives de connexion échouées (limitation des essais répétés).
CREATE TABLE IF NOT EXISTS login_attempts (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attempts_email (email, created_at),
    KEY idx_attempts_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Boîte d'e-mails de développement (mode dev uniquement, aucun envoi réel).
CREATE TABLE IF NOT EXISTS dev_mails (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_email   VARCHAR(190) NOT NULL,
    subject    VARCHAR(255) NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 2a : agenda (tâches et événements) et notes.
CREATE TABLE IF NOT EXISTS tasks (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(160) NOT NULL,
    details     TEXT NULL,
    due_date    DATE NULL,
    due_time    TIME NULL,
    priority    ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
    status      ENUM('todo','done') NOT NULL DEFAULT 'todo',
    done_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tasks_user (user_id, status, due_date),
    CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(160) NOT NULL,
    location    VARCHAR(160) NULL,
    details     TEXT NULL,
    starts_at   DATETIME NOT NULL,
    ends_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_events_user (user_id, starts_at),
    CONSTRAINT fk_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notes (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(160) NOT NULL,
    body        TEXT NULL,
    pinned      TINYINT(1) NOT NULL DEFAULT 0,
    archived    TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_notes_user (user_id, archived, pinned),
    CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 2b : budget (comptes, catégories, transactions, enveloppes, épargne, dettes).
-- Tous les montants sont des entiers en francs CFA (XOF), sans décimales.
CREATE TABLE IF NOT EXISTS accounts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(80) NOT NULL,
    type            ENUM('cash','wave','mobile_money','bank','other') NOT NULL DEFAULT 'cash',
    initial_balance BIGINT NOT NULL DEFAULT 0,
    archived        TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_accounts_user (user_id, archived),
    CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(60) NOT NULL,
    kind        ENUM('expense','income') NOT NULL,
    KEY idx_categories_user (user_id, kind),
    CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS savings_goals (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    name          VARCHAR(80) NOT NULL,
    target_amount BIGINT UNSIGNED NOT NULL,
    due_date      DATE NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_goals_user (user_id),
    CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un transfert (entre comptes ou vers l'épargne) n'est ni un revenu ni une dépense :
-- il produit deux écritures liées (transfer_out puis transfer_in) ou une écriture + un objectif d'épargne.
CREATE TABLE IF NOT EXISTS transactions (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    account_id     BIGINT UNSIGNED NOT NULL,
    category_id    BIGINT UNSIGNED NULL,
    kind           ENUM('income','expense','transfer_in','transfer_out') NOT NULL,
    amount         BIGINT NOT NULL,
    occurred_on    DATE NOT NULL,
    label          VARCHAR(160) NULL,
    transfer_group VARCHAR(32) NULL,
    goal_id        BIGINT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tx_user_date (user_id, occurred_on),
    KEY idx_tx_account (account_id),
    KEY idx_tx_goal (goal_id),
    KEY idx_tx_group (transfer_group),
    CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_tx_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE,
    CONSTRAINT fk_tx_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_tx_goal FOREIGN KEY (goal_id) REFERENCES savings_goals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budgets (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    category_id    BIGINT UNSIGNED NOT NULL,
    monthly_amount BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_budget_cat (user_id, category_id),
    CONSTRAINT fk_budget_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_budget_cat FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS debts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    direction   ENUM('i_owe','owed_to_me') NOT NULL,
    person      VARCHAR(100) NOT NULL,
    amount      BIGINT UNSIGNED NOT NULL,
    due_date    DATE NULL,
    note        VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_debts_user (user_id),
    CONSTRAINT fk_debts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS debt_payments (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    debt_id     BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    amount      BIGINT UNSIGNED NOT NULL,
    paid_on     DATE NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dp_debt (debt_id),
    CONSTRAINT fk_dp_debt FOREIGN KEY (debt_id) REFERENCES debts (id) ON DELETE CASCADE,
    CONSTRAINT fk_dp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 3 : frigos, congélateurs, placards, avec stock et liste de courses.
CREATE TABLE IF NOT EXISTS spaces (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(80) NOT NULL,
    kind        ENUM('fridge','freezer','pantry','other') NOT NULL DEFAULT 'fridge',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_spaces_user (user_id),
    CONSTRAINT fk_spaces_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS space_items (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    space_id    BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(100) NOT NULL,
    quantity    INT UNSIGNED NOT NULL DEFAULT 1,
    unit        VARCHAR(12) NULL,
    expires_on  DATE NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_items_space (space_id, expires_on),
    CONSTRAINT fk_items_space FOREIGN KEY (space_id) REFERENCES spaces (id) ON DELETE CASCADE,
    CONSTRAINT fk_items_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shopping_items (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    space_id    BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(100) NOT NULL,
    quantity    INT UNSIGNED NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_shop_space (space_id),
    CONSTRAINT fk_shop_space FOREIGN KEY (space_id) REFERENCES spaces (id) ON DELETE CASCADE,
    CONSTRAINT fk_shop_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 3b : espaces partagés (membres + invitations à usage unique, valables 7 jours).
CREATE TABLE IF NOT EXISTS space_members (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id    BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    joined_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_member (space_id, user_id),
    KEY idx_member_user (user_id),
    CONSTRAINT fk_member_space FOREIGN KEY (space_id) REFERENCES spaces (id) ON DELETE CASCADE,
    CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS space_invites (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id    BIGINT UNSIGNED NOT NULL,
    created_by  BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    used_by     BIGINT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invite_hash (token_hash),
    KEY idx_invite_space (space_id),
    CONSTRAINT fk_invite_space FOREIGN KEY (space_id) REFERENCES spaces (id) ON DELETE CASCADE,
    CONSTRAINT fk_invite_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mise à jour : le type « Wave » est ajouté aux comptes déjà créés (sans effet si déjà fait).
ALTER TABLE accounts MODIFY type ENUM('cash','wave','mobile_money','bank','other') NOT NULL DEFAULT 'cash';

-- Phase 6 : Ma Journée (journal du jour et albums partagés, photos et messages)
CREATE TABLE IF NOT EXISTS days (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    kind        ENUM('journal','album') NOT NULL DEFAULT 'album',
    title       VARCHAR(100) NOT NULL,
    day_date    DATE NOT NULL,
    place       VARCHAR(100) NULL,
    expires_at  DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_days_user (user_id, day_date),
    KEY idx_days_expires (expires_at),
    CONSTRAINT fk_days_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS day_members (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    day_id      BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    joined_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_day_member (day_id, user_id),
    CONSTRAINT fk_daymem_day FOREIGN KEY (day_id) REFERENCES days (id) ON DELETE CASCADE,
    CONSTRAINT fk_daymem_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS day_invites (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    day_id      BIGINT UNSIGNED NOT NULL,
    created_by  BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    used_by     BIGINT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_day_invite_token (token_hash),
    KEY idx_day_invites_day (day_id),
    CONSTRAINT fk_dayinv_day FOREIGN KEY (day_id) REFERENCES days (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS day_posts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    day_id      BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    body        VARCHAR(1000) NULL,
    photo_path  VARCHAR(80) NULL,
    lat         DOUBLE NULL,
    lng         DOUBLE NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_posts_day (day_id, id),
    CONSTRAINT fk_daypost_day FOREIGN KEY (day_id) REFERENCES days (id) ON DELETE CASCADE,
    CONSTRAINT fk_daypost_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS day_reactions (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id     BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_day_reaction (post_id, user_id),
    CONSTRAINT fk_dayreact_post FOREIGN KEY (post_id) REFERENCES day_posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_dayreact_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 7 : Localisation (cercles de proches et partage de position volontaire, limité dans le temps)
CREATE TABLE IF NOT EXISTS circles (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(80) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_circles_user (user_id),
    CONSTRAINT fk_circ_owner FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS circle_members (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    circle_id   BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    joined_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_circle_member (circle_id, user_id),
    CONSTRAINT fk_cmem_circle FOREIGN KEY (circle_id) REFERENCES circles (id) ON DELETE CASCADE,
    CONSTRAINT fk_cmem_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS circle_invites (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    circle_id   BIGINT UNSIGNED NOT NULL,
    created_by  BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    used_by     BIGINT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_circle_invite_token (token_hash),
    KEY idx_circle_invites_circle (circle_id),
    CONSTRAINT fk_cinv_circle FOREIGN KEY (circle_id) REFERENCES circles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS location_shares (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    circle_id   BIGINT UNSIGNED NOT NULL,
    lat         DOUBLE NULL,
    lng         DOUBLE NULL,
    accuracy    INT UNSIGNED NULL,
    updated_at  DATETIME NULL,
    expires_at  DATETIME NOT NULL,
    UNIQUE KEY uq_location_share (user_id, circle_id),
    KEY idx_location_expires (expires_at),
    CONSTRAINT fk_lshare_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_lshare_circle FOREIGN KEY (circle_id) REFERENCES circles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
