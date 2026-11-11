CREATE DATABASE IF NOT EXISTS siprint
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE siprint;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'pegawai') NOT NULL DEFAULT 'pegawai',
  profile_photo VARCHAR(80) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  auth_version INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_active (role, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  copies SMALLINT UNSIGNED NOT NULL,
  notes VARCHAR(1000) NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filename VARCHAR(80) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  status ENUM('menunggu', 'diproses', 'selesai', 'ditolak') NOT NULL DEFAULT 'menunggu',
  rejection_reason VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_documents_user FOREIGN KEY (user_id) REFERENCES users (id),
  KEY idx_documents_status_created (status, created_at),
  KEY idx_documents_user_created (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  KEY idx_audit_created (created_at),
  KEY idx_audit_user (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  message VARCHAR(500) NOT NULL,
  link VARCHAR(255) NOT NULL DEFAULT '/',
  is_read BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  KEY idx_notifications_user_read_created (user_id, is_read, created_at),
  KEY idx_notifications_user_created (user_id, created_at)
) ENGINE=InnoDB;
