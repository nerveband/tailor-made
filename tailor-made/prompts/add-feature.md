# Add Feature

Prompt template for planning and implementing a new feature in Tailor Made.

## Instructions

Paste this prompt into Claude Code when starting a new feature.

---

## Prompt

```
I want to add a new feature to the Tailor Made WordPress plugin.

**Feature:** [describe the feature]

Before writing any code:

1. Read `tailor-made/AGENTS.md` for architecture, quirks, and patterns
2. Read `tailor-made/CLAUDE.md` for project instructions
3. Explore the existing code related to this feature area
4. Enter plan mode and write an implementation plan that covers:
   - Which files need to change and why
   - New files to create (if any)
   - Database changes (if any — remember to handle in activation hook)
   - Admin UI changes (which tab, what to add)
   - Bricks dynamic data changes (if any)
   - Shortcode changes (if any)
   - How to test the feature on staging
5. After plan approval, execute the plan using subagent-driven development
6. Deploy to staging and verify
7. Update documentation (README, admin tabs, AGENTS.md) — see prompts/update-docs.md

Key constraints:
- Bricks meta writes MUST use $wpdb->update() directly (not update_post_meta)
- All meta keys use the _tt_ prefix
- Deploy via SCP to staging (runcloud@23.94.202.65)
- Test on ts-staging.wavedepth.com
```
