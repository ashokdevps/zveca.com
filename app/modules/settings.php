<?php
/** Company-wide settings stored in hrm_settings. */

authorize('manage_settings');

$fields = [
    'Company' => [
        'company_name'    => ['label' => 'Company name', 'required' => true],
        'company_email'   => ['label' => 'HR e-mail', 'type' => 'email'],
        'company_phone'   => ['label' => 'Phone'],
        'whatsapp_number' => ['label' => 'WhatsApp support number', 'type' => 'tel', 'placeholder' => '919871864942', 'help' => 'With country code, digits only (e.g. 91 + mobile). Powers the green WhatsApp help button. Leave blank to hide it.'],
        'currency'        => ['label' => 'Currency symbol', 'col' => 'col-md-3'],
        'emp_code_prefix' => ['label' => 'Employee code prefix', 'col' => 'col-md-3', 'help' => 'e.g. ADIT → ADIT0001'],
        'company_address' => ['label' => 'Address (shown on payslips & invoices)', 'type' => 'textarea', 'rows' => 3, 'col' => 'col-12'],
        'company_gstin'    => ['label' => 'Company GSTIN (printed on invoices)', 'col' => 'col-md-6', 'maxlength' => 15, 'placeholder' => '09AAACA1234A1Z5'],
        'company_state'    => ['label' => 'Company state (GST registration)', 'type' => 'select', 'options' => INDIAN_STATES, 'col' => 'col-md-6', 'help' => 'Clients in this state are billed CGST + SGST, others IGST.'],
    ],
    'Working hours & attendance' => [
        'office_start'  => ['label' => 'Office start', 'type' => 'time', 'col' => 'col-md-3'],
        'office_end'    => ['label' => 'Office end', 'type' => 'time', 'col' => 'col-md-3'],
        'grace_minutes' => ['label' => 'Late grace (minutes)', 'type' => 'number', 'min' => 0, 'col' => 'col-md-3'],
        'face_punch'    => ['label' => 'Face recognition punch', 'type' => 'select', 'col' => 'col-md-6',
            'options' => ['off' => 'Off: normal punch button', 'optional' => 'Optional: employees may punch with their face', 'required' => 'Required: every punch must be face-verified'],
            'help' => 'Employees set up Face ID once (My Attendance → Face ID). Camera access needs HTTPS.'],
        'face_match_threshold' => ['label' => 'Face match strictness', 'type' => 'number', 'step' => '0.01', 'min' => '0.35', 'max' => '0.65', 'col' => 'col-md-3', 'help' => 'Lower = stricter. 0.50 recommended.'],
        'punch_require_location' => ['label' => 'Require location for every punch', 'type' => 'checkbox', 'col' => 'col-md-3'],
        'office_lat'    => ['label' => 'Office latitude', 'col' => 'col-md-3', 'placeholder' => 'e.g. 28.627100', 'maxlength' => 12],
        'office_lng'    => ['label' => 'Office longitude', 'col' => 'col-md-3', 'placeholder' => 'e.g. 77.372000', 'maxlength' => 12],
        'geofence_radius' => ['label' => 'Geofence radius (metres)', 'type' => 'number', 'min' => 0, 'max' => 50000, 'col' => 'col-md-3', 'help' => 'Punches allowed only within this distance of the office. Blank = anywhere.'],
    ],
    'Probation & performance' => [
        'probation_months_junior' => ['label' => 'Probation: junior profiles (months)', 'type' => 'number', 'min' => 1, 'max' => 24, 'col' => 'col-md-4', 'help' => 'Intern, Junior and Mid designation levels.'],
        'probation_months_senior' => ['label' => 'Probation: senior profiles (months)', 'type' => 'number', 'min' => 1, 'max' => 24, 'col' => 'col-md-4', 'help' => 'Senior, Lead, Manager, Director, Executive.'],
        'probation_reminder_days' => ['label' => 'Flag reviews due within (days)', 'type' => 'number', 'min' => 1, 'max' => 90, 'col' => 'col-md-4', 'help' => 'Shown on the Probation page, dashboard and bell.'],
    ],
    'Payroll' => [
        'pf_percent'      => ['label' => 'PF % (employee)', 'type' => 'number', 'step' => '0.01', 'min' => 0, 'col' => 'col-md-3'],
        'pf_wage_ceiling' => ['label' => 'PF wage ceiling', 'type' => 'money', 'col' => 'col-md-3'],
    ],
    'Client invoices' => [
        'invoice_due_days'     => ['label' => 'Default payment terms (days)', 'type' => 'number', 'min' => 0, 'max' => 365, 'col' => 'col-md-4', 'help' => 'Due date = invoice date + this many days.'],
        'client_invoice_sac'   => ['label' => 'Default SAC code for line items', 'col' => 'col-md-4', 'maxlength' => 8, 'help' => 'e.g. 998313 IT consulting, 998519 staffing. Confirm with your CA.'],
        'invoice_bank_details' => ['label' => 'Bank details printed on invoices', 'type' => 'textarea', 'rows' => 4, 'col' => 'col-md-6', 'placeholder' => "Account name\nBank & branch\nA/c no.\nIFSC\nUPI ID"],
        'invoice_terms'        => ['label' => 'Terms printed on invoices', 'type' => 'textarea', 'rows' => 4, 'col' => 'col-md-6'],
    ],
    'Logins & security' => [
        'default_login_password' => ['label' => 'Default password for new logins', 'col' => 'col-md-6', 'maxlength' => 72, 'autocomplete' => 'off',
            'placeholder' => 'Blank = generate a unique strong password',
            'help' => 'Pre-filled on Users & Roles → New login, where it can still be edited. At least 8 characters. Leave blank (recommended) to give every login its own generated password.'],
    ],
    'Careers & sub-vendors' => [
        'careers_enabled'  => ['label' => 'Public careers page (Find Jobs)', 'type' => 'checkbox', 'col' => 'col-md-6', 'help' => 'Lists jobs whose visibility is "Careers page + sub-vendors".'],
        'candidate_signup' => ['label' => 'Candidates can create their own profile', 'type' => 'checkbox', 'col' => 'col-md-6', 'help' => 'Self-registration for job seekers on the careers page.'],
        'hireflow_job_approval' => ['label' => 'Approve client jobs before they go live (HireFlow)', 'type' => 'checkbox', 'col' => 'col-md-6', 'help' => 'Jobs posted by clients wait in Job Openings as "Pending Approval".'],
        'vendor_dup_days'  => ['label' => 'Candidate ownership window (days)', 'type' => 'number', 'min' => 0, 'max' => 365, 'col' => 'col-md-6',
            'help' => 'Used only when ownership locks (below) are turned off: a profile submitted by one source cannot be re-submitted by another vendor within this many days, for any company. 0 = only block duplicates on the same job.'],
    ],
    'Website & analytics' => [
        'site_tracking'        => ['label' => 'Count website visitors (privacy-friendly, no cookies)', 'type' => 'checkbox', 'col' => 'col-md-6', 'help' => 'Home, careers, job, application and pricing pages. No IP addresses or cookies are stored.'],
        'visit_retention_days' => ['label' => 'Keep visitor data for (days, 0 = forever)', 'type' => 'number', 'min' => 0, 'max' => 3650, 'col' => 'col-md-3'],
        'notif_poll_seconds'   => ['label' => 'Notification refresh (seconds)', 'type' => 'number', 'min' => 20, 'max' => 600, 'col' => 'col-md-3'],
    ],
    'Agreements & documents' => [
        'doc_sign_days'        => ['label' => 'Signing requests expire after (days)', 'type' => 'number', 'min' => 1, 'max' => 90, 'col' => 'col-md-4'],
        'doc_max_mb'           => ['label' => 'Largest Word upload (MB)', 'type' => 'number', 'min' => 1, 'max' => 25, 'col' => 'col-md-4'],
        'doc_require_approval' => ['label' => 'Internal approval required before sending', 'type' => 'checkbox', 'col' => 'col-md-4', 'help' => 'Documents must be "Approved Internally" (by a user who can approve requests) before they can be sent for signature.'],
    ],
    'Resdex credits & candidate database' => [
        'rdx_billing_enabled'      => ['label' => 'Charge credits for Resdex and job posting', 'type' => 'checkbox', 'col' => 'col-md-6', 'help' => 'Off: client logins use Resdex and post jobs without a plan (contact details still need an unlock).'],
        'default_phone_country'    => ['label' => 'Default phone country', 'type' => 'select', 'options' => ['IN' => 'India (+91)', 'AE' => 'UAE (+971)', 'SA' => 'Saudi Arabia (+966)', 'GB' => 'United Kingdom (+44)', 'US' => 'USA / Canada (+1)', 'SG' => 'Singapore (+65)'], 'col' => 'col-md-3', 'help' => 'Numbers without a country code are read as this country only when they clearly fit it; others must include +code.'],
        'rdx_consent_mode'         => ['label' => 'Searchable in Resdex', 'type' => 'select', 'options' => ['not_withdrawn' => 'Active candidates unless consent was withdrawn', 'given_only' => 'Only candidates with recorded consent'], 'col' => 'col-md-3'],
        'rdx_cost_job_post'        => ['label' => 'Job posting (credits)', 'type' => 'number', 'min' => 0, 'max' => 1000, 'col' => 'col-md-2'],
        'rdx_cost_unlock'          => ['label' => 'Profile unlock', 'type' => 'number', 'min' => 0, 'max' => 1000, 'col' => 'col-md-2'],
        'rdx_cost_cv'              => ['label' => 'CV download', 'type' => 'number', 'min' => 0, 'max' => 1000, 'col' => 'col-md-2'],
        'rdx_cost_export_new'      => ['label' => 'Export (not unlocked)', 'type' => 'number', 'min' => 0, 'max' => 1000, 'col' => 'col-md-3'],
        'rdx_cost_export_unlocked' => ['label' => 'Export (unlocked)', 'type' => 'number', 'min' => 0, 'max' => 1000, 'col' => 'col-md-3'],
        'rdx_cv_redownload_free'   => ['label' => 'Re-downloading the same CV is free', 'type' => 'checkbox', 'col' => 'col-md-4'],
        'rdx_repost_charge'        => ['label' => 'Charge again when a closed job is reopened', 'type' => 'checkbox', 'col' => 'col-md-4'],
        'rdx_carry_forward'        => ['label' => 'Carry unused credits forward on renewal / upgrade', 'type' => 'checkbox', 'col' => 'col-md-4', 'help' => 'Credits always expire when a plan lapses.'],
        'rdx_gst_percent'          => ['label' => 'GST on Resdex plans (%)', 'type' => 'number', 'min' => 0, 'max' => 28, 'step' => '0.01', 'col' => 'col-md-3', 'help' => 'Confirm the rate with your tax adviser.'],
        'rdx_sac'                  => ['label' => 'SAC code on invoices', 'col' => 'col-md-3', 'maxlength' => 10, 'help' => 'Blank = the default client invoice SAC.'],
        'rdx_export_max'           => ['label' => 'Max profiles per export', 'type' => 'number', 'min' => 1, 'max' => 5000, 'col' => 'col-md-3'],
        'rdx_daily_unlock_limit'   => ['label' => 'Max unlocks per organisation per day', 'type' => 'number', 'min' => 0, 'max' => 10000, 'col' => 'col-md-3', 'help' => 'Anti-harvesting limit. 0 = no limit.'],
        'rdx_expiry_reminder_days' => ['label' => 'Expiry reminders (days before)', 'col' => 'col-md-3', 'placeholder' => '7,1'],
    ],
    'Ownership locks & vendor scores' => [
        'lock_enabled'       => ['label' => 'Company-specific candidate ownership locks', 'type' => 'checkbox', 'col' => 'col-md-6', 'help' => 'A vendor submission protects the candidate (matched on e-mail and mobile) for that client company only. Competing vendors can\'t submit or open the profile for that company while the lock is active.'],
        'lock_days'          => ['label' => 'Lock duration (days)', 'type' => 'number', 'min' => 1, 'max' => 365, 'col' => 'col-md-3', 'help' => 'Proposed policy: 30 days.'],
        'lock_reminder_days' => ['label' => 'Remind the owner before expiry (days)', 'type' => 'number', 'min' => 0, 'max' => 30, 'col' => 'col-md-3', 'help' => '0 = no reminder.'],
        'lock_max_extension_days' => ['label' => 'Longest single extension (days)', 'type' => 'number', 'min' => 1, 'max' => 180, 'col' => 'col-md-3'],
        'lock_hold_stages'   => ['label' => 'Stages that hold an expired lock', 'col' => 'col-md-9', 'help' => 'Comma separated. While the candidate is in one of these stages for that company, expiry does not release the lock. Hired candidates are always kept. Stages: ' . implode(', ', CANDIDATE_STAGES) . '.'],
        'vendor_score_labels' => ['label' => 'Score category names (85–100 | 65–84 | 0–64)', 'col' => 'col-md-6', 'help' => 'Three names separated by |, e.g. Excellent|Good|Better. Colours stay green, yellow and red.'],
    ],
    'Onboarding' => [
        'offer_approval'        => ['label' => 'Offers need approval before they are sent', 'type' => 'checkbox', 'col' => 'col-md-6', 'help' => 'By someone with "Approve offers" access, other than the person who prepared it (Super Admin may approve their own).'],
        'onboarding_activation' => ['label' => 'Generate employee ID & activate the profile', 'type' => 'select', 'col' => 'col-md-6',
            'options' => ['approval' => 'At HR final approval (before joining)', 'joining' => 'When joining is confirmed']],
        'offer_valid_days'      => ['label' => 'Offer valid for (days)', 'type' => 'number', 'min' => 1, 'max' => 60, 'col' => 'col-md-3', 'help' => 'Default "accept by" date.'],
        'notice_period_days'    => ['label' => 'Notice period (days)', 'type' => 'number', 'min' => 0, 'max' => 180, 'col' => 'col-md-3', 'help' => '{{notice_period}} in templates.'],
        'salary_basic_pct'      => ['label' => 'Basic: % of monthly CTC', 'type' => 'number', 'min' => 10, 'max' => 80, 'step' => '0.5', 'col' => 'col-md-3'],
        'salary_hra_pct'        => ['label' => 'HRA: % of basic', 'type' => 'number', 'min' => 0, 'max' => 60, 'step' => '0.5', 'col' => 'col-md-3', 'help' => '50% metro, 40% non-metro.'],
        'default_professional_tax' => ['label' => 'Professional tax / month (new joiners)', 'type' => 'money', 'col' => 'col-md-3'],
        'onboarding_probation'  => ['label' => 'New joiners start on probation', 'type' => 'checkbox', 'col' => 'col-md-3'],
        'hr_doc_require_approval' => ['label' => 'HR documents need approval before sharing', 'type' => 'checkbox', 'col' => 'col-md-6', 'help' => 'Templates and prepared documents go to someone with "Approve HR documents" first.'],
        'hr_doc_max_mb'         => ['label' => 'Largest template upload (MB)', 'type' => 'number', 'min' => 1, 'max' => 25, 'col' => 'col-md-3'],
        'activation_link_hours' => ['label' => 'Password-setup link valid (hours)', 'type' => 'number', 'min' => 1, 'max' => 336, 'col' => 'col-md-3'],
        'induction_window_days' => ['label' => 'Open induction on sign-in for (days after joining)', 'type' => 'number', 'min' => 0, 'max' => 365, 'col' => 'col-md-3', 'help' => 'New employees with pending mandatory items land on Induction & Documents.'],
        'onboarding_support_contact' => ['label' => 'Support contact in the welcome e-mail', 'col' => 'col-md-6', 'placeholder' => 'e.g. HR helpdesk: hr@company.com, +91 …'],
        'onboarding_signatory'  => ['label' => 'Letter signatory', 'col' => 'col-md-3', 'placeholder' => 'Human Resources', 'help' => '{{hr_name}} in templates.'],
        'onboarding_hr_email'   => ['label' => 'Onboarding updates e-mail', 'type' => 'email', 'col' => 'col-md-3', 'help' => 'Defaults to the HR e-mail.'],
        'onboarding_documents'  => ['label' => 'Documents candidates upload', 'type' => 'textarea', 'rows' => 8, 'col' => 'col-md-6',
            'help' => 'One per line: key | label | required (yes / no / experienced) | hint. Keep the "photo" key for the profile photo.'],
        'onboarding_compliance' => ['label' => 'Compliance checklist (HR)', 'type' => 'textarea', 'rows' => 8, 'col' => 'col-md-6',
            'help' => 'One per line: key | label | required (yes / no) | hint. Document verification, e-signatures and declarations are checked automatically.'],
        'onboarding_tasks'      => ['label' => 'Tasks assigned at joining', 'type' => 'textarea', 'rows' => 5, 'col' => 'col-md-6', 'help' => 'One per line: task | due (days after joining).'],
        'onboarding_access_items' => ['label' => 'Joining-day access checklist', 'type' => 'textarea', 'rows' => 5, 'col' => 'col-md-6', 'help' => 'One item per line.'],
    ],
    'Client onboarding' => [
        'client_signup'        => ['label' => 'Clients can register online (/client-register)', 'type' => 'checkbox', 'col' => 'col-md-6', 'help' => 'New companies start as Prospect with one signatory login and verified e-mail.'],
        'eng_email'            => ['label' => 'Client services e-mail', 'type' => 'email', 'col' => 'col-md-6', 'help' => 'Receives new requests, signatures and escalations. Defaults to the HR e-mail.'],
        'eng_hr_fee_percent'   => ['label' => 'Default recruitment fee (% of CTC)', 'type' => 'number', 'step' => '0.01', 'min' => 0, 'max' => 50, 'col' => 'col-md-3'],
        'eng_replacement_days' => ['label' => 'Default replacement guarantee (days)', 'type' => 'number', 'min' => 0, 'max' => 365, 'col' => 'col-md-3'],
        'eng_advance_percent'  => ['label' => 'Suggested advance (% of estimate)', 'type' => 'number', 'min' => 0, 'max' => 100, 'col' => 'col-md-3'],
        'eng_reminder_days'    => ['label' => 'Remind clients every (days)', 'type' => 'number', 'min' => 1, 'max' => 30, 'col' => 'col-md-3', 'help' => 'Unsigned documents and missing information (max 3 reminders).'],
        'eng_job_visibility'   => ['label' => 'Activated client vacancies are visible to', 'type' => 'select', 'options' => JOB_VISIBILITY, 'col' => 'col-md-6', 'help' => 'Confidential requests are always internal only.'],
        'eng_hr_sac'           => ['label' => 'SAC for recruitment invoices', 'col' => 'col-md-3', 'maxlength' => 8, 'help' => '998512 = permanent placement. Confirm with your CA.'],
        'eng_retention_months' => ['label' => 'Keep request attachments for (months after closure)', 'type' => 'number', 'min' => 12, 'max' => 240, 'col' => 'col-md-3'],
        'eng_jurisdiction'     => ['label' => 'Courts / jurisdiction in agreements', 'col' => 'col-md-6', 'placeholder' => 'Noida, Uttar Pradesh'],
    ],
    'Pricing & payments' => [
        'price_light'      => ['label' => 'Light: ₹ per user / month', 'type' => 'number', 'min' => 1, 'step' => '0.01', 'col' => 'col-md-4'],
        'price_standard'   => ['label' => 'Standard: ₹ per user / month', 'type' => 'number', 'min' => 1, 'step' => '0.01', 'col' => 'col-md-4'],
        'price_pro'        => ['label' => 'Pro: ₹ per user / month', 'type' => 'number', 'min' => 1, 'step' => '0.01', 'col' => 'col-md-4'],
        'annual_discount'  => ['label' => 'Annual billing discount (%)', 'type' => 'number', 'min' => 0, 'max' => 90, 'col' => 'col-md-3'],
        'min_users'        => ['label' => 'Minimum users per order', 'type' => 'number', 'min' => 1, 'col' => 'col-md-3'],
        'max_online_users' => ['label' => 'Max users online (more = sales)', 'type' => 'number', 'min' => 1, 'col' => 'col-md-3'],
        'gst_percent'      => ['label' => 'GST (%)', 'type' => 'number', 'min' => 0, 'max' => 28, 'step' => '0.01', 'col' => 'col-md-3'],
        'invoice_sac'      => ['label' => 'SAC code on invoices', 'col' => 'col-md-4', 'help' => 'Confirm with your CA (SaaS is commonly 998315 / 997331).'],
        'sales_email'      => ['label' => 'Sales e-mail (new orders & payments)', 'type' => 'email', 'col' => 'col-md-4', 'help' => 'Defaults to the HR e-mail.'],
        'razorpay_key_id'  => ['label' => 'Razorpay key ID', 'col' => 'col-md-4', 'placeholder' => 'rzp_live_… or rzp_test_…'],
        'razorpay_key_secret' => ['label' => 'Razorpay key secret', 'type' => 'password', 'col' => 'col-md-4'],
        'razorpay_webhook_secret' => ['label' => 'Razorpay webhook secret', 'type' => 'password', 'col' => 'col-md-4'],
    ],
    'Support desk' => [
        'support_email'      => ['label' => 'Support team e-mail', 'type' => 'email', 'col' => 'col-md-6', 'help' => 'Notified about every new ticket. Defaults to the HR e-mail.'],
        'ticket_prefix'      => ['label' => 'Ticket number prefix', 'col' => 'col-md-6', 'maxlength' => 8, 'help' => 'e.g. TKT → TKT-00001'],
        'sla_hours_Critical' => ['label' => 'SLA: Critical (hours)', 'type' => 'number', 'min' => 1, 'col' => 'col-md-3'],
        'sla_hours_High'     => ['label' => 'SLA: High (hours)', 'type' => 'number', 'min' => 1, 'col' => 'col-md-3'],
        'sla_hours_Medium'   => ['label' => 'SLA: Medium (hours)', 'type' => 'number', 'min' => 1, 'col' => 'col-md-3'],
        'sla_hours_Low'      => ['label' => 'SLA: Low (hours)', 'type' => 'number', 'min' => 1, 'col' => 'col-md-3'],
    ],
];
$days = ['0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday'];

if (is_post()) {
    foreach ($fields as $group) {
        foreach ($group as $k => $f) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if (!empty($f['required']) && $v === '') continue;
            if (($f['type'] ?? '') === 'password' && $v === '') continue; // blank = keep the saved secret
            if ($k === 'whatsapp_number') $v = preg_replace('/\D+/', '', $v); // wa.me needs digits only
            if ($k === 'razorpay_key_id' && $v !== '' && !preg_match('/^rzp_(test|live)_[A-Za-z0-9]{6,}$/', $v)) { flash('danger', 'The Razorpay key ID should look like rzp_live_XXXXXXXX or rzp_test_XXXXXXXX. Not saved.'); continue; }
            if ($k === 'company_gstin' && $v !== '' && !preg_match(GSTIN_PATTERN, strtoupper($v))) { flash('danger', 'The company GSTIN format is not valid. Not saved.'); continue; }
            if ($k === 'company_gstin') $v = strtoupper($v);
            if ($k === 'default_login_password' && $v !== '' && strlen($v) < 8) { flash('danger', 'The default login password must be at least 8 characters. Not saved.'); continue; }
            if ($k === 'face_punch' && !in_array($v, ['off', 'optional', 'required'], true)) $v = 'off';
            if ($k === 'face_match_threshold') $v = is_numeric($v) ? number_format(max(0.35, min(0.65, (float) $v)), 2, '.', '') : '0.50';
            if (($k === 'office_lat' || $k === 'office_lng') && $v !== '' && (!is_numeric($v) || abs((float) $v) > ($k === 'office_lat' ? 90 : 180))) { flash('danger', 'The office ' . ($k === 'office_lat' ? 'latitude' : 'longitude') . ' is not valid. Not saved.'); continue; }
            if ($k === 'geofence_radius') $v = is_numeric($v) && (int) $v > 0 ? (string) min(50000, (int) $v) : '';
            if (in_array($k, ['price_light', 'price_standard', 'price_pro'], true) && (float) $v <= 0) continue;
            if ($k === 'onboarding_activation' && !in_array($v, ['approval', 'joining'], true)) $v = 'approval';
            if (str_starts_with($k, 'rdx_cost_') || in_array($k, ['rdx_export_max', 'rdx_daily_unlock_limit'], true)) $v = (string) max(0, (int) $v);
            if ($k === 'rdx_gst_percent') $v = is_numeric($v) ? (string) max(0, min(28, (float) $v)) : '18';
            if ($k === 'rdx_expiry_reminder_days') $v = implode(',', array_filter(array_map('intval', explode(',', $v)), fn($d) => $d > 0 && $d <= 60)) ?: '7,1';
            if ($k === 'default_phone_country' && !isset(PHONE_COUNTRIES[$v])) $v = 'IN';
            if ($k === 'rdx_consent_mode' && !in_array($v, ['not_withdrawn', 'given_only'], true)) $v = 'not_withdrawn';
            if ($k === 'lock_days') $v = (string) max(1, min(365, (int) $v ?: 30));
            if ($k === 'lock_reminder_days') $v = (string) max(0, min(30, (int) $v));
            if ($k === 'lock_max_extension_days') $v = (string) max(1, min(180, (int) $v ?: 30));
            if ($k === 'lock_hold_stages') $v = implode(',', array_values(array_intersect(array_map('trim', explode(',', $v)), array_diff(CANDIDATE_STAGES, ['Applied', 'Hired', 'Rejected'])))) ?: 'Shortlisted,HR Screening,Interview,Client Interview,Selected,Offered';
            if ($k === 'vendor_score_labels') { $parts = array_slice(array_values(array_filter(array_map('trim', explode('|', $v)))), 0, 3); $v = count($parts) === 3 ? implode('|', array_map(fn($x) => mb_substr($x, 0, 30), $parts)) : 'Excellent|Good|Better'; }
            if (in_array($k, ['onboarding_documents', 'onboarding_compliance'], true) && !onb_checklist_from_text($v)) { flash('danger', 'The ' . strtolower($f['label']) . ' list is empty or not in the "key | label | required" format. Not saved.'); continue; }
            set_setting($k, mb_substr($v, 0, ($f['type'] ?? '') === 'textarea' && str_starts_with($k, 'onboarding_') ? 5000 : 1000));
        }
    }
    $weekend = array_values(array_intersect(array_keys($days), (array) ($_POST['weekend_days'] ?? [])));
    set_setting('weekend_days', implode(',', $weekend));
    try {
        if ($logo = store_upload('company_logo', 'logos', 2)) set_setting('company_logo', $logo);
    } catch (RuntimeException $ex) {
        flash('danger', e($ex->getMessage()));
    }
    if (input('remove_logo')) set_setting('company_logo', '');
    log_activity('settings_updated', 'settings');
    flash('success', 'Settings saved.');
    redirect(url('settings'));
}

page_start('Settings', ['Settings']);
$weekend = weekend_days();
?>
<form method="post" enctype="multipart/form-data" class="vstack gap-3" style="max-width:100%">
  <?= csrf_field() ?>
  <?php foreach ($fields as $group => $list): ?>
    <div class="card" id="<?= e(strtolower(strtok($group, ' '))) ?>"><div class="card-header-lite"><?= e($group) ?></div><div class="card-body"><div class="row g-3">
      <?php foreach ($list as $k => $f) {
          if (($f['type'] ?? '') === 'password') { $f['placeholder'] = setting($k) ? '•••••••• saved — leave blank to keep' : 'Not set'; echo form_field($k, $f, ''); continue; }
          echo form_field($k, $f, setting($k, ''));
      } ?>
      <?php if ($group === 'Pricing & payments'): ?><div class="col-12 small text-muted"><i class="ri-information-line"></i> Prices show on the website pricing page and checkout. Webhook URL for Razorpay: <code><?= e(site_origin() . root_url('razorpay-webhook')) ?></code>. Keys in <code>app/config.php</code> (razorpay_key_id / razorpay_key_secret / razorpay_webhook_secret) take precedence over these fields.</div><?php endif; ?>
      <?php if ($group === 'Company'): ?>
        <div class="col-md-6"><label class="form-label">Logo</label><input class="form-control" type="file" name="company_logo" accept="image/*">
          <?php if ($logo = setting('company_logo')): ?><div class="d-flex align-items-center gap-2 mt-2"><img src="<?= e(file_url($logo)) ?>" style="height:40px" class="border rounded p-1" alt="">
            <label class="form-check-label small"><input class="form-check-input" type="checkbox" name="remove_logo" value="1"> Remove</label></div><?php endif; ?></div>
      <?php endif; ?>
      <?php if ($group === 'Working hours & attendance'): ?>
        <div class="col-12"><label class="form-label">Weekly off days</label><div class="d-flex flex-wrap gap-3">
          <?php foreach ($days as $k => $d): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="weekend_days[]" value="<?= $k ?>" <?= in_array((int) $k, $weekend, true) ? 'checked' : '' ?>> <?= $d ?></label><?php endforeach; ?>
        </div><div class="form-text">Used for working-day counts in leave, attendance and payroll.</div></div>
        <div class="col-12"><button type="button" class="btn btn-sm btn-light border" data-here><i class="ri-crosshair-2-line me-1"></i>Use my current location as the office</button>
          <span class="small text-muted ms-2" data-here-msg>Stand in the office when you click this.</span></div>
        <script>
        (function () {
          var b = document.querySelector('[data-here]'), m = document.querySelector('[data-here-msg]');
          if (!b) return;
          b.addEventListener('click', function () {
            if (!navigator.geolocation) { m.textContent = 'This browser cannot share its location.'; return; }
            m.textContent = 'Getting your location…';
            navigator.geolocation.getCurrentPosition(function (p) {
              document.getElementById('f_office_lat').value = p.coords.latitude.toFixed(6);
              document.getElementById('f_office_lng').value = p.coords.longitude.toFixed(6);
              var r = document.getElementById('f_geofence_radius'); if (!r.value) r.value = 200;
              m.textContent = 'Filled in (accuracy ±' + Math.round(p.coords.accuracy) + ' m). Save settings to apply.';
            }, function (e) { m.textContent = 'Could not get your location: ' + e.message; }, { enableHighAccuracy: true, timeout: 15000 });
          });
        })();
        </script>
      <?php endif; ?>
    </div></div></div>
  <?php endforeach; ?>
  <div class="card"><div class="card-header-lite">Quick links</div><div class="card-body d-flex flex-wrap gap-2">
    <a class="btn btn-light border" href="<?= url('leave_types') ?>"><i class="ri-list-settings-line me-1"></i>Leave types</a>
    <a class="btn btn-light border" href="<?= url('holidays') ?>"><i class="ri-sun-line me-1"></i>Holidays</a>
    <a class="btn btn-light border" href="<?= url('departments') ?>"><i class="ri-building-2-line me-1"></i>Departments</a>
    <a class="btn btn-light border" href="<?= url('users') ?>"><i class="ri-shield-user-line me-1"></i>Users & roles</a>
    <a class="btn btn-light border" href="<?= url('assistant', ['a' => 'admin']) ?>"><i class="ri-robot-2-line me-1"></i>AI assistant</a>
    <a class="btn btn-light border" href="<?= url('kb') ?>"><i class="ri-book-open-line me-1"></i>Knowledge base</a>
  </div></div>
  <div class="d-flex justify-content-end"><button class="btn btn-primary btn-lg"><i class="ri-save-3-line me-1"></i>Save settings</button></div>
</form>
<?php
page_end();
