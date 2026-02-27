# Update Documentation

Prompt for updating documentation when features change.

## Instructions

Paste this prompt into Claude Code after implementing a feature that needs documentation updates.

---

## Prompt

```
Update the Tailor Made documentation for the following change:

**What changed:** [describe the feature or change]

Files to check and update:

1. **`tailor-made/README.md`** — user-facing documentation
   - "What it does" bullet list
   - Configure section (if settings changed)
   - Multiple Box Offices section (if box office behavior changed)
   - Bricks dynamic data tags table (if new tags added)
   - Post meta reference table (if new meta fields)
   - Shortcodes section (if shortcode behavior changed)
   - After updating, copy to root: `cp tailor-made/README.md README.md`

2. **`tailor-made/includes/class-admin.php`** — in-plugin documentation tabs
   - `render_tab_how_to_use()` — step-by-step guide, meta fields table, dynamic data table, "Where to Find Things"
   - `render_tab_how_sync_works()` — sync cycle, matching logic, meta fields table
   - `render_tab_shortcodes()` — shortcode reference, attributes tables, examples with copy buttons
   - `render_tab_about()` — changelog (add entry for this change)

3. **`tailor-made/AGENTS.md`** — developer/AI agent reference
   - File tree (if new files added)
   - Architecture notes (if patterns changed)
   - Key quirks (if new quirks discovered)
   - Common operations (if new CLI commands useful)
   - AJAX endpoints (if new admin actions)

4. **PHP syntax check** after editing class-admin.php:
   ```
   php -l tailor-made/includes/class-admin.php
   ```

5. **Deploy and verify** — upload class-admin.php to staging and check each tab renders:
   ```
   scp "tailor-made/includes/class-admin.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/includes/class-admin.php
   ```
```
