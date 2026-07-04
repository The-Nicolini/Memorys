-- fresh_install.sql
-- Schone installatie voor een NIEUWE deployment van dit memoriaal-systeem.
-- Maakt lege tabellen aan met alleen een standaard admin-account, geen persoonlijke data.
--
-- Standaard inloggegevens na installatie:
--   Gebruikersnaam: admin
--   Wachtwoord:     admin1234
-- BELANGRIJK: wijzig dit wachtwoord direct na de eerste login via het admin-paneel!

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- Tabel `users`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_admin` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`username`, `password`, `is_admin`) VALUES
('admin', '$2y$10$vc5LjPkTz0jAOUnMbixXsOw0rlWa5sqeQA8R/.Uh5n6W/6haVcNla', 1);

-- --------------------------------------------------------
-- Tabel `settings`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `settings`;

CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `value`) VALUES
('slideshow_speed', '5000'),
('message_duration', '10000'),
('display_guest_password', 'wachtwoord123'),
('family_password', 'familie123'),
('family_can_add_slideshow_photos', '1'),
('moderator_password', 'beheer123'),
('memorial_name', 'John Doe'),
('memorial_birth_date', ''),
('memorial_date', ''),
('site_language', 'nl'),
('site_font', '\'Dancing Script\', cursive'),
('global_font', '\'Dancing Script\', cursive'),
('global_color', '#ffffff'),
('global_bg_type', 'semi-transparent'),
('welcome_image', 'fonback2.jpeg'),
('welcome_subtitle', 'Vul uw eigen naam in en het gedeelde wachtwoord om herinneringen te delen.'),
('welcome_button_color', '#d4a373'),
('welcome_bg_size', 'cover'),
('welcome_bg_position', 'center'),
('welcome_overlay_color', '#000000'),
('welcome_overlay_opacity', '0'),
('welcome_card_opacity', '95'),
('site_color_scheme', 'terracotta'),
('site_primary_color', '#d4a373'),
('site_primary_hover_color', '#bc8a5f'),
('site_primary_dark_color', '#8b4513'),
('funeral_mode', '0'),
('admin_name', ''),
('admin_email', ''),
('admin_phone', '');

-- --------------------------------------------------------
-- Tabel `login_attempts`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `login_attempts`;

CREATE TABLE `login_attempts` (
  `ip_address` varchar(45) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabel `media`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `media`;

CREATE TABLE `media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) DEFAULT NULL,
  `message_text` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_type` enum('photo','music','message') NOT NULL,
  `uploader_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  `is_anonymous` tinyint(1) DEFAULT 0,
  `font_family` varchar(100) DEFAULT NULL,
  `text_color` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
