<?php
/**
 * AI assistant ("Ask Adora").
 *
 *  - A built-in feature guide (assistant_guide) + the published knowledge base are the source of truth
 *    for "how do I…" questions. Both are filtered by what the signed-in user may use.
 *  - Read-only data tools (assistant_tools) answer "what is my…" questions, always scoped to the
 *    signed-in user's own permissions and records.
 *  - With an Anthropic API key (Settings → AI Assistant) Claude answers using the guide + tools.
 *    Without one, or if the API is unreachable, the offline engine answers from the same sources.
 */

/**
 * The feature guide. Each entry: title, perms (user needs ANY of them; [] = everyone), keywords,
 * body (steps, light markdown), link [route, params, label].
 */
function assistant_guide(): array
{
    return [
        // ── Everyone ──
        ['title' => 'Change your password', 'perms' => [], 'kw' => 'password change reset update security',
            'body' => "1. Click your name (top right) → **Change password**.\n2. Enter your current password, then the new one twice (at least 8 characters).\n3. Click **Update password**.\n\nForgot it? On the sign-in page click **Forgot password?**; we e-mail you a 6-digit code valid for 10 minutes.",
            'link' => ['profile', ['a' => 'password'], 'Change password']],
        ['title' => 'Quick navigation and search', 'perms' => [], 'kw' => 'search find navigate shortcut ctrl k menu where page',
            'body' => "- Press **Ctrl + K** anywhere to jump to any page by typing its name.\n- Press **/** to focus the search box and search people, candidates, projects and more.\n- The sidebar only shows the modules you have access to.",
            'link' => ['home', [], 'Dashboard']],
        ['title' => 'Raise a support ticket', 'perms' => ['raise_tickets'], 'kw' => 'support ticket help issue problem bug complaint raise contact helpdesk',
            'body' => "1. Open **Help & Support → My Tickets** and click **New ticket**.\n2. Pick a category and priority, describe the problem and attach a screenshot if useful.\n3. You get a ticket number and an e-mail on every reply. The support team works to an SLA based on priority (Critical 4h, High 8h, Medium 24h, Low 72h).\n4. When it's resolved, rate the help you received.",
            'link' => ['support', ['a' => 'create'], 'New ticket']],
        ['title' => 'Help Center and knowledge base', 'perms' => ['raise_tickets', 'use_assistant'], 'kw' => 'help center knowledge base article guide faq documentation how to',
            'body' => 'The **Help Center** lists step-by-step guides for every module you can use, plus articles written by the support team. Search it before raising a ticket.',
            'link' => ['help', [], 'Help Center']],

        // ── Onboarding ──
        ['title' => 'Onboard a selected candidate (offer to joining)', 'perms' => ['manage_onboarding'], 'kw' => 'onboarding offer letter generate send joining new hire employee id activate induction verify documents compliance',
            'body' => "1. Move the candidate to **Selected** in the hiring pipeline, then open **Recruitment → Onboarding → Start onboarding** and pick them.\n2. Enter designation, department, manager, CTC (the salary break-up is calculated), joining date and \"accept by\" date; choose the offer template.\n3. **Preview letter**, then **Send for approval** (if approvals are on) and, once approved, **Send offer**. The candidate gets a secure link, verifies their e-mail with a one-time code and accepts with an e-signature.\n4. They fill in personal, education, bank and PF details, upload documents and e-sign the agreement, NDA and policies, then submit.\n5. On the case page: verify or reject each document, tick the compliance checklist and click **Approve onboarding**. The employee ID, employee profile, salary structure, probation and login are created automatically.\n6. On the joining day, open **Joining & induction**, plan the induction and click **Confirm joining** to assign induction tasks and training.",
            'link' => ['onboarding', [], 'Onboarding']],
        ['title' => 'Approve an offer letter', 'perms' => ['approve_offers'], 'kw' => 'approve offer letter approval pending ctc request changes',
            'body' => "Offers waiting for you appear in the bell and under **Recruitment → Onboarding → Approval**. Open the case, check the offer and salary break-up (**Preview letter**), then click **Approve offer** or **Request changes** with a comment. You can't approve an offer you prepared yourself.",
            'link' => ['onboarding', ['status' => 'Pending Approval'], 'Offers to approve']],
        ['title' => 'Complete your onboarding (new joiners)', 'perms' => ['candidate_applications'], 'kw' => 'onboarding offer accept sign documents upload joining form bank pf uan aadhaar pan',
            'body' => "1. Open **My Onboarding** (or the link in your offer e-mail) and read your offer letter.\n2. Accept it by typing your full name and signing in the box.\n3. Fill in each section: personal information, education & employment, bank & statutory (PAN, Aadhaar, UAN, nominee).\n4. Upload the listed documents (PDF, JPG or PNG) and e-sign the agreement, NDA and policies.\n5. Tick the declarations and **Submit to HR**. If HR asks for corrections you get an e-mail; fix the items and submit again.\n\nAfter approval your account becomes an employee account: sign in with the same e-mail and password.",
            'link' => ['onboarding', ['a' => 'me'], 'My Onboarding']],

        // ── Client onboarding ──
        ['title' => 'Request HR or IT services (clients)', 'perms' => ['client_services'], 'kw' => 'service request requirement vacancy project enquiry proposal agreement nda sop sign approve activate',
            'body' => "1. Open **My Services → New request** and choose **HR services** (recruitment requirement) or **IT services** (service enquiry). The form only shows the fields that apply.\n2. Submit it. Our team may ask for more information, then sends a **proposal** with the agreements (HR: NDA + recruitment agreement; IT: NDA + work agreement + SOP).\n3. Your **authorised signatory** signs each document: we e-mail a one-time code at the moment of signing.\n4. Pay the advance invoice if the proposal has one. After our internal authorisation the vacancy or project goes live.\n5. Follow progress, reports, meetings, deliverables and invoices on the request page; every document and its signature certificate is in the **Document Centre**.",
            'link' => ['services', [], 'My Services']],
        ['title' => 'Handle a client request (staff)', 'perms' => ['manage_engagements', 'approve_engagements'], 'kw' => 'client request engagement proposal commercials sop agreement authorise activate vacancy project waive advance',
            'body' => "1. **Work → Client Requests** lists new HR / IT requests. Assign an owner, or **Request information** if something is missing.\n2. On **Proposal & documents** set the fee model, fee, advance and validity (IT: write the SOP), save, then **Send proposal & documents**. Signed versions are kept; changed documents become new versions.\n3. The advance invoice is issued automatically when the client accepts the proposal; record the payment on the invoice (or an approver waives it).\n4. When everything is signed and paid, someone with **Authorise client activation** clicks **Authorise & activate**: the vacancy or project is created and the agreements are countersigned.\n5. Post status reports and meetings in **Discussion**, share deliverables, then **Mark delivered** and **Close**.",
            'link' => ['engagements', [], 'Client Requests']],

        // ── Employee self-service ──
        ['title' => 'Punch in and punch out', 'perms' => ['self_attendance'], 'kw' => 'punch in out attendance check clock start day end day mark present login time',
            'body' => "1. On the **Dashboard** (or **My Attendance**) click **Punch In** when you start work.\n2. Click **Punch Out** when you finish. You can punch in and out several times a day; every session is added up.\n3. Each punch records the time, device, IP and (if you allow it) location.\n4. Arriving after the office start time plus the grace period marks you **Late**.",
            'link' => ['attendance', ['a' => 'my'], 'My Attendance']],
        ['title' => 'See your attendance history', 'perms' => ['self_attendance'], 'kw' => 'attendance history month late absent present days worked hours report my',
            'body' => '**My Attendance** shows a monthly calendar with each day\'s status (Present, Late, Half Day, On Leave, Holiday, Absent), first punch-in, last punch-out and hours worked. Use the month arrows to go back.',
            'link' => ['attendance', ['a' => 'my'], 'My Attendance']],
        ['title' => 'Apply for leave', 'perms' => ['self_leaves'], 'kw' => 'leave apply holiday off vacation sick casual earned request day off half day',
            'body' => "1. Go to **My Leaves** → **Apply leave**.\n2. Choose the leave type, start and end dates (tick **Half day** if needed) and a reason.\n3. Weekends and company holidays are not counted.\n4. Your manager approves or rejects it; you'll see the status in My Leaves. You can cancel a pending request.",
            'link' => ['leaves', ['a' => 'apply'], 'Apply leave']],
        ['title' => 'Check your leave balance', 'perms' => ['self_leaves'], 'kw' => 'leave balance remaining quota how many leaves left available',
            'body' => '**My Leaves** shows each leave type with the yearly quota, days used, days pending approval and days remaining.',
            'link' => ['leaves', ['a' => 'my'], 'My Leaves']],
        ['title' => 'Download your payslip', 'perms' => ['self_payslips'], 'kw' => 'payslip salary slip pay download print net pay ctc month',
            'body' => 'Open **My Payslips**, pick a month and click **View** → **Print / Save as PDF**. Payslips appear once HR finalises that month\'s payroll.',
            'link' => ['payroll', ['a' => 'my'], 'My Payslips']],
        ['title' => 'Claim an expense', 'perms' => ['self_expenses'], 'kw' => 'expense claim reimbursement bill receipt travel food',
            'body' => "1. Go to **My Expenses** → **Add expense**.\n2. Enter category, amount, date and description and attach the receipt (PDF/JPG/PNG).\n3. Your manager approves it and HR marks it **Reimbursed** when paid.",
            'link' => ['expenses', ['a' => 'my'], 'My Expenses']],
        ['title' => 'Your tasks', 'perms' => ['self_tasks'], 'kw' => 'task todo assigned work status done progress',
            'body' => '**My Tasks** lists everything assigned to you across projects. Change a task\'s status to **In Progress** or **Done** as you work.',
            'link' => ['tasks', ['a' => 'my'], 'My Tasks']],
        ['title' => 'Update your profile', 'perms' => ['self_attendance', 'self_leaves', 'view_directory'], 'kw' => 'profile photo phone address bank account emergency contact update edit details',
            'body' => 'Open **My profile** (click your name). You can update your photo, phone, address, emergency contact and bank details. Name, job and statutory details are managed by HR.',
            'link' => ['profile', [], 'My profile']],
        ['title' => 'Company directory', 'perms' => ['view_directory'], 'kw' => 'directory colleague contact phone email find person team',
            'body' => '**Directory** lists colleagues with department, designation and contact details.', 'link' => ['directory', [], 'Directory']],
        ['title' => 'Holiday calendar', 'perms' => ['view_holidays'], 'kw' => 'holiday calendar public festival off list upcoming',
            'body' => '**Holidays** shows this year\'s public and optional holidays. Holidays are skipped automatically when leave days are counted.',
            'link' => ['holidays', [], 'Holidays']],
        ['title' => 'Announcements', 'perms' => ['view_announcements'], 'kw' => 'announcement news notice circular update',
            'body' => 'Company announcements appear under **Announcements** and in the bell menu.', 'link' => ['announcements', [], 'Announcements']],
        ['title' => 'Projects', 'perms' => ['view_projects', 'manage_projects'], 'kw' => 'project team member deadline progress status',
            'body' => '**Projects** shows the projects you belong to with team, deadline, progress and tasks. Managers with project access can create projects, add members and assign tasks.',
            'link' => ['projects', [], 'Projects']],
        ['title' => 'Training programmes', 'perms' => ['view_training', 'manage_training'], 'kw' => 'training course learning programme enrol session',
            'body' => '**Training** lists scheduled programmes. HR enrols participants and tracks completion.', 'link' => ['trainings', [], 'Training']],

        // ── Managers / HR ──
        ['title' => 'Approve or reject leave', 'perms' => ['approve_leaves'], 'kw' => 'approve reject leave request pending team manager',
            'body' => 'Open **Leave Requests** (the badge shows how many are pending), review the dates, reason and the person\'s balance, then click **Approve** or **Reject** (you can add a note). Managers see their direct reports; HR sees everyone.',
            'link' => ['leaves', ['status' => 'Pending'], 'Leave Requests']],
        ['title' => 'Approve expense claims', 'perms' => ['approve_expenses'], 'kw' => 'approve expense claim reimburse pending',
            'body' => 'Open **Expense Claims**, check the receipt and click **Approve** or **Reject**. After payment mark it **Reimbursed**.',
            'link' => ['expenses', ['status' => 'Pending'], 'Expense Claims']],
        ['title' => 'Add a new employee', 'perms' => ['manage_employees'], 'kw' => 'add employee new joiner onboard hire create staff record login',
            'body' => "1. **Employees** → **Add employee**.\n2. Fill personal, job (department, designation, manager, join date) and statutory/bank details.\n3. Tick **Create portal login** to e-mail them sign-in details.\n4. Upload documents on the employee's profile.",
            'link' => ['employees', ['a' => 'create'], 'Add employee']],
        ['title' => 'Departments, designations and leave types', 'perms' => ['manage_org'], 'kw' => 'department designation leave type quota organisation setup holiday announcement',
            'body' => 'Organisation setup lives in **Departments**, **Designations**, **Leave Types** (yearly quota, paid/unpaid), **Holidays** and **Announcements**.',
            'link' => ['departments', [], 'Departments']],
        ['title' => 'Attendance register and punch log', 'perms' => ['manage_attendance'], 'kw' => 'attendance register daily monthly sheet punch log correct mark absent export',
            'body' => '**Attendance** shows today\'s register (who is in, late, on leave). The **monthly sheet** gives everyone\'s month at a glance and exports to CSV. **Punch Log** lists every raw punch with device, IP and location; HR can add corrections.',
            'link' => ['attendance', [], 'Attendance']],
        ['title' => 'Run monthly payroll', 'perms' => ['manage_payroll'], 'kw' => 'payroll run salary process month finalize payslip generate pf tds lop',
            'body' => "1. Make sure every employee has a **Salary Structure**.\n2. **Payroll Runs** → pick the month → **Generate**. Loss-of-pay days come from attendance and unpaid leave; PF, PT and TDS are applied.\n3. Review the draft, then **Finalize**. Employees can then see their payslips.",
            'link' => ['payroll', [], 'Payroll Runs']],
        ['title' => 'Post a job opening', 'perms' => ['manage_recruitment'], 'kw' => 'job opening post vacancy position hire recruit careers visibility share vendors',
            'body' => "1. **Job Openings** → **Add job**. Add title, department, location, experience, salary range, key skills and the description.\n2. Set **Visibility**: *Careers page + sub-vendors*, *Sub-vendors only* or *Internal only*.\n3. Optionally share it with selected vendors only. Leave all unticked to share with every active vendor.\n4. Set a client if you're hiring for one; they see shortlisted candidates in their portal.",
            'link' => ['jobs', ['a' => 'create'], 'Add job']],
        ['title' => 'Manage candidates and the hiring pipeline', 'perms' => ['manage_recruitment'], 'kw' => 'candidate pipeline stage interview shortlist kanban board resume import excel convert employee',
            'body' => "- **Candidates** lists everyone who applied: from the careers page, submitted by sub-vendors, imported from Excel/CSV or added by HR.\n- **Hiring Pipeline** is a drag-and-drop board: Applied → Shortlisted → HR Screening → Interview → Client Interview → Selected → Offered → Hired (joined).\n- Open a candidate to see their resume, source (vendor / career portal) and client feedback.\n- At **Offered/Hired**, click **Convert to employee**.",
            'link' => ['candidates', ['a' => 'board'], 'Hiring Pipeline']],
        ['title' => 'Search the talent pool', 'perms' => ['manage_recruitment'], 'kw' => 'talent pool database profile search skills future candidates registered job seekers',
            'body' => '**Talent Pool** holds every candidate who created a profile on the careers portal, including people who haven\'t applied yet. Search by skills, experience or location and click **Add to job** to put them into a pipeline.',
            'link' => ['talent_pool', [], 'Talent Pool']],
        ['title' => 'Onboard a sub-vendor (staffing partner)', 'perms' => ['manage_vendors'], 'kw' => 'vendor sub-vendor agency partner consultant staffing onboard add login commission',
            'body' => "1. **Sub-Vendors** → **Add vendor**: company, contact, GST/PAN, fee terms and agreement dates.\n2. On the vendor's page click **Create portal login**.\n3. Share jobs (visibility *Careers + sub-vendors* or *Sub-vendors only*).\n4. The vendor scorecard shows submissions, shortlist rate, interviews and hires.",
            'link' => ['vendors', [], 'Sub-Vendors']],
        ['title' => 'Vendor Performance Score (industry-wise)', 'perms' => ['manage_vendors', 'manage_recruitment', 'manage_vendor_scores'], 'kw' => 'vendor score cibil rating performance industry excellent good better rank compare best vendor',
            'body' => "Each partner has a 0–100 **Vendor Performance Score per industry** (an internal rating, not a credit score).\n1. **Sub-Vendors** → open a vendor → *Vendor Performance Score* card: pick the industry to see its score, category and colour (85–100 green, 65–84 yellow, 0–64 red).\n2. People with *Vendor performance scores* access enter or update the score (with a note); every change is kept in the score history. Missing industry? Choose **+ Add a new industry** or manage them under **Industries**.\n3. In the vendor list, filter by **Industry** and **Score category**, and sort by the score column to compare partners.\n4. Set a job's **Hiring industry**: the job page then ranks vendors by that industry's score, with one-click **Share**.",
            'link' => ['vendors', [], 'Sub-Vendors']],
        ['title' => 'Candidate ownership lock & timer', 'perms' => ['manage_vendors', 'manage_candidate_locks'], 'kw' => 'ownership lock timer duplicate candidate vendor claim protected release extend expiry company',
            'body' => "When a partner submits a candidate, the candidate is **protected for that client company only** (matched on e-mail and mobile) for the lock period set in **Settings → Ownership locks** (default 30 days).\n- Another partner submitting the same person to the **same company** is blocked and can't open the protected profile; other companies are not affected.\n- A partner's linked sub-vendors share its ownership.\n- If the e-mail matches but the mobile differs (or the reverse), the submission waits under **Candidate Ownership → Reviews**: decide *same person* or *different person*.\n- At expiry the lock is released automatically, **held** while the client is still interviewing, and **kept** when hired. Owners get a reminder before expiry.\n- **Candidate Ownership** shows every lock with its timer; extend (with a reason) or release from a lock or the candidate's page. Everything is in the **Audit log**.",
            'link' => ['ownership', [], 'Candidate Ownership']],
        ['title' => 'Clients, invoices and payments', 'perms' => ['manage_accounts'], 'kw' => 'client invoice payment billing gst due overdue receive record',
            'body' => '**Clients** holds customer companies (and their portal logins). **Invoices** tracks billed, paid and due amounts with GST; record receipts under **Payments**. Invoice status updates automatically.',
            'link' => ['invoices', [], 'Invoices']],
        ['title' => 'Reports and exports', 'perms' => ['view_reports'], 'kw' => 'report analytics export csv excel headcount attrition attendance payroll hiring',
            'body' => '**Reports** has headcount, attendance, leave, payroll and hiring reports with charts. Every list in the portal also has an **Export** button.',
            'link' => ['reports', [], 'Reports']],
        ['title' => 'Handle the support queue', 'perms' => ['manage_support'], 'kw' => 'support desk queue ticket assign agent sla reply internal note resolve close',
            'body' => "- **Support Desk** shows the queue: Unassigned, Mine, Overdue, Waiting on customer and Resolved.\n- Open a ticket to reply, add **internal notes** (never shown to the requester), change priority/status or assign it.\n- The SLA clock pauses while a ticket is *Waiting on Customer*.\n- Write reusable answers in the **Knowledge Base**; they also power the AI assistant.",
            'link' => ['support', [], 'Support Desk']],
        ['title' => 'Users, roles and module access', 'perms' => ['manage_users'], 'kw' => 'user role access permission login create disable module grant super admin',
            'body' => "**Users & Roles** (Super Admin only) creates logins for staff, clients, sub-vendors and candidates. Each role has default modules. Switch to **Custom access** to tick exactly which modules a person can use. External roles can only ever receive their own portal's modules.",
            'link' => ['users', [], 'Users & Roles']],
        ['title' => 'Configure the AI assistant', 'perms' => ['manage_settings'], 'kw' => 'ai assistant api key claude anthropic configure enable model settings',
            'body' => 'Under **Administration → AI Assistant** add your Anthropic API key, turn the assistant on or off, choose the model and see usage, ratings and unanswered questions.',
            'link' => ['assistant', ['a' => 'admin'], 'AI Assistant settings']],

        // ── Client portal ──
        ['title' => 'Track your projects (client)', 'perms' => ['client_projects'], 'kw' => 'project progress status deadline team client our',
            'body' => '**Our Projects** shows each project\'s progress, deadline, open tasks and the team working on it.', 'link' => ['client', ['a' => 'projects'], 'Our Projects']],
        ['title' => 'Invoices and payments (client)', 'perms' => ['client_invoices'], 'kw' => 'invoice bill payment due paid balance download print client',
            'body' => '**Invoices & Payments** lists every invoice with amount paid and due. Open one to print or save it as PDF.', 'link' => ['client', ['a' => 'invoices'], 'Invoices & Payments']],
        ['title' => 'Review shortlisted candidates (client)', 'perms' => ['client_hiring'], 'kw' => 'candidate shortlist feedback interested hiring client resume',
            'body' => '**Hiring for Us** shows openings for your company and candidates we have shortlisted. Open a candidate, view the resume and mark **Interested**, **On hold** or **Not interested** with a comment.',
            'link' => ['client', ['a' => 'hiring'], 'Hiring for Us']],

        ['title' => 'Offboard an employee (mark inactive)', 'perms' => ['manage_employees'], 'kw' => 'offboard exit resign terminate inactive deactivate leaving relieve last working day former employee rehire',
            'body' => "1. Open the employee's profile and click **Offboard**.\n2. Choose the exit type (Resigned, Terminated, Retired, Absconded, Contract Ended, Inactive), the last working day and whether they are eligible for rehire.\n3. Confirm the checklist: disable the portal login, move direct reports to a new manager, cancel pending leave and unassign open tasks.\n\nFormer employees are listed under **People → Inactive Employees**, with a **Reactivate** button to rehire.",
            'link' => ['employees', ['a' => 'inactive'], 'Inactive Employees']],
        ['title' => 'Probation period rules', 'perms' => ['self_performance', 'manage_performance', 'manage_employees'], 'kw' => 'probation period confirmation confirm junior senior months how long end date extend extension',
            'body' => "Probation depends on the profile: **junior profiles " . setting('probation_months_junior', 6) . " months**, **senior profiles " . setting('probation_months_senior', 3) . " months** from the joining date.\n\n• The track comes from the designation level (Senior, Lead, Manager, Director, Executive = senior; Intern, Junior, Mid = junior). HR can override it on the employee record.\n• Before the end date the manager completes a **probation review**, and HR decides: **Confirm** (status becomes Active and a confirmation letter is issued), **Extend** (1–6 months) or **Do not confirm**.\n• Employees see their countdown under **Performance → My Performance**.",
            'link' => ['performance', ['a' => 'my'], 'My Performance']],
        ['title' => 'Complete a probation review', 'perms' => ['manage_performance'], 'kw' => 'probation review confirm extend not confirmed decision letter due overdue',
            'body' => "1. Open **Performance → Probation** (the bell and dashboard flag reviews due soon).\n2. Click **Start review** next to the employee — you see attendance, leave, tasks and kudos for the probation period.\n3. Rate the 7 competencies, add strengths / areas to improve and recommend **Confirm**, **Extend** or **Do not confirm**.\n4. HR applies the decision: confirmation sets the status to Active and creates a printable confirmation letter; not confirmed opens the offboarding form.",
            'link' => ['performance', ['a' => 'probation'], 'Probation']],
        ['title' => 'Performance reviews and goals', 'perms' => ['self_performance', 'manage_performance'], 'kw' => 'performance review appraisal rating goals kpi self review manager review cycle annual increment promotion acknowledge',
            'body' => "• **Goals & KPIs**: add measurable goals with a target, weight and due date, and update progress as you go.\n• **Review cycles** (HR): create a cycle, then **Launch** — everyone gets a self-review.\n• **Self-review** → **Manager review** (goal + competency ratings, recommendation, increment) → employee **acknowledges**.\n• The overall rating blends weighted goal ratings with the competency average (default 60 / 40). HR can calibrate the final rating.",
            'link' => ['performance', ['a' => 'my'], 'My Performance']],
        ['title' => 'Plans, pricing and the payment gateway', 'perms' => ['manage_settings'], 'kw' => 'plan pricing price billing subscription razorpay payment gateway invoice gst upgrade seats license light standard pro renewal',
            'body' => "• **Plans**: Light (core HR, attendance, leave), Standard (+ payroll, performance, probation, projects, helpdesk) and Pro (+ hiring, partner portals, HireFlow, AI).\n• **Prices** per user per month, annual discount, minimum users and GST are in **Settings → Pricing & payments**.\n• **Razorpay**: add the key ID, key secret and webhook secret there, then add the webhook URL shown on **Plan & Billing** in the Razorpay dashboard (events payment.captured, order.paid, payment.failed).\n• **Plan & Billing** lists every website order with status, GST invoice, renewals due, payment links and offline payments.",
            'link' => ['billing', [], 'Plan & Billing']],
        ['title' => 'Give kudos to a colleague', 'perms' => ['self_performance'], 'kw' => 'kudos appreciate thank recognition praise award badge',
            'body' => 'Open **Performance → Kudos Wall**, choose a colleague and a badge (Great work, Team player, Customer hero…), write what they did and click **Send kudos**. They get an e-mail and it counts in their review snapshot.',
            'link' => ['performance', ['a' => 'kudos'], 'Kudos Wall']],
        ['title' => 'HireFlow: post and publish a job', 'perms' => ['client_post_jobs'], 'kw' => 'hireflow post job publish requirement vacancy draft approval client',
            'body' => "1. **HireFlow → Post a Job**: title, location, experience, salary, skills and the description.\n2. **Save draft** or **Publish**. If approval is required, the job goes live once it is approved.\n3. Manage it under **Manage Jobs**: see responses per stage, pause, close or reopen.",
            'link' => ['hireflow', ['a' => 'post'], 'Post a Job']],
        ['title' => 'HireFlow: screen, shortlist and hire', 'perms' => ['client_pipeline'], 'kw' => 'hireflow applications screen shortlist interview client interview selection offer joining reject candidate pipeline',
            'body' => "1. **Applications** shows new applicants. Click **Shortlist** or **Reject**, or open **Screen** for the resume.\n2. On the candidate page use **Move in pipeline**: Shortlisted → HR Screening → Interview → Client Interview → Selected → Offered → Joined. Add interview times, offer CTC and joining date as you go.\n3. **Shortlist** lists everyone in progress; the dashboard shows upcoming interviews and joinings.",
            'link' => ['hireflow', ['a' => 'applications'], 'Applications']],
        ['title' => 'Resdex: search the talent pool', 'perms' => ['client_resdex'], 'kw' => 'resdex search talent pool database candidates find matching resume save contact',
            'body' => "1. **Resdex Search**: enter skills (comma = must have all), location, experience, notice period or education.\n2. Open a profile to read the resume, **Save** it, or **Shortlist for a job**. Contact details unlock once the candidate is in your pipeline.\n3. **Contact** sends an e-mail through the portal; replies come straight to you.",
            'link' => ['hireflow', ['a' => 'resdex'], 'Resdex Search']],

        // ── Sub-vendor portal ──
        ['title' => 'See jobs shared with your agency', 'perms' => ['vendor_jobs'], 'kw' => 'jobs requirements openings shared vendor positions jd description',
            'body' => '**Open Jobs** lists every requirement shared with your agency, with the full job description, experience, budget and number of openings.',
            'link' => ['vendor', ['a' => 'jobs'], 'Open Jobs']],
        ['title' => 'Submit a candidate', 'perms' => ['vendor_submissions'], 'kw' => 'submit candidate profile resume upload vendor add cv',
            'body' => "1. Open a job in **Open Jobs** and click **Submit candidate**.\n2. Fill the candidate's details and upload the resume (PDF/Word).\n3. **Duplicate rule:** a profile already in the pipeline for that job, or submitted by another source within the ownership window, is rejected, so submit only candidates you have spoken to.",
            'link' => ['vendor', ['a' => 'jobs'], 'Open Jobs']],
        ['title' => 'Track your submissions', 'perms' => ['vendor_submissions'], 'kw' => 'track status submission shortlisted interview offer hired rejected vendor',
            'body' => '**My Submissions** shows every profile you submitted and where it is: Application received → Under review → Interview → Offer made → Hired (or Not selected).',
            'link' => ['vendor', ['a' => 'submissions'], 'My Submissions']],

        // ── Candidate portal ──
        ['title' => 'Complete your candidate profile', 'perms' => ['candidate_jobs', 'candidate_applications'], 'kw' => 'profile resume cv upload skills experience complete candidate update',
            'body' => 'Open **My Profile**, add your headline, experience, skills, expected CTC, notice period and upload your resume. A complete profile is required to apply, and recruiters search the talent pool by these fields for future openings.',
            'link' => ['talent', ['a' => 'profile'], 'My Profile']],
        ['title' => 'Find and apply for jobs', 'perms' => ['candidate_jobs'], 'kw' => 'apply job search opening vacancy candidate browse',
            'body' => '**Browse Jobs** lists open positions. Search by title, skill or location, open a job and click **Apply now**. Your saved profile and resume are sent. You can apply to several jobs.',
            'link' => ['talent', ['a' => 'jobs'], 'Browse Jobs']],
        ['title' => 'Track your applications', 'perms' => ['candidate_applications'], 'kw' => 'application status track withdraw interview selected candidate',
            'body' => '**My Applications** shows each job you applied to and its stage. You can withdraw an application that is still under review.',
            'link' => ['talent', ['a' => 'applications'], 'My Applications']],
        ['title' => 'Job alerts and open-to-work', 'perms' => ['candidate_jobs'], 'kw' => 'alert notify future jobs open to work visible recruiter',
            'body' => 'In **My Profile** keep **Open to work** on so recruiters can find you in the talent pool, and **Job alerts** on to be e-mailed about new matching openings.',
            'link' => ['talent', ['a' => 'profile'], 'My Profile']],
    ];
}

/** Guide entries the current user may use. */
function assistant_visible_guide(): array
{
    return array_values(array_filter(assistant_guide(), fn($g) => !$g['perms'] || can_any(...$g['perms'])));
}

/** Audience value of knowledge-base articles the current user may read. */
function kb_audiences(): array
{
    if (is_agent()) return ['Everyone', 'Staff', 'Clients', 'Vendors', 'Candidates'];
    return ['Everyone', ['staff' => 'Staff', 'client' => 'Clients', 'vendor' => 'Vendors', 'candidate' => 'Candidates'][audience_of(role())]];
}

/** Words that carry meaning for matching. */
function assistant_terms(string $s): array
{
    static $stop = ['the', 'a', 'an', 'to', 'i', 'my', 'me', 'how', 'do', 'can', 'what', 'is', 'are', 'of', 'for', 'in', 'on', 'and', 'or', 'where',
        'when', 'why', 'it', 'this', 'that', 'with', 'please', 'you', 'your', 'we', 'our', 'be', 'get', 'see', 'show', 'tell', 'about', 'there', 'any', 'kya', 'kaise', 'hai', 'ka', 'ki', 'ke'];
    $words = preg_split('/[^a-z0-9]+/', strtolower($s), -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($words as $w) {
        if (strlen($w) < 2 || in_array($w, $stop, true)) continue;
        $out[] = strlen($w) > 4 ? preg_replace('/(ing|ed|es|s)$/', '', $w) : $w; // crude stemming
    }
    return array_values(array_unique($out));
}

/** Ranked help results for a question: guide entries + knowledge-base articles. */
function assistant_search(string $q, int $limit = 4): array
{
    $terms = assistant_terms($q);
    if (!$terms) return [];
    $score = function (string $title, string $kw, string $body) use ($terms): float {
        $s = 0;
        $t = strtolower($title);
        $k = strtolower($kw);
        $b = strtolower($body);
        foreach ($terms as $w) {
            if (str_contains($t, $w)) $s += 3;
            if (str_contains($k, $w)) $s += 2;
            if (str_contains($b, $w)) $s += 0.5;
        }
        return $s / max(1, count($terms)) * min(count($terms), 3);
    };
    $hits = [];
    foreach (assistant_visible_guide() as $g) {
        $s = $score($g['title'], $g['kw'], $g['body']);
        if ($s > 0) $hits[] = ['score' => $s, 'title' => $g['title'], 'body' => $g['body'], 'url' => url($g['link'][0], $g['link'][1]), 'label' => $g['link'][2], 'kind' => 'guide'];
    }
    $aud = kb_audiences();
    $in = implode(',', array_fill(0, count($aud), '?'));
    foreach (all("SELECT id, title, category, tags, body FROM hrm_kb_articles WHERE published = 1 AND audience IN ($in)", $aud) as $a) {
        $s = $score($a['title'], $a['category'] . ' ' . $a['tags'], $a['body']);
        if ($s > 0) $hits[] = ['score' => $s + 0.25, 'title' => $a['title'], 'body' => $a['body'], 'url' => url('help', ['a' => 'article', 'id' => $a['id']]), 'label' => 'Read article', 'kind' => 'kb'];
    }
    usort($hits, fn($x, $y) => $y['score'] <=> $x['score']);
    return array_slice($hits, 0, $limit);
}

/* ───────────────────────── Data tools (read-only, user-scoped) ───────────────────────── */

/**
 * Tools available to the current user: name => [description, input schema properties, handler].
 * Every handler reads only what the user could already see in the portal.
 */
function assistant_tools(): array
{
    $me = my_employee_id();
    $uid = (int) user()['id'];
    $t = [];
    $t['search_help'] = ['Search the HRMS feature guide and knowledge base. Use for any "how do I / where is / what is" question about the portal.',
        ['query' => ['type' => 'string', 'description' => 'What the user wants to do, in a few words']],
        fn($in) => array_map(fn($h) => ['title' => $h['title'], 'answer' => $h['body'], 'link' => $h['url']], assistant_search((string) ($in['query'] ?? ''), 4))];

    if ($me && can('self_leaves')) {
        $t['get_my_leave_balance'] = ['The user\'s own leave balance for this year per leave type (quota, used, pending, remaining) plus their recent requests.', [],
            function () use ($me) {
                return [
                    'year' => (int) date('Y'),
                    'balances' => array_map(fn($b) => ['type' => $b['name'], 'quota' => (float) $b['annual_quota'], 'used' => $b['used'], 'pending' => $b['pending'], 'remaining' => $b['remaining'] ?? 'unlimited / unpaid'], leave_balances($me)),
                    'recent_requests' => all('SELECT t.name type, l.start_date, l.end_date, l.days, l.status FROM hrm_leaves l JOIN hrm_leave_types t ON t.id = l.leave_type_id WHERE l.employee_id = ? ORDER BY l.created_at DESC LIMIT 5', [$me]),
                    'link' => url('leaves', ['a' => 'my']),
                ];
            }];
    }
    if ($me && can('self_attendance')) {
        $t['get_my_attendance'] = ['The user\'s own attendance: today\'s punches and hours worked, plus a summary of this month.', [],
            function () use ($me) {
                $ps = punch_summary(day_punches($me, date('Y-m-d')));
                $month = all("SELECT status, COUNT(*) c, SUM(is_late) late FROM hrm_attendance WHERE employee_id = ? AND date >= ? GROUP BY status", [$me, date('Y-m-01')]);
                return [
                    'today' => ['punched_in' => (bool) $ps['count'], 'currently_working' => (bool) $ps['open_since'], 'first_in' => $ps['first_in'] ? ftime($ps['first_in']) : null,
                        'last_out' => $ps['last_out'] ? ftime($ps['last_out']) : null, 'worked' => minutes_to_hm(live_minutes($ps)) ?: '0h 00m',
                        'office_hours' => setting('office_start') . ' – ' . setting('office_end')],
                    'this_month' => ['by_status' => array_column($month, 'c', 'status'), 'late_marks' => (int) array_sum(array_column($month, 'late'))],
                    'link' => url('attendance', ['a' => 'my']),
                ];
            }];
    }
    if ($me && can('self_payslips')) {
        $t['get_my_payslips'] = ['The user\'s own latest finalised payslips (month, gross, deductions, net pay).', [],
            fn() => ['payslips' => array_map(fn($p) => ['month' => date('F Y', strtotime($p['month'] . '-01')), 'gross' => money($p['gross']), 'deductions' => money($p['total_deductions']), 'net_pay' => money($p['net_pay']), 'lop_days' => (float) $p['lop_days']],
                all("SELECT r.month, s.gross, s.total_deductions, s.net_pay, s.lop_days FROM hrm_payslips s JOIN hrm_payroll_runs r ON r.id = s.run_id WHERE s.employee_id = ? AND r.status = 'Finalized' ORDER BY r.month DESC LIMIT 3", [$me])),
                'link' => url('payroll', ['a' => 'my'])]];
    }
    if ($me && can('self_tasks')) {
        $t['get_my_tasks'] = ['The user\'s own open tasks with project, priority and due date.', [],
            fn() => ['tasks' => all("SELECT t.title, t.status, t.priority, t.due_date, p.name project FROM hrm_tasks t LEFT JOIN hrm_projects p ON p.id = t.project_id WHERE t.assignee_id = ? AND t.status <> 'Done' ORDER BY t.due_date IS NULL, t.due_date LIMIT 10", [$me]),
                'link' => url('tasks', ['a' => 'my'])]];
    }
    if ($me && can('self_performance')) {
        $t['get_my_performance'] = ['The user\'s own probation status (track, end date, days left), goals progress, latest review rating and reviews waiting on them.', [],
            fn() => ['summary' => performance_summary_for($me) ?: 'No probation, goals or reviews yet.',
                'pending_reviews' => array_map(fn($r) => ['for' => (int) $r['employee_id'] === $me ? 'self' : emp_name($r), 'type' => $r['kind'], 'status' => $r['status'], 'due' => $r['due_date']], my_pending_reviews()),
                'link' => url('performance', ['a' => 'my'])]];
    }
    if (can('manage_performance')) {
        $t['get_probation_due'] = ['Employees whose probation ends soon or is overdue (within the reminder window), in the user\'s scope.', [],
            fn() => ['employees' => array_map(function ($e) { $p = probation_info($e); return ['name' => emp_name($e), 'track' => $p['track'], 'ends' => $p['end'], 'status' => probation_countdown($p)]; },
                all("SELECT * FROM hrm_employees WHERE status = 'On Probation' AND probation_end <= DATE_ADD(CURDATE(), INTERVAL " . (int) setting('probation_reminder_days', 15) . " DAY) AND " . perf_scope_sql('id') . ' ORDER BY probation_end LIMIT 15')),
                'link' => url('performance', ['a' => 'probation'])]];
    }
    if (can_any('approve_leaves', 'approve_expenses')) {
        $t['get_pending_approvals'] = ['How many leave requests and expense claims are waiting for this user\'s approval.', [],
            fn() => pending_counts() + ['leave_link' => url('leaves', ['status' => 'Pending']), 'expense_link' => url('expenses', ['status' => 'Pending'])]];
    }
    if (can('view_holidays')) {
        $t['get_upcoming_holidays'] = ['The next company holidays.', [],
            fn() => ['holidays' => all('SELECT title, date, type FROM hrm_holidays WHERE date >= CURDATE() ORDER BY date LIMIT 6'), 'link' => url('holidays')]];
    }
    if (can('raise_tickets')) {
        $t['get_my_tickets'] = ['The user\'s own support tickets and their status.', [],
            fn() => ['tickets' => all('SELECT ticket_no, subject, status, priority, updated_at FROM hrm_tickets WHERE requester_id = ? ORDER BY id DESC LIMIT 6', [$uid]),
                'new_ticket_link' => url('support', ['a' => 'create'])]];
    }
    if (org_wide() && can('view_reports')) {
        $t['get_hr_snapshot'] = ['Company-wide HR numbers for today: headcount, present, on leave, pending approvals, open positions, candidates in pipeline.', [],
            fn() => [
                'active_employees' => (int) val("SELECT COUNT(*) FROM hrm_employees WHERE status NOT IN " . EMP_INACTIVE_SQL . ""),
                'present_today' => (int) val("SELECT COUNT(*) FROM hrm_attendance WHERE date = CURDATE() AND status IN ('Present','Half Day','WFH')"),
                'late_today' => (int) val('SELECT COUNT(*) FROM hrm_attendance WHERE date = CURDATE() AND is_late = 1'),
                'on_leave_today' => count(on_leave_on(date('Y-m-d'))),
                'pending_leave_requests' => (int) val("SELECT COUNT(*) FROM hrm_leaves WHERE status = 'Pending'"),
                'open_positions' => (int) val("SELECT COALESCE(SUM(openings),0) FROM hrm_jobs WHERE status = 'Open'"),
                'candidates_in_pipeline' => (int) val("SELECT COUNT(*) FROM hrm_candidates WHERE stage NOT IN ('Hired','Rejected')"),
                'open_support_tickets' => (int) val("SELECT COUNT(*) FROM hrm_tickets WHERE status IN ('Open','In Progress','Waiting on Customer')"),
            ]];
    }
    if (is_client()) {
        $cid = my_client_id();
        $t['get_my_company_summary'] = ['For client logins: their company\'s active projects with progress, and invoice balance due.', [],
            fn() => [
                'projects' => can('client_projects') ? all("SELECT name, status, progress, deadline FROM hrm_projects WHERE client_id = ? AND status <> 'Cancelled' ORDER BY deadline", [$cid]) : 'no access',
                'invoices' => can('client_invoices') ? one("SELECT COALESCE(SUM(total),0) billed, COALESCE(SUM(paid),0) paid, COALESCE(SUM(total - paid),0) due FROM hrm_invoices WHERE client_id = ? AND status <> 'Cancelled'", [$cid]) : 'no access',
            ]];
    }
    if (is_vendor() && can('vendor_jobs')) {
        $vid = my_vendor_id();
        $t['get_open_jobs'] = ['Open requirements shared with this vendor agency.', [],
            function () use ($vid) {
                [$w, $p] = vendor_jobs_where($vid);
                return ['jobs' => all("SELECT j.id, j.title, j.location, j.experience, j.openings, j.salary_range FROM hrm_jobs j WHERE $w ORDER BY j.created_at DESC LIMIT 15", $p), 'link' => url('vendor', ['a' => 'jobs'])];
            }];
    }
    if (is_vendor() && can('vendor_submissions')) {
        $vid = my_vendor_id();
        $t['get_my_submissions'] = ['Candidates this vendor agency submitted, with their current stage.', [],
            fn() => ['by_stage' => array_column(all('SELECT stage, COUNT(*) c FROM hrm_candidates WHERE vendor_id = ? GROUP BY stage', [$vid]), 'c', 'stage'),
                'recent' => array_map(fn($r) => $r + ['stage_label' => CANDIDATE_STAGE_LABELS[$r['stage']] ?? $r['stage']], all('SELECT c.name, j.title job, c.stage, c.created_at submitted FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.vendor_id = ? ORDER BY c.id DESC LIMIT 8', [$vid])),
                'link' => url('vendor', ['a' => 'submissions'])]];
    }
    if (is_candidate()) {
        $tid = my_talent_id();
        if (can('candidate_jobs')) {
            $t['get_open_jobs'] = ['Open positions the candidate can apply to (optionally filtered by a keyword such as a skill, title or city).',
                ['keyword' => ['type' => 'string', 'description' => 'Optional filter, e.g. "PHP" or "Noida". Empty string for all.']],
                function ($in) {
                    $k = trim((string) ($in['keyword'] ?? ''));
                    $sql = 'SELECT j.id, j.title, j.location, j.experience, j.employment_type, j.skills FROM hrm_jobs j WHERE ' . public_jobs_where();
                    $p = [];
                    if ($k !== '') { $sql .= ' AND (j.title LIKE ? OR j.skills LIKE ? OR j.location LIKE ? OR j.description LIKE ?)'; $p = array_fill(0, 4, "%$k%"); }
                    return ['jobs' => array_map(fn($j) => $j + ['link' => url('talent', ['a' => 'job', 'id' => $j['id']])], all("$sql ORDER BY j.created_at DESC LIMIT 10", $p))];
                }];
        }
        if (can('candidate_applications')) {
            $t['get_my_applications'] = ['The candidate\'s own job applications and their status, plus profile completeness.', [],
                function () use ($tid) {
                    [$pct, $missing] = talent_completeness(user()['talent']);
                    return ['applications' => array_map(fn($r) => ['job' => $r['job'], 'status' => CANDIDATE_STAGE_LABELS[$r['stage']] ?? $r['stage'], 'applied' => fdate($r['created_at'])],
                        all('SELECT j.title job, c.stage, c.created_at FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.talent_id = ? ORDER BY c.id DESC LIMIT 10', [$tid])),
                        'profile_complete_percent' => $pct, 'profile_missing' => $missing, 'link' => url('talent', ['a' => 'applications'])];
                }];
        }
    }
    return $t;
}

function assistant_run_tool(string $name, array $input): array
{
    $tools = assistant_tools();
    if (!isset($tools[$name])) return ['error' => 'This information is not available to you.'];
    try {
        return ($tools[$name][2])($input);
    } catch (Throwable $e) {
        error_log('assistant tool ' . $name . ': ' . $e->getMessage());
        return ['error' => 'Could not load that information right now.'];
    }
}

/* ───────────────────────── Offline engine ───────────────────────── */

/** Personal-data intents the offline engine recognises: regex => tool. */
const ASSISTANT_INTENTS = [
    '/\b(leave|leaves)\b.*\b(balance|left|remaining|available|how many)|\b(balance|how many)\b.*\bleave/i' => 'get_my_leave_balance',
    '/\b(my )?(attendance|punch(ed)?|worked|hours) (today|status)|\bam i (punched|late)|\bdid i punch|\bhours (worked|today)/i' => 'get_my_attendance',
    '/\b(my )?(last|latest|recent)? ?(payslip|salary|net pay)\b.*\b(month|last|latest|how much)|\bhow much (salary|net pay)/i' => 'get_my_payslips',
    '/\bmy (open |pending )?tasks?\b|\btasks? (due|assigned)/i' => 'get_my_tasks',
    '/\bpending (approvals?|requests?|leaves?)|\b(waiting|need).*\bapprov/i' => 'get_pending_approvals',
    '/\b(upcoming|next) holidays?|\bholiday list\b|\bwhen is the next holiday/i' => 'get_upcoming_holidays',
    '/\bmy tickets?\b|\bticket status\b|\bstatus of my (ticket|complaint)/i' => 'get_my_tickets',
    '/\b(today\'?s )?(hr )?(snapshot|summary|headcount)\b|\bhow many (employees|people) (are )?(present|on leave)/i' => 'get_hr_snapshot',
    '/\b(my )?applications?\b.*\b(status|track)|\bstatus of my application|\bmy applications\b/i' => 'get_my_applications',
    '/\bmy submissions?\b|\bsubmission status\b|\bcandidates? i submitted/i' => 'get_my_submissions',
    '/\b(open|available|new|latest) (jobs|positions|openings|requirements)\b|\bany (jobs|openings)\b|\bjobs? (for|in) /i' => 'get_open_jobs',
    '/\b(project progress|my projects|balance due|outstanding (amount|invoice))\b/i' => 'get_my_company_summary',
];

/** Plain-language rendering of a tool result for the offline engine. */
function assistant_format_tool(string $tool, array $d): string
{
    $link = fn($u, $l) => $u ? "\n\n[$l]($u)" : '';
    switch ($tool) {
        case 'get_my_leave_balance':
            $lines = array_map(fn($b) => '- **' . $b['type'] . '**: ' . (is_numeric($b['remaining']) ? (float) $b['remaining'] . ' left' : $b['remaining']) . " (used {$b['used']}" . ($b['pending'] ? ", {$b['pending']} pending" : '') . ')', $d['balances']);
            return "Your leave balance for {$d['year']}:\n" . implode("\n", $lines) . $link($d['link'], 'Open My Leaves');
        case 'get_my_attendance':
            $t = $d['today'];
            $s = !$t['punched_in'] ? "You haven't punched in today yet (office hours {$t['office_hours']})." : ($t['currently_working'] ? "You're punched in since **{$t['first_in']}**, with **{$t['worked']}** worked so far." : "You worked **{$t['worked']}** today ({$t['first_in']} – {$t['last_out']}).");
            $m = $d['this_month']['by_status'];
            return $s . ($m ? "\n\nThis month: " . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($m), $m)) . ($d['this_month']['late_marks'] ? ", {$d['this_month']['late_marks']} late mark(s)" : '') . '.' : '') . $link($d['link'], 'Open My Attendance');
        case 'get_my_payslips':
            if (!$d['payslips']) return "No finalised payslips yet. They appear here once HR finalises the month's payroll." . $link($d['link'], 'My Payslips');
            return "Your latest payslips:\n" . implode("\n", array_map(fn($p) => "- **{$p['month']}**: net pay {$p['net_pay']} (gross {$p['gross']}, deductions {$p['deductions']})", $d['payslips'])) . $link($d['link'], 'Open My Payslips');
        case 'get_my_tasks':
            if (!$d['tasks']) return 'You have no open tasks. 🎉' . $link($d['link'], 'My Tasks');
            return "Your open tasks:\n" . implode("\n", array_map(fn($x) => "- **{$x['title']}** ({$x['status']}, {$x['priority']})" . ($x['due_date'] ? ' · due ' . fdate($x['due_date']) : '') . ($x['project'] ? " · {$x['project']}" : ''), $d['tasks'])) . $link($d['link'], 'Open My Tasks');
        case 'get_pending_approvals':
            return "Waiting for you: **{$d['pending_leaves']}** leave request(s) and **{$d['pending_expenses']}** expense claim(s)." . $link($d['leave_link'], 'Review leave requests');
        case 'get_upcoming_holidays':
            if (!$d['holidays']) return 'No upcoming holidays are published yet.' . $link($d['link'], 'Holidays');
            return "Upcoming holidays:\n" . implode("\n", array_map(fn($h) => '- **' . fdate($h['date'], 'D, d M') . "**: {$h['title']}", $d['holidays'])) . $link($d['link'], 'Holiday calendar');
        case 'get_my_tickets':
            if (!$d['tickets']) return "You haven't raised any tickets." . $link($d['new_ticket_link'], 'Raise a ticket');
            return "Your tickets:\n" . implode("\n", array_map(fn($x) => "- **{$x['ticket_no']}** {$x['subject']}: {$x['status']}", $d['tickets'])) . $link(url('support'), 'Open My Tickets');
        case 'get_hr_snapshot':
            return "Today at a glance:\n- Active employees: **{$d['active_employees']}**\n- Present: **{$d['present_today']}** ({$d['late_today']} late)\n- On leave: **{$d['on_leave_today']}**\n- Pending leave requests: **{$d['pending_leave_requests']}**\n- Open positions: **{$d['open_positions']}** · candidates in pipeline: **{$d['candidates_in_pipeline']}**\n- Open support tickets: **{$d['open_support_tickets']}**";
        case 'get_my_applications':
            $s = $d['applications'] ? "Your applications:\n" . implode("\n", array_map(fn($a) => "- **{$a['job']}**: {$a['status']} (applied {$a['applied']})", $d['applications'])) : "You haven't applied to any job yet.";
            return $s . "\n\nYour profile is **{$d['profile_complete_percent']}%** complete" . ($d['profile_missing'] ? ' (missing: ' . implode(', ', $d['profile_missing']) . ')' : '') . '.' . $link($d['link'], 'My Applications');
        case 'get_my_submissions':
            $s = $d['by_stage'] ? 'Your submissions by stage: ' . implode(', ', array_map(fn($k, $v) => (CANDIDATE_STAGE_LABELS[$k] ?? $k) . " $v", array_keys($d['by_stage']), $d['by_stage'])) . '.' : "You haven't submitted any candidates yet.";
            return $s . $link($d['link'], 'Open My Submissions');
        case 'get_open_jobs':
            if (!$d['jobs']) return 'There are no open positions right now.';
            return "Open positions:\n" . implode("\n", array_map(fn($j) => "- **{$j['title']}**" . ($j['location'] ? " · {$j['location']}" : '') . ($j['experience'] ? " · {$j['experience']}" : ''), $d['jobs'])) . $link($d['link'] ?? url('talent', ['a' => 'jobs']), 'See all jobs');
        case 'get_my_company_summary':
            $p = is_array($d['projects']) ? implode("\n", array_map(fn($x) => "- **{$x['name']}**: {$x['progress']}% ({$x['status']})", $d['projects'])) : '';
            $i = is_array($d['invoices']) ? "\n\nInvoices: billed " . money($d['invoices']['billed']) . ', paid ' . money($d['invoices']['paid']) . ', **due ' . money($d['invoices']['due']) . '**' : '';
            return ($p ? "Your projects:\n$p" : 'No active projects.') . $i;
    }
    return json_encode($d);
}

/** Answer without an LLM. Returns [text, matched(bool), toolsUsed[]]. */
function assistant_offline(string $q): array
{
    $tools = assistant_tools();
    if (preg_match('/^\s*(hi|hello|hey|namaste|good (morning|afternoon|evening))\b/i', $q) && str_word_count($q) <= 4) {
        return [assistant_greeting(), true, []];
    }
    if (preg_match('/\b(thanks?|thank you|thx|great|awesome)\b/i', $q) && str_word_count($q) <= 5) {
        return ['Happy to help! Ask me anything else about the portal.', true, []];
    }
    foreach (ASSISTANT_INTENTS as $re => $tool) {
        if (isset($tools[$tool]) && preg_match($re, $q)) {
            $in = $tool === 'get_open_jobs' && preg_match('/\b(?:for|in)\s+([a-z0-9 .#+-]{2,30})/i', $q, $m) ? ['keyword' => trim($m[1])] : [];
            return [assistant_format_tool($tool, assistant_run_tool($tool, $in)), true, [$tool]];
        }
    }
    $hits = assistant_search($q, 3);
    if ($hits && $hits[0]['score'] >= 1.5) {
        $top = $hits[0];
        $text = "**{$top['title']}**\n\n{$top['body']}\n\n[{$top['label']}]({$top['url']})";
        $more = array_slice(array_filter($hits, fn($h) => $h['score'] >= 1), 1);
        if ($more) $text .= "\n\nRelated: " . implode(' · ', array_map(fn($h) => "[{$h['title']}]({$h['url']})", $more));
        return [$text, true, ['search_help']];
    }
    return ["I couldn't find an answer to that in the guide. Try rephrasing (for example *\"how do I apply for leave\"*), browse the [Help Center](" . url('help') . ')'
        . (can('raise_tickets') ? ', or raise a ticket and our support team will help.' : '.'), false, []];
}

function assistant_greeting(): string
{
    $name = user()['employee']['first_name'] ?? (user()['talent']['full_name'] ?? user()['display_name']);
    $examples = [];
    foreach ([['get_my_leave_balance', 'How many leaves do I have left?'], ['get_my_attendance', 'Did I punch in today?'], ['get_pending_approvals', 'What is waiting for my approval?'],
                 ['get_hr_snapshot', "Give me today's HR summary"], ['get_my_applications', 'What is the status of my applications?'], ['get_open_jobs', 'Show open jobs'],
                 ['get_my_submissions', 'How are my submissions doing?'], ['get_my_company_summary', 'How are our projects progressing?']] as [$tool, $ex]) {
        if (isset(assistant_tools()[$tool])) $examples[] = "- *$ex*";
    }
    return "Hi $name! I'm your HRMS assistant. Ask me how to do anything in the portal, or about your own data. For example:\n" . implode("\n", array_slice($examples, 0, 3))
        . "\n- *How do I " . (can('self_leaves') ? 'apply for leave' : (can('vendor_submissions') ? 'submit a candidate' : (can('candidate_jobs') ? 'apply for a job' : 'raise a support ticket'))) . '?*';
}

/* ───────────────────────── Claude engine ───────────────────────── */

function assistant_api_key(): string
{
    return (string) (config('anthropic_api_key') ?: (getenv('ANTHROPIC_API_KEY') ?: setting('ai_api_key', '')));
}

function assistant_enabled(): bool
{
    return setting('ai_enabled', '1') === '1' && can('use_assistant');
}

/** Stable, permission-specific instructions + guide (cacheable), then the per-user context. */
function assistant_system(): array
{
    $company = setting('company_name', 'Adorable IT Services');
    $guide = implode("\n\n", array_map(fn($g) => "## {$g['title']}\n{$g['body']}\nLink: " . url($g['link'][0], $g['link'][1]), assistant_visible_guide()));
    $stable = <<<TXT
You are the in-app assistant of the {$company} HRMS portal (attendance, leave, payroll, recruitment, projects, client / sub-vendor / candidate portals and a support desk).

How to answer:
- Help only with this portal and the user's own work in it. For anything unrelated, say briefly that you can only help with the HRMS.
- For "how do I…" questions, answer from the feature guide below (or the search_help tool for anything not covered). Give short numbered steps and end with the relevant link.
- For questions about the user's own data (leave balance, attendance, payslips, tasks, tickets, applications, submissions, approvals), call the matching tool. Never guess numbers.
- The guide and tools only include what this user is allowed to use. If they ask about something not in them, tell them they don't have access to it and suggest asking their administrator. Never reveal other people's personal data.
- If you can't solve it, suggest raising a support ticket.
- Style: friendly, concise (under 150 words unless steps need more), plain markdown (**bold**, lists, [label](link)). Only use links that appear in the guide or tool results. Reply in the user's language (English or Hindi/Hinglish).

# Feature guide for this user
{$guide}
TXT;
    $u = user();
    $who = $u['display_name'] . ' — role: ' . ROLES[$u['role']]
        . ($u['client'] ? ', client company: ' . $u['client']['name'] : '') . ($u['vendor'] ? ', vendor agency: ' . $u['vendor']['name'] : '')
        . ($u['employee'] ? ', employee code: ' . $u['employee']['emp_code'] : '');
    return [
        ['type' => 'text', 'text' => $stable, 'cacheControl' => ['type' => 'ephemeral']],
        ['type' => 'text', 'text' => "Signed-in user: $who\nToday: " . date('l, d F Y, h:i A') . ' (' . config('timezone', 'Asia/Kolkata') . ')'],
    ];
}

function assistant_tool_defs(): array
{
    $defs = [];
    foreach (assistant_tools() as $name => [$desc, $props]) {
        $defs[] = ['name' => $name, 'description' => $desc, 'inputSchema' => ['type' => 'object', 'properties' => (object) $props, 'required' => array_keys($props)]];
    }
    return $defs;
}

/**
 * Asks Claude. $history is [['role' => 'user'|'assistant', 'content' => string], ...] ending with the new question.
 * Returns [text, toolsUsed[], inputTokens, outputTokens]. Throws on API / network errors.
 */
function assistant_claude(array $history): array
{
    if (!composer_autoload()) {
        throw new RuntimeException('The Claude API library needs PHP ' . (PHP_VERSION_ID < 80200 ? '8.2 or newer' : 'its vendor/ folder') . ' on this server.');
    }
    $client = new \Anthropic\Client(
        apiKey: assistant_api_key(),
        requestOptions: ['maxRetries' => 1, 'transporter' => new \GuzzleHttp\Client(['timeout' => 45, 'connect_timeout' => 8])],
    );
    $model = setting('ai_model', 'claude-opus-5') ?: 'claude-opus-5';
    $effort = in_array(setting('ai_effort', 'low'), ['low', 'medium', 'high'], true) ? setting('ai_effort', 'low') : 'low';
    $system = assistant_system();
    $tools = assistant_tool_defs();
    $messages = $history;
    $used = [];
    $inTok = $outTok = 0;

    for ($turn = 0; $turn < 5; $turn++) {
        $response = $client->beta->messages->create(
            model: $model,
            maxTokens: 4096,
            system: $system,
            messages: $messages,
            tools: $tools,
            thinking: ['type' => 'adaptive'],
            outputConfig: ['effort' => $effort],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );
        $inTok += (int) ($response->usage->inputTokens ?? 0);
        $outTok += (int) ($response->usage->outputTokens ?? 0);

        if ($response->stopReason === 'refusal') {
            return ["I can't help with that request. If you need help with the portal, ask me another question or raise a support ticket.", $used, $inTok, $outTok];
        }
        if ($response->stopReason !== 'tool_use') {
            $text = '';
            foreach ($response->content as $block) {
                if ($block->type === 'text') $text .= $block->text;
            }
            return [trim($text) ?: 'Sorry, I could not put together an answer. Please try rephrasing.', $used, $inTok, $outTok];
        }
        $results = [];
        foreach ($response->content as $block) {
            if ($block->type !== 'tool_use') continue;
            $used[] = $block->name;
            $input = is_array($block->input) ? $block->input : [];
            $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => json_encode(assistant_run_tool($block->name, $input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }
        $messages[] = ['role' => 'assistant', 'content' => $response->content];
        $messages[] = ['role' => 'user', 'content' => $results];
    }
    return ['That took too many steps. Please ask a more specific question.', $used, $inTok, $outTok];
}

/** Recent conversation for context (text only, oldest first, must start with a user turn). */
function assistant_history(int $userId, int $limit = 10): array
{
    $rows = array_reverse(all('SELECT role, content FROM hrm_ai_messages WHERE user_id = ? AND created_at >= ? ORDER BY id DESC LIMIT ' . (int) $limit,
        [$userId, date('Y-m-d H:i:s', strtotime('-6 hours'))]));
    while ($rows && $rows[0]['role'] !== 'user') array_shift($rows);
    // Collapse any consecutive same-role rows (e.g. a failed turn) so roles alternate.
    $out = [];
    foreach ($rows as $r) {
        if ($out && end($out)['role'] === $r['role']) {
            $out[count($out) - 1]['content'] .= "\n\n" . $r['content'];
        } else {
            $out[] = ['role' => $r['role'], 'content' => $r['content']];
        }
    }
    return $out;
}

/** Full question → answer flow. Returns the payload sent to the chat widget. */
function assistant_answer(string $question): array
{
    $uid = (int) user()['id'];
    $history = assistant_history($uid);
    if ($history && end($history)['role'] === 'user') array_pop($history); // unanswered question from a failed turn
    insert('hrm_ai_messages', ['user_id' => $uid, 'role' => 'user', 'content' => $question]);

    $source = 'offline';
    $matched = true;
    $used = [];
    $in = $out = null;
    $notice = null;
    if (assistant_api_key() !== '') {
        try {
            [$text, $used, $in, $out] = assistant_claude([...$history, ['role' => 'user', 'content' => $question]]);
            $source = 'claude';
        } catch (Throwable $e) {
            error_log('assistant: ' . get_class($e) . ': ' . $e->getMessage());
            $notice = 'The AI service is unavailable right now, so this answer comes from the built-in guide.';
        }
    }
    if ($source === 'offline') {
        [$text, $matched, $used] = assistant_offline($question);
        if (!$matched) $source = 'unanswered';
    }
    $id = insert('hrm_ai_messages', ['user_id' => $uid, 'role' => 'assistant', 'content' => $text, 'source' => $source,
        'tools' => $used ? mb_substr(implode(',', array_unique($used)), 0, 255) : null, 'input_tokens' => $in, 'output_tokens' => $out]);
    return ['id' => $id, 'answer' => $text, 'source' => $source, 'notice' => $notice,
        'ticket_url' => can('raise_tickets') ? url('support', ['a' => 'create', 'subject' => mb_strimwidth($question, 0, 120, '…'), 'from' => 'assistant']) : null];
}

/** Per-user rate limit (messages per 10 minutes). */
function assistant_rate_limited(int $userId): bool
{
    $limit = max(5, (int) setting('ai_rate_limit', 20));
    return (int) val("SELECT COUNT(*) FROM hrm_ai_messages WHERE user_id = ? AND role = 'user' AND created_at >= ?", [$userId, date('Y-m-d H:i:s', time() - 600)]) >= $limit;
}
