# Release Version

Prompt for releasing a new version of Tailor Made.

## Instructions

Paste this prompt into Claude Code when you're ready to tag a new release.

---

## Prompt

```
Release version [X.Y.Z] of the Tailor Made plugin.

Steps:

1. **Version bump** — update the version in these locations:
   - `tailor-made/tailor-made.php` → `TAILOR_MADE_VERSION` constant
   - `tailor-made/tailor-made.php` → `Version:` in the plugin header comment

2. **Changelog** — add a changelog entry in:
   - `tailor-made/includes/class-admin.php` → `render_tab_about()` → Changelog section
   - Include all changes since the last release

3. **Update README** — if any user-facing changes:
   - Update `tailor-made/README.md`
   - Copy to root `README.md`

4. **Update AGENTS.md** — if architecture changed

5. **Commit** — commit all version bump changes:
   ```
   git add tailor-made/tailor-made.php tailor-made/includes/class-admin.php tailor-made/README.md README.md tailor-made/AGENTS.md
   git commit -m "chore: bump version to X.Y.Z"
   ```

6. **Tag** — create a git tag:
   ```
   git tag -a vX.Y.Z -m "vX.Y.Z — [brief description]"
   ```

7. **Push** — push the commit and tag:
   ```
   git push origin feature/multi-box-office
   git push origin vX.Y.Z
   ```

8. **GitHub Release** — create a release on GitHub:
   ```
   gh release create vX.Y.Z --title "vX.Y.Z" --notes "[changelog notes]"
   ```

The GitHub auto-updater will pick up the new release and WordPress will show the update.
```
