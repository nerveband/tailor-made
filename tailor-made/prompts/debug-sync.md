# Debug Sync

Prompt for diagnosing sync issues in Tailor Made.

## Instructions

Paste this prompt into Claude Code when sync isn't working correctly.

---

## Prompt

```
Help me debug a sync issue in the Tailor Made plugin on staging.

**Problem:** [describe what's wrong — e.g. events not appearing, wrong data, sync failing]

Diagnostic steps:

1. **Check box offices** — list all box offices and their status:
   ```
   ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp db query \"SELECT id, name, slug, status FROM wp_tailor_made_box_offices\""
   ```

2. **Test API keys** — verify each box office can reach the API:
   ```
   ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp eval '
   \$offices = Tailor_Made_Box_Office_Manager::get_all(\"active\");
   foreach (\$offices as \$o) {
       \$key = Tailor_Made_Box_Office_Manager::get_decrypted_key(\$o->id);
       \$client = new Tailor_Made_API_Client(\$key);
       \$ping = \$client->ping();
       echo \$o->name . \": \" . (\$ping ? \"OK\" : \"FAILED\") . \"\\n\";
   }'"
   ```

3. **Check recent logs** — look for errors in the sync log:
   ```
   ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp db query \"SELECT created_at, level, message, box_office_name FROM wp_tailor_made_sync_log ORDER BY id DESC LIMIT 20\""
   ```

4. **Compare events** — see what TT has vs what WP has:
   ```
   ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp post list --post_type=tt_event --fields=ID,post_title,post_status --format=table"
   ```

5. **Check cron** — verify sync cron is registered:
   ```
   ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp cron event list --fields=hook,next_run_relative | grep tailor"
   ```

6. **Run manual sync** — trigger sync and capture output:
   ```
   ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp eval 'print_r(Tailor_Made_Sync_Engine::sync_all_box_offices());'"
   ```

7. **Check taxonomy terms** — verify box office terms exist:
   ```
   ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp term list tt_box_office --fields=term_id,name,slug,count --format=table"
   ```

Analyze the output and identify the root cause. Propose a fix if possible.
```
