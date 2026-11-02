# Deploying Zveca HRMS without touching live data

A release changes **code only** (design and features). Existing live database records and uploaded files are never overwritten, changed or removed. New features arrive as new tables, new columns and new settings, which the live site adds to its own database on the first request after the deploy.

What each release adds is in [CHANGELOG.md](CHANGELOG.md).

## The rules (why live data is safe)

| Protection | What it guarantees |
|---|---|
| **Code-only package** | `php app/tools/release.php` never packs `app/storage/` (uploads, logs, backups), `app/config.php`, `app/config.local.php`, `.git` or `_legacy/`, and refuses to finish if one slips in. Extracting it over the live folder leaves those untouched. |
| **The database is never uploaded** | The local database stays local. Never import a local dump on the live site or copy local tables over live ones. Live data is only ever changed by the people using the live site. |
| **Additive-only upgrades** | On the first request after a deploy, `ensure_schema()` (`app/lib/schema.php`) only creates tables, adds columns and indexes, inserts *new* settings (`INSERT IGNORE`) and fills columns created in that same run. It never updates or deletes an existing value. |
| **Enforced before packaging** | `app/tools/safety-check.php` (run automatically by `release.php`) fails the release if the upgrade code contains an `UPDATE`, `DELETE`, `REPLACE`, `TRUNCATE`, `DROP` or `ALTER … MODIFY/CHANGE/RENAME` without a reviewed `data-safe:` justification. It also runs `php -l` on every file and rejects debugging output. |
| **Rehearsal on a copy of live** | `app/tools/rehearse.php <live backup>` imports the live backup into a throwaway local database, runs the upgrade and proves with a data fingerprint that no existing record changed. It then runs every scheduled job and allows only their date-based status changes (for example an ownership lock past its end date becoming *Expired*); any other change fails. Mail and payment-gateway calls are switched off, so nobody is contacted. |
| **Automatic backup + lock** | Before an upgrade the live database is backed up to `app/storage/backups/` (newest 15 kept). A database lock makes parallel first requests wait, so the upgrade runs once. |
| **Optional data updates** | Any change to *existing* records (for example giving a new module to logins with a custom permission list) is only offered under **Administration → Database & Updates**. It runs only when a Super Admin clicks **Apply**, after another backup. |
| **Data fingerprint** | Row count + content hash of every table, taken on the live site before the deploy and verified after it. New tables, columns and rows don't count as changes. Any edited or deleted existing record is reported. |

## Release checklist

### 1. Local: prepare

1. Finish and test the change locally.
2. Bump `APP_BUILD` in `app/bootstrap.php`.
3. If the database structure changed:
   - Add the new tables / columns in `app/lib/schema.php` and bump `SCHEMA_VERSION`.
   - Follow the rules comment inside `ensure_schema()`.
   - Anything that must change existing rows goes into `data_updates()` in `app/lib/deploy.php`, never into the upgrade.
4. `php app/tools/safety-check.php` must end with **OK: safe to package**.

### 2. Rehearse with a copy of live (recommended for every release with a schema change)

5. On the live site: **Administration → Database & Updates → Back up now**, then download the `.sql.gz` (or phpMyAdmin → Export).
6. Locally:

   ```
   php app/tools/rehearse.php path/to/live-backup.sql.gz
   ```

   It must end with **REHEARSAL PASSED**. It also lists the optional data updates the live site will offer.
7. Keep the downloaded backup somewhere safe and off the server; delete it when no longer needed (it contains personal data).

### 3. Build the package

8. `php app/tools/release.php` produces three files in `app/storage/releases/`:
   - the zip;
   - a manifest with a SHA-256 hash for every file;
   - `…changes.txt`, listing files added / changed / removed since the previous package.
9. Read `changes.txt`. If it flags server configuration files (`.htaccess`, `robots.txt`, `composer.*`) and the hosting provider edited the live copies, compare before overwriting.

### 4. Live site: deploy

10. Sign in as Super Admin → **Administration → Database & Updates**:
    - **Back up now** (download it).
    - Take a **data fingerprint** labelled `before-deploy`.
11. Upload the zip to the site folder (Hostinger File Manager) and **extract it over the existing files**.
    - Do not delete folders first and do not "replace folder".
    - Uploads, logs, backups and `app/config.php` are not in the zip, so they stay as they are.
12. Open any page once. The upgrade runs on that request: backup first, additive only, recorded under **Upgrade history**.

### 5. Live site: verify

13. **Database & Updates → Verify** next to the `before-deploy` fingerprint. Expected: *all existing data is unchanged*.
    - Edits people make in between also show up, so do steps 10–13 close together, ideally in a quiet hour.
    - The same goes for date-based status changes the site makes on its own (locks, signing requests or subscriptions reaching their end date) and for activity bookkeeping (last-seen times, notification read state).
14. Review **Optional data updates**: **Apply** the ones you want (a backup is taken first) or **Dismiss** them. Nothing is applied on its own.
15. Follow the "After deploying" steps for this release in [CHANGELOG.md](CHANGELOG.md).

With SSH, steps 10, 13 and 14 also work from the command line:

```
php app/tools/data-check.php backup
php app/tools/data-check.php snapshot before-deploy
php app/tools/data-check.php verify latest     # exit code 0 = unchanged, 2 = changed
php app/tools/data-check.php status            # schema version + optional updates offered
```

## Rolling back

- **Code:** extract the previous release zip over the folder. Upgrades only *add*, so older code runs on the newer database; the extra tables and columns are simply unused.
- **Data** (only if ever needed): every upgrade leaves a backup in `app/storage/backups/` named `…-before-upgrade-vX-to-vY.sql.gz`. Restore with phpMyAdmin → select the database → **Import**, or over SSH:

  ```
  gunzip -c app/storage/backups/FILE.sql.gz | mysql -u USER -p DATABASE
  ```

  A restore replaces the whole database with the backup, so anything entered after the backup is lost. It is a last resort, not part of a normal deploy.

## Configuration

- `app/config.php` is **not** in the package, so the live database password and keys stay as they are on the server. If you deliberately changed it, build with `php app/tools/release.php --with-config` and check the live values first.
- `app/config.local.php` is read only on localhost and is never deployed or committed.
- No environment variables are needed on the live server. `HRMS_TEST_DB` and `HRMS_REHEARSAL` are for local tests and rehearsals only.

## Scheduled jobs (optional)

Reminders, expiries and background indexing also run from normal page loads (at most every 30 minutes). For punctual runs add a cron job every 30 minutes:

```
php /home/USER/public_html/app/cron.php
```

## Tools

| Command | Purpose | Writes |
|---|---|---|
| `php app/tools/safety-check.php` | Pre-release gate: additive-only upgrade code, PHP syntax, no debug output, data files excluded | nothing |
| `php app/tools/rehearse.php <backup> [--keep]` | Upgrade on a throwaway copy of live (fingerprint must stay unchanged), then scheduled jobs (only date-based status changes allowed) | only the throwaway `hrms_rehearsal` database (dropped afterwards) |
| `php app/tools/release.php [--with-config] [--skip-checks]` | Code-only zip + manifest + change list | `app/storage/releases/` |
| `php app/tools/data-check.php backup\|snapshot\|verify\|status` | Backup, fingerprint, verify, status | `app/storage/backups/` (backup, snapshot) |

## Verified

- **v15 → v19:** run on a copy of a v15 database holding 6,600+ real records.
  - Upgrade completed with a backup.
  - The fingerprint showed all 47 existing tables unchanged.
  - The backup restored to an identical copy.
  - Three simultaneous first requests produced a single upgrade.
- **Each of v21, v22, v23 and v24:** rehearsed the same way. The v24 package was rehearsed with `rehearse.php` on a v23 copy: upgrade, then every scheduled job, then 85 existing tables verified unchanged, with 0 e-mails sent.
- **Negative tests:**
  - A planted `DELETE` / `DROP` in the upgrade code was rejected by `safety-check.php`.
  - A planted change to an existing record in a scheduled job made `rehearse.php` fail and name the table.
- **v25:** `rehearse.php` on the v23 copy: upgrade v23 → v25, 85 existing tables unchanged; the scheduled jobs then expired one ownership lock whose end date had passed (the expected v20 behaviour) and changed nothing else.
