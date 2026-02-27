# Deploy and Test

Step-by-step prompt for deploying changes to staging and running the test checklist.

## Instructions

Paste this prompt into Claude Code after making changes you want to test on staging.

---

## Prompt

```
Deploy the latest changes to the Tailor Made staging site and verify everything works.

Steps:

1. **PHP syntax check** — run `php -l` on all modified PHP files
2. **Upload via SCP** — upload changed files to staging:
   ```
   scp "tailor-made/<file>" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/<file>
   ```
3. **Reactivate if needed** — if activation hooks changed (DB tables, cron, new files in tailor-made.php):
   ```
   ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp plugin deactivate tailor-made && wp plugin activate tailor-made"
   ```
4. **Verify admin page** — load ts-staging.wavedepth.com/wp-admin/admin.php?page=tailor-made and check each tab renders without errors
5. **Test sync** — if sync engine changed, run a manual sync:
   ```
   ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp eval 'print_r(Tailor_Made_Sync_Engine::sync_all_box_offices());'"
   ```
6. **Check events** — verify events exist and have correct data:
   ```
   ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp post list --post_type=tt_event --fields=ID,post_title,post_status --format=table"
   ```
7. **Front-end check** — verify event pages render correctly at ts-staging.wavedepth.com/events/
8. **Check logs** — if logging changed, verify log entries:
   ```
   ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp db query \"SELECT * FROM wp_tailor_made_sync_log ORDER BY id DESC LIMIT 10\""
   ```

Report results for each step.
```
