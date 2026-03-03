# Release Checklist

Pre-release verification checklist for Tailor Made. Run through this **before** tagging a release.

## Instructions

Paste this prompt into Claude Code after all feature work is done and deployed to staging.

---

## Prompt

```
Run the Tailor Made pre-release checklist for version [X.Y.Z].

### 1. PHP Syntax Check
Run `php -l` on every PHP file in `tailor-made/includes/`:
```
find tailor-made/includes/ -name "*.php" -exec php -l {} \;
php -l tailor-made/tailor-made.php
```
All files must pass with no errors.

### 2. Deploy to Staging
Upload all modified files via SCP:
```
scp "tailor-made/<file>" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/<file>
```
If activation hooks changed, reactivate:
```
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp plugin deactivate tailor-made && wp plugin activate tailor-made"
```

### 3. Admin Page Smoke Test
Using Chrome DevTools, navigate to each admin tab and verify no PHP errors:
- [ ] Dashboard tab loads
- [ ] How To Use tab loads
- [ ] How Sync Works tab loads
- [ ] Shortcodes tab loads
- [ ] Magic Links tab loads
- [ ] Sync Log tab loads
- [ ] About tab loads (verify new changelog entry is visible)

### 4. Events List Columns
Navigate to Events → All Events (`edit.php?post_type=tt_event`):
- [ ] All custom columns render (Attendees, Roster Link, Site Visibility)
- [ ] Attendee counts display correctly (number or —)
- [ ] Roster Link shows Generate button or link/copy icons as appropriate
- [ ] Site Visibility toggle works (eye icon toggles)

### 5. Roster Link Actions
- [ ] Click "Generate" on an event without a roster link → icons appear
- [ ] Click link icon → roster page opens in new tab
- [ ] Click copy icon → URL copied to clipboard, "Copied!" tooltip appears

### 6. Sync Test
Run a manual sync and verify:
```
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp eval 'print_r(Tailor_Made_Sync_Engine::sync_all_box_offices());'"
```
- [ ] Sync completes without errors
- [ ] Events still exist with correct data:
```
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp post list --post_type=tt_event --fields=ID,post_title,post_status --format=table"
```
- [ ] Hidden events remain draft after sync (visibility preserved)

### 7. Front-End Check
- [ ] Events page renders: ts-staging.wavedepth.com/events/
- [ ] Individual event pages load correctly

### 8. Box Office Verification
- [ ] Both box offices show in Dashboard tab
- [ ] Events are tagged with correct box office taxonomy terms

### 9. Version Consistency
Verify version matches in all locations:
```
grep -n 'TAILOR_MADE_VERSION\|Version:' tailor-made/tailor-made.php
```
Both must show the same version number.

### 10. Git Status Clean
```
git status
git diff --stat
```
Confirm all intended changes are staged and no unintended files are included.

Report PASS/FAIL for each item. All must pass before proceeding with `release-version.md`.
```
