# ADVISORY chqc: Cron Key Rotation Procedure

## Summary
Old guest and cron credentials (if disclosed) can be used to trigger scheduled tasks or
access guest invoices. The CRON_KEY can be safely rotated; guest keys cannot.

## Action Required

### 1. Rotate CRON_KEY (SAFE)
Execute this SQL on your database, then update your crontab entries:

```sql
-- Generate and update a new cron key
UPDATE ip_settings SET setting_value = UNHEX(MD5(RAND())) 
WHERE setting_key = 'cron_key';

-- Retrieve the new key
SELECT setting_value FROM ip_settings WHERE setting_key = 'cron_key';
```

Then update your crontab:
```bash
# OLD: curl "https://yourapp.local/invoices/cron/recur/OLD_CRON_KEY"
# NEW: curl "https://yourapp.local/invoices/cron/recur/NEW_CRON_KEY"
```

### 2. Guest Keys (DO NOT ROTATE)
Guest invoice keys are embedded in URLs sent to clients and archived in PDF filenames.
Rotating them breaks all guest access links. Instead:

- Use firewall/IP whitelist rules to restrict who can access guest URLs
- Monitor access logs for suspicious activity (guest URL scanning)
- Consider deprecating old invoice URLs after a grace period (document in your retention policy)

## Timeline
- Do this during a maintenance window (brief Cron interruption)
- Allow time for all scheduled cron jobs to re-register with the new key
- Test: `curl "https://yourapp.local/invoices/cron/recur/NEW_KEY?test=1"` (should return 200)
