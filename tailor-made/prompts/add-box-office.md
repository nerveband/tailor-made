# Add Box Office

Prompt for adding a new box office to the system.

## Instructions

Use this when you need to add a new Ticket Tailor box office to the plugin.

---

## Via Admin UI

```
Add a new box office to Tailor Made:

1. Go to ts-staging.wavedepth.com/wp-admin/admin.php?page=tailor-made (Dashboard tab)
2. Click "Add Box Office"
3. Enter:
   - Name: [box office name]
   - API Key: [the sk_ key from Ticket Tailor]
4. Click Save
5. Click "Test Connection" next to the new box office
6. Click "Sync Now" to pull events
7. Verify events appear under Tailor Made > Events with the correct box office taxonomy term
```

## Via WP-CLI (for testing)

```
Add a new box office via WP-CLI on staging for testing:

ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp eval '
\$mgr = Tailor_Made_Box_Office_Manager::class;
\$result = \$mgr::create([
    \"name\" => \"[Box Office Name]\",
    \"api_key\" => \"[sk_xxxxx]\",
]);
print_r(\$result);
'"

Then run a sync:
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp eval 'print_r(Tailor_Made_Sync_Engine::sync_all_box_offices());'"

Verify:
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp post list --post_type=tt_event --fields=ID,post_title,post_status --format=table"
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp term list tt_box_office --fields=term_id,name,slug,count --format=table"
```
