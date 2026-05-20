-- AI platform review reports (Super Admin). Idempotent.
CREATE TABLE IF NOT EXISTS ai_review_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    report_type VARCHAR(32) NOT NULL DEFAULT 'custom',
    filters_json LONGTEXT NULL,
    metrics_json LONGTEXT NULL,
    ai_output LONGTEXT NULL,
    html_output LONGTEXT NULL,
    docx_path VARCHAR(500) NULL DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_air_created (created_at),
    INDEX idx_air_user (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
