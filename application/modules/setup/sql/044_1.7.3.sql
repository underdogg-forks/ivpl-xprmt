-- Security: Add payment_external_id to prevent duplicate payment processing.
-- This column stores external payment identifiers (e.g., PayPal capture IDs and
-- Stripe payment_intent IDs) to prevent duplicate payment callbacks from being
-- processed multiple times. It was originally added in 042_1.7.1.sql but was
-- lost when that migration was renumbered/replaced; the guest gateway
-- controllers (Paypal.php, Stripe.php) have depended on it ever since.

-- Add the column first (nullable, no index yet so duplicates can exist).
ALTER TABLE `ip_payments`
    ADD COLUMN `payment_external_id` VARCHAR(255) DEFAULT NULL AFTER `payment_note`;

-- De-duplicate any duplicate payment_external_id values recorded before the
-- unique index existed. The earliest row keeps its reference; later duplicates
-- are suffixed with "-dup<payment_id>" so payment history stays intact.
UPDATE `ip_payments` AS `target`
JOIN (
    SELECT `payment_external_id`, MIN(`payment_id`) AS `keep_id`
    FROM `ip_payments`
    WHERE `payment_external_id` IS NOT NULL
    GROUP BY `payment_external_id`
    HAVING COUNT(*) > 1
) AS `dupes` ON `target`.`payment_external_id` = `dupes`.`payment_external_id`
   AND `target`.`payment_id` <> `dupes`.`keep_id`
SET `target`.`payment_external_id` = CONCAT(`target`.`payment_external_id`, '-dup', `target`.`payment_id`);

-- Now that duplicates are resolved, the unique index can be enforced.
ALTER TABLE `ip_payments`
    ADD UNIQUE INDEX `idx_payment_external_id` (`payment_external_id`);
