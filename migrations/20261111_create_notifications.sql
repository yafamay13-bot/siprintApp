USE siprint;

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
