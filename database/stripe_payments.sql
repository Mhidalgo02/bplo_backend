-- Additive setup for Stripe test checkout. Safe to run again.
CREATE TABLE IF NOT EXISTS bplo_stripe_payments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 attempt_key CHAR(64) NOT NULL,
 session_id VARCHAR(255) DEFAULT NULL,
 checkout_url TEXT,
 amount_cents BIGINT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'php',
 state VARCHAR(30) NOT NULL DEFAULT 'creating',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY (attempt_key),
 UNIQUE KEY (session_id),
 KEY (application_id),
 FOREIGN KEY (application_id) REFERENCES bplo_applications(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO bplo_settings (setting_key, setting_json) VALUES ('stripe_payments_schema', '{"version":1}')
ON DUPLICATE KEY UPDATE setting_json = '{"version":1}';
