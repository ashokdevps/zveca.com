# Changelog

What each release adds, newest first. Every release is code-only and additive: existing live data is never changed or removed (see DEPLOYMENT.md).
Each entry lists what the upgrade adds to the database, what HR / admins should do after deploying, and the automated tests that were run.

## Schema v26: the brand is now Zveca

- **New logo everywhere.** Three new images in `dashboard/assets/images/`:
  - `zveca-logo.png`: the wordmark for light backgrounds, with a transparent background.
  - `zveca-logo-light.png`: the wordmark for dark backgrounds.
  - `zveca-mark.png`: the "Z" app icon, 512 px.
- **Where the logo appears.** The website, sign-in / sign-up / onboarding pages, the dashboard sidebar, the favicon, the home-screen icon (manifest), the 404 page, invoices, payslips, offer letters and review letters.
  - A logo uploaded under Administration → Website still takes priority.
- **Built-in texts.** "Adorable IT Services", "Adorable HRMS" and "Adorable IT" became "Zveca" / "Zveca HRMS" in:
  - page titles, e-mails, WhatsApp messages and legal pages
  - the default settings, the website defaults, `robots.txt` (sitemap now at zveca.com) and the careers-page structured data
  - The footer "Company" links now go to sections of the home page instead of adorableit.com.
- **Unchanged:** e-mail addresses (`info@` / `hr@` / `noreply@adorableit.com`), phone numbers, the office address and social media links. Change them under Settings and Administration → Website once Zveca addresses exist.
- **Database:** no table change. The upgrade changes no stored value.
  - It offers an optional update under Administration → Database & Updates: **"Rename the company to Zveca in settings, website content and own job adverts"**. Nothing changes until you apply it.
  - It updates the company name setting, the Website content (draft and live), the company's own client record and the descriptions of its own jobs. A backup is taken first.
  - Historical records stay as they are: other clients, candidates' past employers, project names, invoices, subscriptions and logs.
- **After deploying:** Super Admin → Database & Updates → apply the Zveca update.
  - Rename internal project names that start with "Adorable IT Services" if you want them changed.
- **Tests run:**
  - `app/tools/safety-check.php`: OK.
  - `app/tools/rehearse.php` on a v25 copy: v25 → v26 left every existing record unchanged; the update is offered with 11 records.
  - Every dashboard module was opened as Super Admin: no PHP errors.
  - The home, pricing, careers, sign-in and 404 pages and the dashboard were checked in a headless browser on desktop and mobile: no script errors.

## Deepavali launch intro (no database change)

- **Click-to-launch intro** (`launch.php`):
  - The visitor sees a night sky with lanterns, diyas and a glowing **Launch ZVECA** button (or presses Enter).
  - Pressing it plays a 3-2-1 countdown. A rocket lifts off from the button, then comes a **BOOM**: flash, shockwave, screen shake and a fireworks show, with firecracker sound.
  - Then the ZVECA wordmark is revealed. About 5 seconds later the page dissolves into golden light.
- **The website opens with a boom.** The home page (`index.php`) fades in out of that light and fires its own fireworks, then shows a "Shubh Deepavali! Welcome to ZVECA" toast. It plays only right after the intro, never on a normal visit or a reload.
- **The home page stays at "/"** (`index.php`). It includes `launch.php` first, which shows the intro only:
  - between `INTRO_START` and `INTRO_END` (India time, top of `launch.php`; currently 25 Oct to 16 Nov 2026);
  - once per browser every 12 hours (cookie `zv_intro`).
  - Search engines, link-preview bots (WhatsApp, LinkedIn…), Lighthouse and other bots always get the home page, so SEO and share previews are unchanged.
  - `/launch` previews the intro at any time, and `/?intro=0` skips it. `/home` and `/index2` redirect to "/".
- **Sound** is on by default. The launch click is the gesture browsers need before they play audio. The toggle is remembered.
- **Reduced-motion users** get the reveal without fireworks or zoom.
- **One shared, dependency-free engine:** `dashboard/assets/js/zv-fireworks.js` (canvas fireworks and synthesised sound). The pages load no external scripts, only Google Fonts, so they work under the existing Content-Security-Policy. An optional offer line is set with `INTRO_OFFER`.
- **Fix:** website images uploaded in Administration → Website (`dashboard/file?f=site/…`, for example the header logo) were behind the login, so visitors saw a broken logo. They are now public. Every other upload folder still requires sign-in.
- **After Deepavali**, nothing needs to change: once `INTRO_END` passes, "/" serves the home page directly.

## Schema v25 — notifications, website editor, visitor and CRM usage insights

The upgrade adds five tables: in-app notifications, website content (draft and live per section), website revisions, anonymous page views, and daily CRM activity per login. It also adds one nullable column, `it_login.last_seen_at`, and three settings (`site_tracking`, `visit_retention_days`, `notif_poll_seconds`). Nothing existing changes. This was verified on a copy (v23 → v25: all 85 existing tables unchanged).

What it does:
- **Notification bell.**
  - *Needs action* shows live counts of work waiting for the signed-in person (leave and expense approvals, support tickets, onboarding steps, document signatures, HR documents, induction, ownership reviews, optional data updates). Each count is a link, and the badge is the sum of what is listed, with no hidden counters.
  - *Updates* is a personal feed: new tickets and replies, leave requests and decisions, announcements, careers applications, shared or answered HR documents, onboarding and client-request steps, signed documents. Each update can be marked read or unread, or all at once.
  - The bell refreshes every `notif_poll_seconds` (default 60) and shows a toast for new updates. A full page is at **/dashboard/notifications**.
  - An update only links inside the HRMS, and opening someone else's update is refused.
- **Website editor (Administration → Website, Super Admin).**
  - Every section of the home page can be edited: hero, ticker, numbers, product tour, lifecycle, features, pricing, roles, security, services, about and testimonials, FAQ, call to action, contact. So can the site-wide parts: company and contact details, SEO and sharing, announcement bar, header menu, footer.
  - Sections can be reordered (drag or arrows) and hidden.
  - Edits are saved as a **draft**. *Preview* shows the draft to the signed-in admin only, and *Publish* (with an optional note) makes it live. Every publish keeps a version that can be restored as a draft, and drafts can be discarded or reset to the original content.
  - The default content is the current home page, so nothing changes on the live site until someone publishes.
  - Images: PNG, JPG and WebP only (no SVG). `javascript:` links are refused. All text is escaped.
- **Insights (Administration → Insights, Super Admin).**
  - **CRM usage:** who is online now, active today, this week or this month, dormant for 30+ days, or has never signed in. Also adoption by role, daily active users, recent sign-ins and failed attempts, with filters and a CSV export.
  - **Website visitors:** visitors and page views per day, top pages, URLs, referrers, campaigns (UTM), devices and browsers, for any date range.
  - The Super Admin dashboard shows a *Platform pulse* summary of both. Other roles do not see it.
- **Privacy.**
  - No tracking cookies and no IP addresses are stored. A visitor is counted with a daily anonymous code (a hash of the date, a secret salt, the IP and the browser), so the same person cannot be followed from one day to the next.
  - Bots, Do-Not-Track / Global Privacy Control browsers, link previews and admin previews are not counted.
  - Page-view rows older than `visit_retention_days` (default 395) are deleted. This is the only data the release ever deletes, and only from its own new table. Set it to 0 to keep everything.
  - Tracking can be switched off with `site_tracking`.

After deploying:
1. **Administration → Website**: check the home page in *Preview*, adjust contact details, SEO and the announcement bar, then *Publish*.
2. **Settings → Website & analytics**: tracking on/off, retention days, bell refresh interval.
3. Optional cron: `php app/cron.php` also applies the visitor-data retention (otherwise it runs from page loads once a day).
4. If the site has a privacy policy, mention anonymous, cookie-free visitor statistics.

Polish in this release (no database changes):
- **Branded error page.** Unknown addresses now show a branded 404 page (`error.php`) with links to the home page, open jobs and sign-in (or the dashboard when signed in). Blocked files show a short "not available" page. Neither page names the server or PHP version any more, as Apache's default pages did. Missing images, scripts and styles still get a plain 404.
- **`.htaccess` changed** (it is flagged in `changes.txt`). Private folders and missing pages are now routed to `error.php`, there is an `ErrorDocument 403`, and `.webmanifest` gets its MIME type. If the hosting provider edited the live `.htaccess`, merge rather than overwrite.
- **Install on phone.** A web-app manifest lets employees add the portal to their home screen (opens the dashboard full-screen with the company icon).
- **Client dashboard.** With no open requests, it now offers HR / IT service tiles instead of an empty box. The stat row is sized to the cards the client can see, and the side cards spread across the row when there is no project list.
- **Sign-in and public pages.**
  - The page title follows the view (sign in / forgot password / new password).
  - Labels are linked to their inputs.
  - Search and sharing descriptions were added to sign-up, company registration, pricing and the legal pages.
  - Sign-in is marked `noindex`.
- **Accessibility.** Every form control in the portal now has an accessible name (filters, attendance grid inputs, file pickers).
- **Insights.** Fixed a doubled status dot and the filter-bar wrapping.

Deployment tooling in this release:
- `rehearse.php` now checks the upgrade on its own first; it must change nothing. It then runs the scheduled jobs and allows only their date-based status changes (an ownership lock, signing request, HR document request or Resdex subscription past its end date). Any other change still fails the rehearsal.
- The data fingerprint no longer includes anonymous page views, which expire by design.

No new environment variables.

Known limits:
- Insights counts page loads of the public pages (home, careers, job pages, applications, pricing). It is not a replacement for a full analytics product (no funnels or heatmaps).
- "Online now" means active in the last 5 minutes. Last-seen times are refreshed at most every 2 minutes.
- The bell refreshes by polling, not push.

Automated tests for this release (run against a copy of the database):
- `node v25-e2e.js`: browser checks covering bell accuracy, leave and announcement notifications, polling, read/unread, refusing to open someone else's update, website draft / preview / publish / hide / reorder, link sanitising, SVG refusal, image upload, versions / restore / discard / reset, visitor counting (bots, Do-Not-Track and previews skipped, no cookies, anonymous IDs), CRM usage, dashboard panel visibility, Insights filters and CSV, and mobile layouts.
- `node smoke25.js` and `node crawl.js`: every dashboard route for Super Admin, employee, client and vendor (235 pages) with no PHP warnings, server errors or JavaScript errors, and the bell endpoint answering for each role.
- Regression: the v20–v24 suites (`unit21`, `unit22`, `unit24`, `doc-e2e`, `openapp-e2e`, `apply-test`, `own-test`, `hrdocs-e2e`, `rdx-e2e`, `smoke`, `smoke22`, `smoke24`) all passed. `safety-check.php` reported OK, and `rehearse.php` passed on a v23 copy.

## Schema v24 — HR Document Centre, offer letters, signatures, employee credentials, induction

The upgrade adds 11 tables: HR document categories and types (seeded with the 50 standard lifecycle documents), templates and template versions, generated documents, the audit trail, signatories, induction items, assignees and acknowledgements, and a mail delivery log. It also adds nullable columns to `hrm_employees` (official e-mail and its status, candidate link), `it_login` (activation-link hash, expiry, password-set time) and `hrm_onboardings` (edited offer draft, library template, signatory, welcome e-mail status). Nothing existing changes. This was verified on a copy (v23 → v24: all 85 existing tables unchanged).

**Behaviour changes HR should know about**
- **Missing details are flagged in offer letters.** If a placeholder the letter uses has no verified value (for example no reporting manager), the preview highlights it and the offer cannot go for approval or be sent until HR fills in the detail or edits that sentence. Before this release such values were filled with fallback text ("your reporting manager", "our office", "—").
- **Credentials e-mail.** When an employee is activated, the welcome e-mail goes to the personal e-mail on the onboarding case. It contains the employee ID and sign-in link, plus either "use the password you created during onboarding" or a one-time password-setup link (default 72 h). It never contains a password.
  - If that e-mail address was never verified by the onboarding code, the e-mail is held until HR confirms the address on the case.
  - A failed e-mail can be resent; retrying never creates another account.
- **Employee IDs.** The existing format (prefix + 4 digits, Settings → `emp_code_prefix`) is unchanged.
  - The highest number ever issued is now remembered, so the ID of a deleted employee is never reused.
  - Parallel activations wait for each other, so two people can never get the same ID.
- **Existing account with the same e-mail.** If the hire already has an HRMS account under their personal e-mail (for example a careers profile), activation links that account instead of skipping login creation. If the account belongs to another employee, a client or a vendor, activation stops with a clear message and nothing is half-created.
- **CTC mandate.** Not changed and waiting for HR's decision. CTC is still required, as before.

After deploying:
1. **Database & Updates**: optional updates *HR Document Centre* and *Induction & policies* access for HR logins with custom permission lists (role defaults already include them).
2. **HR Document Centre → Document types**: review the seeded categories and types; add, rename or deactivate as needed.
3. **HR Document Centre → Signatures**: add authorised signatories with their signature images. These are images placed on documents, not electronic signatures.
4. **HR Document Centre → Templates**: upload Word templates using placeholders such as `{{candidate_name}}`, `{{designation}}`, `{{manager}}`, `{{ctc}}`, `{{joining_date}}`, `{{signature}}`. Templates of type *Offer Letter* appear in the onboarding offer form.
5. **HR Document Centre → Induction & policies**: add induction items (text, published HR policy, template, file or link). Set which are mandatory, the audience (everyone, a department, selected employees or a joining cohort) and the due days.
6. **Settings → Onboarding**:
   - Whether HR documents need approval before sharing.
   - Password-setup link validity.
   - How many days after joining new employees land on *Induction & Documents*.
   - The support contact shown in the welcome e-mail.
7. Optional cron: `php app/cron.php` also expires overdue shared documents.

No new environment variables. Mail uses the existing mail configuration. "Sent" in the delivery log means the mail server accepted the message; PHP `mail()` cannot confirm inbox delivery.

Known limits:
- No malware scanning of uploads. Files are type- and content-checked, stored privately and streamed only after permission checks.
- No third-party e-signature integration for HR documents. Recipients acknowledge or accept with their signed-in account; candidates' offer e-signature (OTP + drawn) is unchanged.
- `.doc` and PDF templates are not fully editable: `.doc` is a text preview, PDF is read-only.
- Official e-mail mailboxes are not created automatically. HR or IT records them, and only "Provisioned" addresses are shown.

Automated tests for this release (run against a copy of the database):
- `node hrdocs-e2e.js`: 86 browser checks covering templates, persistence, permissions, generation, missing data, editing, sharing, acknowledgement, replace and delete safety, the offer workflow with approval and locking, the CTC rule, activation, employee IDs, the credentials e-mail, the one-time link, the official e-mail, induction with versions and reminders, the HR dashboard, and mobile layouts.
- `php unit24.php`: 21 checks covering placeholders, signature preservation, parallel activations, the double-click case, account linking and its refusal, activation-token expiry, induction versioning and audit hygiene.

## Schema v23 — open applications — "Apply for any position"

The upgrade adds ten nullable columns to `hrm_candidates` (application type, reference, preferred role / department, employment type, experience level, portfolio URL, cover letter, additional information and a submission key), two indexes and a unique index on the submission key. Older records keep NULL in every new column, so nothing existing changes. This was verified on a copy (v22 → v23: all 85 existing tables unchanged).

What it does:
- **/find-jobs**: a new "Open applications" section at the bottom of the listing. *Apply for Any Position* opens the same slide-over wizard Quick Apply uses, at `/find-jobs?apply=open`, which can be shared and closes with Back.
- **/find-jobs/apply**: the same application as a normal page. It works without JavaScript and is listed in the sitemap. This needs the new rewrite rule in `.htaccess` (`^find-jobs/apply/?$ → find-jobs.php?open=1`), which ships in the release. Hosts that do not use the root `.htaccess` must add it.
- Fields:
  - **Required:** name, e-mail, mobile, location, preferred role, employment type (Full-time / Part-time / Contract / Internship), experience level, years of experience, PDF resume, privacy consent.
  - **Optional:** department, current company, salary, notice period, LinkedIn, portfolio, cover letter, key skills, additional information.
- **Checks:**
  - Everything is validated in the browser and again on the server.
  - The resume must be a genuine PDF (type and content checked) and is stored privately in `uploads/resumes`.
  - The existing spam protections apply (hidden field, minimum form time, hourly limits per network and e-mail).
- **No duplicates:** each form carries a one-time submission key, so a double click, a retried upload or a back-and-resubmit returns the same application and reference. The same person (same e-mail or mobile) sending another open application within 30 days is told it already exists, and nothing new is saved.
- **Records:**
  - Saved as a candidate with no job, marked *Open application*, source "Company website", stage Applied, consent recorded.
  - The talent-pool profile is created or completed, exactly as for Quick Apply.
  - Quick Apply on a job now also stores its job link, the application type and the same `APP-000123` reference shown on its success screen.
- **E-mail:** the applicant gets a confirmation with the reference. The HR e-mail (Settings → Company → HR e-mail) gets a short notice with a sign-in link. Contact details and the resume are not included in that notice.
- **HR:** Candidates has an *All applications / Job application / Open application* filter. The candidate record shows an Open application card (role, department, type, level, links, cover letter, additional information). Access stays limited to users with recruitment permission.

No new environment variables. E-mail uses the existing mail settings.

Automated tests for this release (run against a copy of the database): `node openapp-e2e.js` (43 browser checks: section content, every field, step validation, server-side validation with browser checks bypassed, PDF type and content checks, concurrent double submit, repeat applicant, Quick Apply with job ID, HR filter and record, file protection, no-JS post, 390 / 768 / 1440 px layouts, dialog accessibility). The existing suites still pass: `apply-test.js` 33/33, `doc-e2e.js` 61/61, `unit22.php` 44/44, `unit21.php` 50/50, `own-test.js` and `rdx-e2e.js`.

## Schema v22 — agreement & document management, client offer letters

The upgrade adds two tables (`hrm_document_events` audit trail, `hrm_document_access` grants), new nullable / defaulted columns on `hrm_client_documents` and `hrm_clients`, three settings and three indexes. One documented exception to "add only": `hrm_client_documents.client_id` becomes nullable so agreements can belong to a vendor. That changes the column definition only, not any stored value. The new `is_current` column is filled once for rows that already have a newer version, because that column did not exist before. Existing proposal-pack documents, signatures and signed copies stay exactly as they were.

After deploying:
1. **Database & Updates**: optional update *Show offer letters already sent to candidates in their client's Document Centre*. It records the current approved text of offers that were already sent to candidates of client jobs. Onboarding records are not changed.
2. **Clients → edit**: assign a *Business development owner*. BD users see only the documents of their assigned clients and engagements. Set *Offer letters in client portal* when a client has the HR + recruitment package without an active recruitment engagement. Leave it on "Automatic" otherwise.
3. **Users & Roles**: give Business Development logins the *Business development: documents* permission. Vendor logins get *Documents & agreements* by default (role default; custom permission lists need it added).
4. **Settings → Agreements & documents**: signing expiry (days), largest Word upload and whether internal approval is required before sending.

What it does:
- **Agreements & Documents** (staff): upload .docx / .doc (checked on the server as a real Word package, macros and zip bombs refused), start from a template or blank, edit in the browser, preview, internal review / approval, company signature, send to a verified signatory with an expiry, countersignature, cancel, revoke access, upload revised versions, convert .doc to .docx, and download as .docx or the original. Every action is in the audit trail. 12 document types × 4 service categories.
- **Versioning**: editing anything a recipient has already seen creates a new version and invalidates the open signing request. The signing OTP is bound to the SHA-256 of that exact text. Signed and partially signed versions are never changed.
- **Client Document Centre**: table with Title, Type, Category, Engagement, Version, Date shared, Status, Signature status and Actions. Filters by type, category, status and date range. Clients can view, sign (signatories only, by e-mail OTP and a drawn signature), download when allowed, request changes, or decline. The previous grouped view is kept as the *By engagement* tab.
- **Offer letters** tab (entitled clients): a read-only copy of each offer as it was sent, with the columns Candidate Name, Designation, Work Location, Joined Date and View Offer Letter. Counters cover Total shared, Accepted, Pending, Joined and Not joined, and each offer is counted once. Drafts are never shared. "Pending" / "Not Joined" are shown instead of a date the system does not have.
- **Vendor portal → Documents**: agreements shared with the agency (view, sign, respond) and the agency's own Word uploads.
- Signature methods are recorded and shown as they are: e-mail OTP plus a drawn mark, an internal drawn signature, or execution outside the portal (DSC, Aadhaar eSign, wet ink or notarised, with the executed PDF uploaded). The portal does not claim legal validity.

Known limits: uploads are not virus-scanned (use host-level scanning if required), and there is no direct eSign / DSC provider integration (the executed copy is uploaded instead). The browser editor covers headings, emphasis, lists and tables, so complex Word layouts (headers, footers, tracked changes, images) are kept only in the original file. Legacy .doc files are shown as a text preview until they are converted.

Automated tests for this release (run against a copy of the database): `php unit22.php` (44 backend checks) and `node doc-e2e.js` (61 browser checks across Super Admin, client-services Admin, two BD users, client signatory / non-signatory / another client, and two vendors, including IDOR, version invalidation and offer counters).

## Schema v21 — central candidate database, Resdex credits & subscriptions

The upgrade only adds tables, columns, indexes and new settings. On the first page loads after deploy, a background job fills the new identity / search-index columns of existing candidates (a few hundred records per minute; `php app/cron.php` does all of them at once). No existing value is changed.

After deploying:
1. **Database & Updates**: review and apply the optional updates:
   - *Link repeat applications of the same person* (recommended): links later records whose e-mail and phone agree with an earlier one, so each person appears once in Resdex. Conflicts stay in the duplicate review queue.
   - *Add careers-portal profiles without an application*: makes those profiles searchable in Resdex.
   - *Candidate database* permission for custom HR logins.
2. **Candidates → Candidate Data Quality**: resolve the potential duplicates found in existing data (each decision needs a reason and is audited).
3. **Settings → Resdex credits & candidate database**: confirm credit rates, GST % and SAC, consent mode and the default phone country. Turn off *Charge credits* if existing clients should keep posting jobs / using Resdex without a plan until plans go live.
4. **Resdex & Credits → Plans / Offers**: confirm prices and the launch offer dates (the launch offer runs 30 days from the upgrade).
5. Online plan payments use the Razorpay keys under Settings → Pricing & payments (webhook URL unchanged: `/razorpay-webhook`). Without keys, orders wait for payment and are confirmed under Resdex & Credits → Subscriptions & orders.

Automated tests for this release (run against a copy of the database): `php unit21.php` (50 backend checks: normalisation, duplicate rules, concurrency, credit engine, subscriptions, promotions) and `node rdx-e2e.js` (39 browser checks across Super Admin, client admin and a second organisation).
