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
  ADD COLUMN `client_disable_reminders` TINYINT(1) NOT NULL DEFAULT '0' AFTER `client_active`;

ALTER TABLE `ip_invoices`
  ADD COLUMN `invoice_disable_reminders` TINYINT(1) NOT NULL DEFAULT '0' AFTER `invoice_status_id`;

-- Pre-1.7 installations could end up with the client form's internal 'custom' sentinel value
-- saved directly into ip_clients.client_title instead of the free-text title it stands for,
-- which 1.7's templates then print literally as "Custom" on every invoice/quote/client view.
UPDATE `ip_clients` SET `client_title` = NULL WHERE CAST(`client_title` AS BINARY) = 'custom';
