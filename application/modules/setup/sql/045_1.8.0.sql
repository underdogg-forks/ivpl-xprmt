-- =====================================================
-- 1.8.0 — the one migration file for this version (one file per version;
-- while 1.8.0 is unreleased, new statements are appended here)
--
-- Consolidated migration. Supersedes the incremental 1.8.0 – 1.8.12 files:
-- the integration-module schema plus the event_hash / idx_event_dedup repair
-- cascade (1.8.7 – 1.8.12) that only ever re-added the same column and index.
--
-- Net effect:
--   * ip_merchant_clients            — new: integration account registry
--   * ip_merchant_responses          — extended: unified provider response log
--                                      (payment gateways + e-invoice + Peppol),
--                                      incoming-document archive metadata,
--                                      event_hash dedup key; invoice_id nullable
--   * ip_einvoice_responses          — dropped (folded into ip_merchant_responses)
--   * ip_integration_sync_runs       — new: sync operations ledger
--   * ip_services / ip_client_services — new: service catalogue + assignments
--   * ip_clients.client_peppol_id    — new: Peppol electronic address
--   * ip_users.user_einvoice_identifier — new: sender e-invoicing identifier
--   * ip_invoices.service_id / ip_quotes.service_id — new: one service per doc
--   * ip_invoice_reminders + client/invoice reminder opt-out columns — new
--   * ip_users.user_auth_version     — new: invalidates sessions on password change
--   * ip_suppliers / ip_supplier_invoice* — new: supplier invoices module
--   * every remaining MyISAM table   — converted to InnoDB by upgrade_045_1_8_0()
--
-- InnoDB conversion: there are no statements for it here. The upgrade_045_1_8_0()
-- hook in Mdl_Setup converts every remaining MyISAM table after this file has
-- run. The set of tables can only be known at runtime: two tables (ip_sessions,
-- ip_login_log) were created with no ENGINE clause and so inherit whatever the
-- server default was at install time, and an install that has already been
-- converted by hand must not be rebuilt needlessly. The hook reads
-- information_schema and touches only tables that actually exist and are
-- actually MyISAM, so it is safe to re-run.
--
-- Idempotent (IF [NOT] EXISTS guards) so it is safe to re-run on a database
-- that already applied any of the superseded files.
-- =====================================================


-- ---------------------------------------------------------
-- ip_merchant_clients — one row per configured integration
-- account (e-invoicing, Peppol access point, payment gateway).
-- Credentials live in ip_settings under "integration_{merchant_type}_*";
-- settings_json is the legacy AES-256-GCM envelope.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ip_merchant_clients` (
  `id`            INT           AUTO_INCREMENT PRIMARY KEY,
  `merchant_type` VARCHAR(100)  NOT NULL,
  `label`         VARCHAR(255)  NULL,
  `enabled`       TINYINT(1)    DEFAULT 0,
  `auth_type`     VARCHAR(50)   DEFAULT 'oauth2',
  `settings_json` LONGTEXT      NULL COMMENT 'AES-256-GCM encrypted provider settings (ipenc:v1 envelope)',
  `created_at`    DATETIME      NULL,
  `updated_at`    DATETIME      NULL,

  UNIQUE KEY `uniq_merchant_type_label` (`merchant_type`, `label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------
-- ip_merchant_responses — extend the existing payment-gateway
-- response log to cover e-invoice (SuperPDP, Qonto) and Peppol
-- (LetsPeppol) rows. No JSON blobs — typed columns only.
-- Reused columns: merchant_response_driver / _response /
-- _reference / _successful.
-- ---------------------------------------------------------
ALTER TABLE `ip_merchant_responses`
  MODIFY COLUMN `invoice_id` INT(11) NULL,

  ADD COLUMN IF NOT EXISTS `merchant_client_id` INT(11) NULL
    COMMENT 'FK to ip_merchant_clients — NULL for legacy payment-gateway rows'
    AFTER `invoice_id`,

  ADD COLUMN IF NOT EXISTS `direction` VARCHAR(3) NOT NULL DEFAULT 'out'
    COMMENT 'MerchantResponseDirection enum value: in | out'
    AFTER `merchant_client_id`,

  ADD COLUMN IF NOT EXISTS `record_type` VARCHAR(50) NOT NULL DEFAULT 'payment'
    COMMENT 'MerchantResponseType enum value: payment | outbound_status | incoming_invoice | invoice_event'
    AFTER `direction`,

  ADD COLUMN IF NOT EXISTS `status` VARCHAR(50) NULL
    COMMENT 'MerchantResponseStatus enum value'
    AFTER `record_type`,

  ADD COLUMN IF NOT EXISTS `http_code` SMALLINT NULL
    AFTER `status`,

  ADD COLUMN IF NOT EXISTS `error_code` VARCHAR(100) NULL
    COMMENT 'Structured error code as returned by the provider'
    AFTER `http_code`,

  ADD COLUMN IF NOT EXISTS `error_detail` VARCHAR(500) NULL
    COMMENT 'Human-readable error detail — not a raw JSON dump'
    AFTER `error_code`,

  ADD COLUMN IF NOT EXISTS `peppol_participant_id` VARCHAR(100) NULL
    COMMENT 'Sender or receiver PEPPOL participant identifier, e.g. 0106:12345678'
    AFTER `error_detail`,

  ADD COLUMN IF NOT EXISTS `peppol_document_type` VARCHAR(255) NULL
    COMMENT 'PeppolDocumentType enum value — the BIS document type URN'
    AFTER `peppol_participant_id`,

  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NULL
    COMMENT 'Full datetime precision — merchant_response_date stores the DATE portion'
    AFTER `peppol_document_type`,

  ADD COLUMN IF NOT EXISTS `raw_payload` LONGTEXT NULL
    COMMENT 'Bounded provider audit JSON with credentials, signed URLs, document bodies, and personal identifiers redacted'
    AFTER `created_at`,

  ADD COLUMN IF NOT EXISTS `event_hash` CHAR(64) NULL
    COMMENT 'SHA-256 of raw_payload — dedup key for incoming invoice events'
    AFTER `raw_payload`,

  ADD COLUMN IF NOT EXISTS `document_path` VARCHAR(500) NULL
    COMMENT 'Path relative to uploads/archive for a validated incoming document'
    AFTER `event_hash`,

  ADD COLUMN IF NOT EXISTS `document_name` VARCHAR(255) NULL
    COMMENT 'Sanitized original provider filename'
    AFTER `document_path`,

  ADD COLUMN IF NOT EXISTS `document_mime_type` VARCHAR(100) NULL
    AFTER `document_name`,

  ADD COLUMN IF NOT EXISTS `document_size` INT UNSIGNED NULL
    AFTER `document_mime_type`,

  ADD COLUMN IF NOT EXISTS `document_sha256` CHAR(64) NULL
    AFTER `document_size`,

  ADD COLUMN IF NOT EXISTS `document_profile` VARCHAR(100) NULL
    COMMENT 'Validated EInvoiceProfileRegistry code'
    AFTER `document_sha256`,

  ADD COLUMN IF NOT EXISTS `document_validation_status` VARCHAR(20) NULL
    COMMENT 'valid or failed'
    AFTER `document_profile`,

  ADD COLUMN IF NOT EXISTS `document_validation_error` VARCHAR(1000) NULL
    AFTER `document_validation_status`;

CREATE INDEX IF NOT EXISTS `idx_merchant_client_id`       ON `ip_merchant_responses` (`merchant_client_id`);
CREATE INDEX IF NOT EXISTS `idx_record_type`              ON `ip_merchant_responses` (`record_type`);
CREATE INDEX IF NOT EXISTS `idx_status`                   ON `ip_merchant_responses` (`status`);
CREATE INDEX IF NOT EXISTS `idx_incoming_document_sha256` ON `ip_merchant_responses` (`document_sha256`);
CREATE INDEX IF NOT EXISTS `idx_event_dedup`              ON `ip_merchant_responses` (`merchant_client_id`, `record_type`, `event_hash`);


-- ---------------------------------------------------------
-- All response logging now goes through ip_merchant_responses. Fold any rows
-- from the superseded table across before it is dropped so no provider audit
-- trail is lost. ip_einvoice_responses only ever existed on a development
-- install that ran the old standalone `einvoice` module's own migration — a
-- released database never had it. The guarded CREATE keeps this consolidated
-- migration re-runnable: on a database that never had the table it makes an
-- empty one, the fold copies nothing, and the DROP removes it again.
-- request_json / response_json / status / timestamps are carried across in
-- raw_payload; no rows are discarded.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ip_einvoice_responses` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `merchant_client_id` INT NOT NULL,
  `direction`          ENUM('out','in') NOT NULL DEFAULT 'out',
  `record_type`        VARCHAR(50) NOT NULL DEFAULT 'outbound_status',
  `invoice_id`         INT NULL,
  `external_id`        VARCHAR(255) NULL,
  `status`             VARCHAR(50) DEFAULT 'draft',
  `message`            TEXT NULL,
  `http_code`          INT NULL,
  `request_json`       LONGTEXT NULL,
  `response_json`      LONGTEXT NULL,
  `created_at`         DATETIME NULL,
  `updated_at`         DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `ip_merchant_responses`
  (`invoice_id`, `merchant_client_id`, `direction`, `record_type`, `status`,
   `http_code`, `merchant_response_date`, `merchant_response_driver`,
   `merchant_response`, `merchant_response_reference`,
   `merchant_response_successful`, `created_at`, `raw_payload`)
SELECT
  `e`.`invoice_id`,
  `e`.`merchant_client_id`,
  `e`.`direction`,
  `e`.`record_type`,
  `e`.`status`,
  `e`.`http_code`,
  DATE(COALESCE(`e`.`created_at`, UTC_TIMESTAMP())),
  LEFT(COALESCE(`mc`.`merchant_type`, 'einvoice'), 35),
  LEFT(COALESCE(`e`.`message`, ''), 255),
  COALESCE(`e`.`external_id`, ''),
  CASE LOWER(`e`.`status`)
    WHEN 'error'    THEN 0
    WHEN 'failed'   THEN 0
    WHEN 'rejected' THEN 0
    WHEN 'sent'     THEN 1
    WHEN 'accepted' THEN 1
    WHEN 'received' THEN 1
    ELSE NULL
  END,
  `e`.`created_at`,
  CONCAT(
    '{"folded_from":"ip_einvoice_responses","request":', COALESCE(`e`.`request_json`, 'null'),
    ',"response":', COALESCE(`e`.`response_json`, 'null'),
    ',"updated_at":', IF(`e`.`updated_at` IS NULL, 'null', CONCAT('"', `e`.`updated_at`, '"')),
    '}'
  )
FROM `ip_einvoice_responses` `e`
LEFT JOIN `ip_merchant_clients` `mc` ON `mc`.`id` = `e`.`merchant_client_id`;

DROP TABLE IF EXISTS `ip_einvoice_responses`;


-- ---------------------------------------------------------
-- ip_clients — Peppol electronic address, format {ICD}:{identifier}
--   CZ IČO 0130:27325502 · NL KVK 0106:87654321 ·
--   FR SIRET 0009:12345678900012 · NO Org.nr 0192:123456789 ·
--   EU VAT 9906:NL123456789B01
-- ---------------------------------------------------------
ALTER TABLE `ip_clients`
  ADD COLUMN IF NOT EXISTS `client_peppol_id` VARCHAR(100) NULL
    COMMENT 'Peppol electronic address: {ICD}:{identifier}, e.g. 0130:27325502 (CZ IČO)'
    AFTER `client_einvoicing_version`;


-- ---------------------------------------------------------
-- ip_users — the sender's own e-invoicing electronic address.
-- Mdl_users::validation_rules() already persists this field.
-- ---------------------------------------------------------
ALTER TABLE `ip_users`
  ADD COLUMN IF NOT EXISTS `user_einvoice_identifier` VARCHAR(100) NULL
    COMMENT 'Sender Peppol electronic address / e-invoicing identifier, e.g. {ICD}:{identifier}'
    AFTER `user_company`;


-- ---------------------------------------------------------
-- ip_integration_sync_runs — operational ledger for e-invoicing
-- synchronization runs (one row per triggered sync).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ip_integration_sync_runs` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_client_id` INT NOT NULL,
  `correlation_id`     CHAR(32) NOT NULL,
  `trigger_type`       VARCHAR(20) NOT NULL,
  `sync_scope`         VARCHAR(20) NOT NULL,
  `status`             VARCHAR(20) NOT NULL,
  `attempt_count`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `incoming_received`  INT UNSIGNED NOT NULL DEFAULT 0,
  `incoming_archived`  INT UNSIGNED NOT NULL DEFAULT 0,
  `incoming_skipped`   INT UNSIGNED NOT NULL DEFAULT 0,
  `incoming_failed`    INT UNSIGNED NOT NULL DEFAULT 0,
  `events_received`    INT UNSIGNED NOT NULL DEFAULT 0,
  `events_created`     INT UNSIGNED NOT NULL DEFAULT 0,
  `events_skipped`     INT UNSIGNED NOT NULL DEFAULT 0,
  `error_summary`      VARCHAR(2000) NULL,
  `duration_ms`        INT UNSIGNED NULL,
  `started_at`         DATETIME NOT NULL,
  `finished_at`        DATETIME NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sync_correlation_id` (`correlation_id`),
  KEY `idx_sync_client_started` (`merchant_client_id`, `started_at`),
  KEY `idx_sync_status_started` (`status`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------
-- Service catalogue and per-client service assignments.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ip_services` (
  `service_id`   INT NOT NULL AUTO_INCREMENT,
  `service_name` VARCHAR(255) NOT NULL,

  PRIMARY KEY (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `ip_client_services` (
  `client_id`  INT NOT NULL,
  `service_id` INT NOT NULL,

  PRIMARY KEY (`client_id`, `service_id`),
  KEY `idx_client_services_service_id` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


-- ---------------------------------------------------------
-- Associate a single service with an invoice / quote.
-- ---------------------------------------------------------
ALTER TABLE `ip_invoices`
  ADD COLUMN IF NOT EXISTS `service_id` INT(11) DEFAULT 0 AFTER `creditinvoice_parent_id`;

ALTER TABLE `ip_quotes`
  ADD COLUMN IF NOT EXISTS `service_id` INT(11) DEFAULT 0 AFTER `notes`;

-- ---------------------------------------------------------
-- Payment reminders
-- ---------------------------------------------------------
-- Payment reminder log. The UNIQUE key is the dedup guarantee: the reminder engine
-- claims an (invoice, type, offset) slot with INSERT IGNORE before sending, so two
-- concurrent cron runs can never both email the same reminder: the loser of the race
-- gets affected_rows() = 0 and skips the send.
--
-- reminder_type:   'before_due' | 'overdue'
-- reminder_status: 'pending' (claimed) | 'sent' | 'failed' | 'skipped'
-- IF NOT EXISTS so the migration survives a database that already carries the
-- table but has an older ip_versions history -- e.g. a production dump restored
-- over a newer development schema. Same guard as ip_sessions in 019_1.4.7.sql.
CREATE TABLE IF NOT EXISTS `ip_invoice_reminders` (
  `reminder_id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `invoice_id`         INT(11)      NOT NULL,
  `reminder_type`      VARCHAR(20)  NOT NULL,
  `reminder_offset`    INT(11)      NOT NULL,
  `reminder_status`    VARCHAR(20)  NOT NULL DEFAULT 'pending',
  `reminder_date_sent` DATETIME     NOT NULL,
  `reminder_email_to`  VARCHAR(255)          DEFAULT NULL,
  PRIMARY KEY (`reminder_id`),
  UNIQUE KEY `idx_reminder_slot` (`invoice_id`, `reminder_type`, `reminder_offset`),
  KEY `invoice_id` (`invoice_id`)
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8;

-- Per-client and per-invoice reminder opt-out. Both default to 0 (reminders allowed);
-- the global `invoice_reminders_enabled` setting is the master switch above them.
ALTER TABLE `ip_clients`
  ADD COLUMN IF NOT EXISTS `client_disable_reminders` TINYINT(1) NOT NULL DEFAULT '0' AFTER `client_active`;

ALTER TABLE `ip_invoices`
  ADD COLUMN IF NOT EXISTS `invoice_disable_reminders` TINYINT(1) NOT NULL DEFAULT '0' AFTER `invoice_status_id`;

-- ---------------------------------------------------------
-- Per-user authentication version: bumped on a password change so every
-- other session of that user is invalidated.
-- ---------------------------------------------------------
ALTER TABLE `ip_users` ADD COLUMN IF NOT EXISTS `user_auth_version` INT(10) UNSIGNED DEFAULT 1 NOT NULL;

-- ---------------------------------------------------------
-- Supplier invoices
-- ---------------------------------------------------------
-- Supplier accounting register for validated incoming e-invoices.

CREATE TABLE IF NOT EXISTS `ip_suppliers` (
  `supplier_id` INT(11) NOT NULL AUTO_INCREMENT,
  `supplier_name` VARCHAR(255) NOT NULL,
  `supplier_company` VARCHAR(255) NULL,
  `supplier_vat_id` VARCHAR(100) NULL,
  `supplier_tax_code` VARCHAR(100) NULL,
  `supplier_peppol_id` VARCHAR(100) NULL,
  `supplier_email` VARCHAR(255) NULL,
  `supplier_address_1` VARCHAR(255) NULL,
  `supplier_address_2` VARCHAR(255) NULL,
  `supplier_city` VARCHAR(100) NULL,
  `supplier_state` VARCHAR(100) NULL,
  `supplier_zip` VARCHAR(30) NULL,
  `supplier_country` VARCHAR(2) NULL,
  `supplier_active` TINYINT(1) NOT NULL DEFAULT 1,
  `supplier_date_created` DATE NOT NULL,
  `supplier_date_modified` DATE NOT NULL,
  PRIMARY KEY (`supplier_id`),
  KEY `idx_supplier_peppol_id` (`supplier_peppol_id`),
  KEY `idx_supplier_vat_id` (`supplier_vat_id`),
  KEY `idx_supplier_name` (`supplier_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ip_supplier_invoices` (
  `supplier_invoice_id` INT(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` INT(11) NULL,
  `incoming_response_id` INT(11) NOT NULL,
  `merchant_client_id` INT(11) NULL,
  `external_reference` VARCHAR(255) NULL,
  `supplier_invoice_number` VARCHAR(255) NULL,
  `supplier_invoice_date` DATE NULL,
  `supplier_due_date` DATE NULL,
  `currency_code` CHAR(3) NOT NULL DEFAULT 'EUR',
  `subtotal` DECIMAL(20, 6) NULL,
  `tax_total` DECIMAL(20, 6) NULL,
  `total` DECIMAL(20, 6) NULL,
  `amount_paid` DECIMAL(20, 6) NOT NULL DEFAULT 0,
  `status` VARCHAR(30) NOT NULL DEFAULT 'received',
  `document_path` VARCHAR(500) NULL,
  `document_name` VARCHAR(255) NULL,
  `document_mime_type` VARCHAR(100) NULL,
  `document_sha256` CHAR(64) NULL,
  `raw_payload` LONGTEXT NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`supplier_invoice_id`),
  UNIQUE KEY `uq_supplier_invoice_response` (`incoming_response_id`),
  KEY `idx_supplier_invoice_supplier` (`supplier_id`),
  KEY `idx_supplier_invoice_status` (`status`),
  KEY `idx_supplier_invoice_reference` (`merchant_client_id`, `external_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ip_supplier_invoice_items` (
  `supplier_invoice_item_id` INT(11) NOT NULL AUTO_INCREMENT,
  `supplier_invoice_id` INT(11) NOT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `item_description` TEXT NULL,
  `quantity` DECIMAL(20, 6) NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(20, 6) NULL,
  `tax_rate` DECIMAL(8, 4) NULL,
  `subtotal` DECIMAL(20, 6) NULL,
  `tax_total` DECIMAL(20, 6) NULL,
  `total` DECIMAL(20, 6) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`supplier_invoice_item_id`),
  KEY `idx_supplier_invoice_items_invoice` (`supplier_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payments and status history.
ALTER TABLE `ip_supplier_invoices`
  MODIFY `incoming_response_id` INT(11) NULL;

CREATE TABLE IF NOT EXISTS `ip_supplier_invoice_payments` (
  `supplier_invoice_payment_id` INT(11) NOT NULL AUTO_INCREMENT,
  `supplier_invoice_id` INT(11) NOT NULL,
  `payment_date` DATE NOT NULL,
  `amount` DECIMAL(20, 6) NOT NULL,
  `currency_code` CHAR(3) NOT NULL DEFAULT 'EUR',
  `payment_method` VARCHAR(100) NULL,
  `reference` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`supplier_invoice_payment_id`),
  KEY `idx_supplier_invoice_payment_invoice` (`supplier_invoice_id`),
  KEY `idx_supplier_invoice_payment_date` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ip_supplier_invoice_status_history` (
  `supplier_invoice_status_history_id` INT(11) NOT NULL AUTO_INCREMENT,
  `supplier_invoice_id` INT(11) NOT NULL,
  `old_status` VARCHAR(30) NULL,
  `new_status` VARCHAR(30) NOT NULL,
  `comment` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`supplier_invoice_status_history_id`),
  KEY `idx_supplier_invoice_status_history_invoice` (`supplier_invoice_id`),
  KEY `idx_supplier_invoice_status_history_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Additional documents attached to supplier invoices.

CREATE TABLE IF NOT EXISTS `ip_supplier_invoice_attachments` (
  `supplier_invoice_attachment_id` INT(11) NOT NULL AUTO_INCREMENT,
  `supplier_invoice_id` INT(11) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `storage_path` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` BIGINT(20) NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`supplier_invoice_attachment_id`),
  UNIQUE KEY `uq_supplier_invoice_attachment_hash` (`supplier_invoice_id`, `sha256`),
  KEY `idx_supplier_invoice_attachment_invoice` (`supplier_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prevent duplicate supplier invoice numbers for the same supplier.

ALTER TABLE `ip_supplier_invoices`
  ADD UNIQUE KEY IF NOT EXISTS `uq_supplier_invoice_supplier_number` (`supplier_id`, `supplier_invoice_number`);

-- Reversible supplier invoice archiving.

ALTER TABLE `ip_supplier_invoices`
  ADD COLUMN IF NOT EXISTS `archived_at` DATETIME NULL AFTER `updated_at`,
  ADD COLUMN IF NOT EXISTS `archived_by` INT(11) NULL AFTER `archived_at`,
  ADD KEY IF NOT EXISTS `idx_supplier_invoice_archived_at` (`archived_at`);
