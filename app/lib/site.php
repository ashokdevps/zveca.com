<?php
/**
 * Website content manager (schema v25): every block of the public home page is editable by the Super Admin
 * (Administration → Website). Content lives in hrm_site_content as JSON per section (draft + live); anything not edited
 * falls back to site_defaults(), which reproduces the original page, so a fresh install or a new field never shows a gap.
 *   site_content($draft)   merged content for index.php (live, or the draft for a signed-in Super Admin preview)
 *   site_schema()          field definitions used to build the editor forms (app/modules/site.php)
 * Loaded by index.php with only helpers.php + db.php: no user / session functions are used here.
 */

/** Sections that make up the page body, in their default order (each can be hidden or moved). */
const SITE_BODY = ['hero' => 'Hero banner', 'marquee' => 'Module ticker', 'stats' => 'Key numbers', 'tour' => 'Product tour', 'lifecycle' => 'Employee lifecycle',
    'features' => 'Features', 'pricing' => 'Pricing', 'roles' => 'Role workspaces', 'security' => 'Security & trust', 'services' => 'Services', 'about' => 'About & testimonials',
    'faq' => 'FAQ', 'cta' => 'Call to action', 'contact' => 'Contact'];
/** Sections that are always present (site-wide settings). */
const SITE_GLOBAL = ['general' => 'Company & contact', 'seo' => 'SEO & sharing', 'announce' => 'Announcement bar', 'nav' => 'Header menu', 'footer' => 'Footer'];

function site_defaults(): array
{
    return [
        'general' => ['company' => 'Zveca', 'product' => 'Zveca HRMS', 'site_url' => 'https://zveca.com', 'phone' => '+91-9871864942', 'whatsapp' => '919871864942',
            'email' => 'info@adorableit.com', 'address' => '1st Floor, B-135, B Block, Sector 2, Noida, Uttar Pradesh 201301',
            'map_url' => 'https://maps.google.com/?q=B-135,+B+Block,+Sector+2,+Noida,+Uttar+Pradesh+201301',
            'demo_message' => 'Hello Zveca, I would like a demo of the Zveca HRMS portal for my company.',
            'chat_message' => 'Hello Zveca, I would like to know more about the HRMS portal / your HR & IT services.', 'logo' => '', 'footer_logo' => '', 'show_chat' => true],
        'seo' => ['title' => 'Zveca HRMS — HR, Payroll, Performance & Hiring Platform | Zveca',
            'description' => 'Zveca HRMS: attendance, leave, payroll, performance reviews, probation tracking, recruitment and employee self-service in one secure portal by Zveca, Noida.',
            'og_title' => 'Zveca HRMS — one people platform, from hire to retire', 'og_description' => 'Attendance, payroll, performance, probation, hiring and an AI assistant, connected in one portal.',
            'og_image' => '', 'theme_color' => '#07142a'],
        'announce' => ['enabled' => true, 'pill' => 'New', 'text' => 'Performance reviews, goals & probation tracking are now live in the portal.', 'link_label' => "See what's new", 'link_url' => '#tour'],
        'nav' => ['links' => [['label' => 'Product', 'href' => '#tour'], ['label' => 'Features', 'href' => '#features'], ['label' => 'Pricing', 'href' => '#pricing'], ['label' => 'Security', 'href' => '#security'],
            ['label' => 'Services', 'href' => '#services'], ['label' => 'Careers', 'href' => 'find-jobs'], ['label' => 'Contact', 'href' => '#contact']], 'jobs_button' => true, 'jobs_label' => 'Find jobs'],
        'hero' => ['eyebrow' => 'The people platform by Zveca', 'title' => 'Run your entire workforce', 'highlight' => 'from hire to retire.',
            'lead' => 'Attendance, payroll, performance, probation, hiring and an AI assistant, connected in one secure portal that works on every device.',
            'cta_guest' => 'Login to portal', 'cta_user' => 'Open your dashboard', 'demo_label' => 'Book a free demo', 'demo_url' => '',
            'checks' => [['icon' => 'ri-shield-check-fill', 'text' => 'Role-based security'], ['icon' => 'ri-smartphone-line', 'text' => 'Mobile-ready'], ['icon' => 'ri-map-pin-2-fill', 'text' => 'Built in India']],
            'image' => '',
            'floats' => [['icon' => 'ri-checkbox-circle-line', 'title' => 'Probation confirmed', 'sub' => 'Priya Sharma · letter issued'], ['icon' => 'ri-medal-2-line', 'title' => 'Kudos: Team player', 'sub' => '"Thanks for the release!"'],
                ['icon' => 'ri-fingerprint-line', 'title' => 'Punched in 09:18 AM', 'sub' => 'Noida office · on time']]],
        'marquee' => ['items' => array_map(fn($t) => ['text' => $t], ['Core HR', 'Punch In / Out', 'Attendance', 'Leave', 'Holidays', 'Payroll', 'Payslips', 'Expenses', 'Performance Reviews', 'Goals & KPIs', 'Probation',
            'Kudos', 'Recruitment ATS', 'HireFlow', 'Resdex Search', 'Careers Page', 'Sub-Vendor Portal', 'Candidate Portal', 'Client Portal', 'Projects & Tasks', 'Training', 'Helpdesk', 'Knowledge Base', 'AI Assistant', 'Reports', 'Audit Log'])],
        'stats' => ['items' => [['value' => '25', 'suffix' => '+', 'label' => 'Connected modules', 'animate' => true], ['value' => '8', 'suffix' => '', 'label' => 'Role-based workspaces', 'animate' => true],
            ['value' => '125', 'suffix' => '+', 'label' => 'People at Zveca', 'animate' => true], ['value' => '24×7', 'suffix' => '', 'label' => 'Self-service & AI help', 'animate' => false]]],
        'tour' => ['kicker' => 'Product tour', 'title' => 'One platform. Every HR moment.', 'text' => 'Data entered once flows everywhere: attendance into payroll, candidates into employees, goals into reviews.', 'button' => 'Try it in the portal',
            'tabs' => [
                ['icon' => 'ri-team-line', 'label' => 'Core HR', 'title' => 'One employee record that every module shares', 'points' => "Employee profiles, documents and reporting lines\nDepartments, designations and levels\nOffboarding checklist and inactive-employee history\nCompany directory, announcements and celebrations",
                    'rows' => "ri-user-add-line | Priya Sharma joined | Engineering · Senior Developer | New\nri-file-text-line | Offer letter uploaded | Documents · PDF | Saved\nri-user-unfollow-line | Exit processed | Login disabled · tasks reassigned | Done"],
                ['icon' => 'ri-fingerprint-line', 'label' => 'Time & Leave', 'title' => 'Punch in from any device, approve leave in one tap', 'points' => "Punch in / out with time, device, IP and location\nLate marks, half days and work hours calculated automatically\nLeave balances, approvals and a team calendar\nHoliday calendar and weekly-off rules",
                    'rows' => "ri-login-circle-line | Punched in 09:18 AM | Noida office · Chrome | On time\nri-calendar-check-line | Casual leave · 2 days | Rahul Verma · approved by manager | Approved\nri-time-line | Worked today | 8h 06m logged | Live"],
                ['icon' => 'ri-money-rupee-circle-line', 'label' => 'Payroll', 'title' => 'Accurate, attendance-linked payroll every month', 'points' => "Salary structures with PF, PT, TDS and deductions\nLoss-of-pay pulled straight from attendance\nPayroll runs with review before finalising\nPrintable payslips in every employee's workspace",
                    'rows' => "ri-bank-card-line | September payroll | 42 employees · finalised | Paid\nri-wallet-3-line | Payslip ready | Net pay ₹68,420 | New\nri-receipt-line | Expense reimbursed | Travel · ₹2,350 | Done"],
                ['icon' => 'ri-line-chart-line', 'label' => 'Performance', 'title' => 'Goals, reviews and probation that people actually complete', 'points' => "Goals & KPIs with weights, targets and live progress\nSelf-review → manager review → acknowledgement\nProbation: junior 6 months, senior 3 months, with confirm / extend decisions\nKudos wall, calibration and printable confirmation letters",
                    'rows' => "ri-hourglass-2-line | Probation ends in 12 days | Junior profile · review due | Due\nri-star-smile-line | Annual review completed | Rating 4.5 · Outstanding | Done\nri-medal-2-line | Kudos: Team player | From Ashok · \"Thanks for the release!\" | New"],
                ['icon' => 'ri-user-search-line', 'label' => 'Hiring', 'title' => 'From job post to joining, with clients and vendors in the loop', 'points' => "Careers page with Quick Apply and Google-for-Jobs markup\nPipeline: screening, interviews, offer and joining\nHireFlow and Resdex search for your clients\nSub-vendor portal with duplicate protection",
                    'rows' => "ri-briefcase-4-line | PHP Developer | 24 applications · 6 shortlisted | Open\nri-calendar-event-line | Client interview | Tomorrow 11:00 AM | Scheduled\nri-shake-hands-line | Offer accepted | Joining 1 Nov | Hired"],
                ['icon' => 'ri-sparkling-2-line', 'label' => 'AI & Helpdesk', 'title' => 'Answers in seconds, tickets when people need a human', 'points' => "AI assistant that knows every feature and the user's own data\nHelpdesk with SLA timers, internal notes and ratings\nSelf-service knowledge base\nRole-aware: people only see what they are allowed to",
                    'rows' => "ri-question-answer-line | \"How many leaves do I have?\" | AI: 7 casual, 5 sick remaining | Answered\nri-customer-service-2-line | TKT-00042 · Payslip query | Resolved in 2h 10m | Resolved\nri-book-open-line | How to apply for WFH | Knowledge base · 128 views | Article"],
            ]],
        'lifecycle' => ['kicker' => 'Employee lifecycle', 'title' => 'Every stage, connected', 'text' => "Follow each person from the day they apply to the day they leave, and back again if they're eligible for rehire.",
            'steps' => [['icon' => 'ri-user-search-line', 'title' => 'Hire', 'text' => 'Careers page, pipeline, offer'], ['icon' => 'ri-user-add-line', 'title' => 'Onboard', 'text' => 'Profile, login, documents'],
                ['icon' => 'ri-hourglass-2-line', 'title' => 'Probation', 'text' => '6 / 3 months, then confirm'], ['icon' => 'ri-line-chart-line', 'title' => 'Perform', 'text' => 'Goals, reviews, kudos'],
                ['icon' => 'ri-wallet-3-line', 'title' => 'Pay', 'text' => 'Attendance-linked payroll'], ['icon' => 'ri-door-open-line', 'title' => 'Exit', 'text' => 'Offboarding & rehire']],
            'spot_pill' => 'New', 'spot_title' => 'Probation that runs itself', 'spot_text' => 'The end date is set from the designation level the moment someone joins. The countdown appears on their dashboard, the manager is prompted before it ends, and HR confirms or extends in one click.',
            'cards' => [['icon' => 'ri-user-line', 'value' => '6 months', 'label' => 'Junior profiles', 'sub' => 'Intern · Junior · Mid'], ['icon' => 'ri-user-star-line', 'value' => '3 months', 'label' => 'Senior profiles', 'sub' => 'Senior · Lead · Manager+']]],
        'features' => ['kicker' => 'Features', 'title' => "Everything HR needs, nothing it doesn't", 'text' => 'Purpose-built modules that share one employee record and one login.',
            'items' => [
                ['icon' => 'ri-fingerprint-line', 'title' => 'Punch In / Out', 'text' => 'Every punch saved with time, device, IP and location. Hours and late marks are automatic.', 'style' => 'wide'],
                ['icon' => 'ri-calendar-check-line', 'title' => 'Leave & Holidays', 'text' => 'Balances, one-click requests, manager approvals and a team calendar.', 'style' => ''],
                ['icon' => 'ri-money-rupee-circle-line', 'title' => 'Payroll & Payslips', 'text' => 'PF, PT, TDS and loss-of-pay from attendance. Printable payslips.', 'style' => ''],
                ['icon' => 'ri-line-chart-line', 'title' => 'Performance Reviews', 'text' => 'Review cycles, weighted goals, competencies and HR calibration.', 'style' => 'new'],
                ['icon' => 'ri-hourglass-2-line', 'title' => 'Probation Tracking', 'text' => 'Junior 6 months, senior 3 months. Countdown, reviews, confirm or extend.', 'style' => 'new'],
                ['icon' => 'ri-flow-chart', 'title' => 'HireFlow & Resdex', 'text' => 'Clients post jobs, screen applicants and search the talent pool.', 'style' => 'wide'],
                ['icon' => 'ri-user-search-line', 'title' => 'Recruitment & ATS', 'text' => 'Jobs, candidates, resumes and a drag-and-drop pipeline.', 'style' => ''],
                ['icon' => 'ri-team-fill', 'title' => 'Sub-Vendor Portal', 'text' => 'Partners submit profiles and track every candidate to the offer.', 'style' => ''],
                ['icon' => 'ri-user-smile-line', 'title' => 'Employee Self-Service', 'text' => 'Profile, attendance, leave, payslips, expenses, goals and tasks.', 'style' => ''],
                ['icon' => 'ri-folder-chart-line', 'title' => 'Projects & Tasks', 'text' => 'Plan projects, assign teams and track progress against deadlines.', 'style' => ''],
                ['icon' => 'ri-customer-service-2-line', 'title' => 'Helpdesk & Knowledge Base', 'text' => 'SLA timers, internal notes, satisfaction ratings and articles.', 'style' => 'wide'],
                ['icon' => 'ri-sparkling-2-line', 'title' => 'AI Assistant', 'text' => 'Ask anything about the portal or your own data. Role-aware and private.', 'style' => 'wide'],
            ]],
        'pricing' => ['kicker' => 'Pricing', 'title' => 'Simple per-user pricing', 'text' => 'Pay only for active employees. Pick a plan, set your team size and see the exact price.', 'link_label' => 'Compare every module across plans'],
        'roles' => ['kicker' => 'Role-based workspaces', 'title' => 'The right workspace for every person', 'text' => 'Each login opens a dashboard built for its role, and sees only what it should. The Super Admin can fine-tune module access per user.',
            'button_guest' => 'Sign in', 'button_user' => 'Open dashboard',
            'items' => [['icon' => 'ri-shield-star-line', 'title' => 'Super Admin', 'text' => 'Users, roles, module access, settings and the audit log.'], ['icon' => 'ri-shield-user-line', 'title' => 'HR Manager', 'text' => 'Employees, attendance, payroll, recruitment, probation and reports.'],
                ['icon' => 'ri-user-star-line', 'title' => 'Team Manager', 'text' => 'Team attendance, leave and expense approvals, reviews for direct reports.'], ['icon' => 'ri-user-line', 'title' => 'Employee', 'text' => 'Punch in, leave, payslips, expenses, goals, reviews and kudos.'],
                ['icon' => 'ri-customer-service-2-line', 'title' => 'Support Agent', 'text' => 'Ticket queue with SLAs, internal notes and the knowledge base.'], ['icon' => 'ri-building-line', 'title' => 'Client', 'text' => 'Projects, invoices, hiring feedback and HireFlow, if granted.'],
                ['icon' => 'ri-team-fill', 'title' => 'Sub-Vendor', 'text' => 'Shared requirements, profile submissions and live status.'], ['icon' => 'ri-user-search-line', 'title' => 'Candidate', 'text' => 'One profile, one-click apply and application tracking.']]],
        'security' => ['kicker' => 'Security & trust', 'title' => 'Your people data, locked down', 'text' => 'Security is built into every request, not bolted on.',
            'items' => [['icon' => 'ri-lock-password-line', 'title' => 'Hashed passwords & lockout', 'text' => 'Passwords are stored as one-way hashes. Repeated wrong attempts lock the account and IP for 15 minutes.'],
                ['icon' => 'ri-shield-keyhole-line', 'title' => 'Role-based access', 'text' => 'Every page checks permissions on the server. Managers see their team; clients and vendors see only their own records.'],
                ['icon' => 'ri-file-shield-2-line', 'title' => 'Private documents', 'text' => 'Resumes, payslips and documents are served only to signed-in users who may see them, never from a public folder.'],
                ['icon' => 'ri-spy-line', 'title' => 'Hardened web layer', 'text' => 'Content-Security-Policy, clickjacking protection, CSRF tokens on every form and upload content checks.'],
                ['icon' => 'ri-history-line', 'title' => 'Full audit trail', 'text' => 'Who changed what and when: logins, approvals, payroll and record edits are logged.'],
                ['icon' => 'ri-smartphone-line', 'title' => 'Works on every device', 'text' => 'Responsive on phones, tablets and desktops, with a bottom bar for one-thumb use on mobile.']]],
        'services' => ['kicker' => 'Services', 'title' => 'HR & IT expertise under one roof', 'text' => 'Beyond software, our HR and technology teams help organisations hire, manage and grow their workforce.',
            'items' => [['icon' => 'ri-team-line', 'title' => 'Recruitment & Staffing', 'text' => 'Sourcing, screening, interviews and onboarding for permanent and contract roles.'],
                ['icon' => 'ri-line-chart-line', 'title' => 'HR Technology & Analytics', 'text' => 'HR software implementation, workforce analytics and process automation.'],
                ['icon' => 'ri-medal-line', 'title' => 'Performance Management', 'text' => 'Review systems, KPI & OKR frameworks, continuous feedback and improvement plans.'],
                ['icon' => 'ri-graduation-cap-line', 'title' => 'Training & Development', 'text' => 'Skill development, leadership training, upskilling and compliance programmes.'],
                ['icon' => 'ri-wallet-3-line', 'title' => 'Payroll Management', 'text' => 'Accurate salary processing, statutory compliance and attendance-integrated payroll.'],
                ['icon' => 'ri-hand-coin-line', 'title' => 'Compensation & Benefits', 'text' => 'Salary benchmarking, benefits planning, incentives and retention strategies.']],
            'it_title' => 'Tech Platform', 'it_text' => 'IT services that power your digital path to success',
            'it_items' => [['icon' => 'ri-code-s-slash-line', 'title' => 'Website Design & Development'], ['icon' => 'ri-palette-line', 'title' => 'UI / UX Designing'], ['icon' => 'ri-smartphone-line', 'title' => 'Mobile App Development'],
                ['icon' => 'ri-pen-nib-line', 'title' => 'Graphic Designing'], ['icon' => 'ri-stack-line', 'title' => 'ERP & CRM Development'], ['icon' => 'ri-megaphone-line', 'title' => 'Digital Marketing'], ['icon' => 'ri-cloud-line', 'title' => 'Cloud & DevOps']],
            'quote_label' => 'Request a quote', 'quote_url' => '#contact'],
        'about' => ['kicker' => 'About Zveca', 'title' => 'Guided by integrity, innovation and customer-centricity',
            'text' => 'We deliver high-calibre IT services and strategic HR solutions so businesses achieve digital expansion, workforce efficiency and operational success.',
            'team' => [['name' => 'Rekha Gaur', 'role' => 'Co-Founder'], ['name' => 'Trupti Patro', 'role' => 'HR Head'], ['name' => 'Pramod Kumar', 'role' => 'Administrative Officer'], ['name' => 'Arvind Kumar', 'role' => 'IT Technical Support Head']],
            'testimonials' => [['name' => 'Mr. Ankush Bindra', 'role' => 'CEO, ARK Event Studio', 'quote' => 'Exceptional IT service! Our company experienced a seamless transition to new software, thanks to their expertise.', 'rating' => '5'],
                ['name' => 'Mr. Pramod Kumar', 'role' => 'Event Manager, RN DJ', 'quote' => 'Zveca exceeded expectations! Their expertise, promptness, and personalized solutions are commendable. A true delight to work with!', 'rating' => '5'],
                ['name' => 'Trupti Mahanty', 'role' => 'HR Head, Zveca', 'quote' => 'Their team is incredibly knowledgeable, responsive, and truly committed to delivering high-quality solutions.', 'rating' => '5']]],
        'faq' => ['kicker' => 'FAQ', 'title' => 'Questions, answered',
            'items' => [['q' => 'How do employees get access?', 'a' => 'HR adds the employee and ticks "Create login". A temporary password is e-mailed, and the employee signs in from the Login button and changes it.'],
                ['q' => 'How does the probation period work?', 'a' => 'Probation is set automatically from the designation level: junior profiles get 6 months and senior profiles 3 months from the joining date (both configurable). Managers complete a probation review before the end date and HR confirms, extends or separates. Confirmation issues a printable letter.'],
                ['q' => 'Can employees use it on their phone?', 'a' => 'Yes. Every page is responsive, and on phones a bottom bar gives one-tap access to Punch, Leaves and Payslips. No app install is needed.'],
                ['q' => 'How are performance ratings calculated?', 'a' => 'Managers rate weighted goals and seven competencies on a 1–5 scale. By default the overall rating is 60% goals and 40% competencies, and HR can calibrate it before the employee acknowledges the review.'],
                ['q' => 'Can our clients and staffing vendors use it?', 'a' => 'Yes. Clients get their own portal (projects, invoices, hiring and HireFlow) and sub-vendors get a submission portal. Each only sees their own records.'],
                ['q' => 'Is our data secure?', 'a' => 'Access is role-based and checked on the server, passwords are hashed, failed logins are throttled, documents are private and every important change is written to an audit log.']]],
        'cta' => ['title' => 'Ready to modernise HR?', 'text' => 'Log in to your workspace, or book a free walkthrough for your company.', 'button_guest' => 'Login to Portal', 'button_user' => 'Go to Dashboard', 'demo_label' => 'Book a demo'],
        'contact' => ['kicker' => 'Contact', 'title' => 'Talk to our team', 'text' => "Need portal access, a demo for your company, or HR / IT services? We're happy to help."],
        'footer' => ['text' => 'We make your digital path to success — IT services and HR solutions for businesses of every size.',
            'col1' => 'Portal', 'links1' => [['label' => 'Login', 'href' => 'sign-in'], ['label' => 'Dashboard', 'href' => 'dashboard/'], ['label' => 'Forgot password', 'href' => 'sign-in?view=forgot'], ['label' => 'Find jobs', 'href' => 'find-jobs'], ['label' => 'Pricing', 'href' => 'pricing'], ['label' => 'FAQ', 'href' => '#faq']],
            'col2' => 'Company', 'links2' => [['label' => 'About us', 'href' => '#about'], ['label' => 'HR Platform', 'href' => '#features'], ['label' => 'Tech Platform', 'href' => '#services'],
                ['label' => 'Terms of service', 'href' => 'terms'], ['label' => 'Privacy policy', 'href' => 'privacy'], ['label' => 'Refund policy', 'href' => 'refund-policy']],
            'col3' => 'Get in touch',
            'socials' => [['icon' => 'ri-linkedin-fill', 'label' => 'LinkedIn', 'url' => 'https://www.linkedin.com/company/adorable-it-services/'], ['icon' => 'ri-facebook-fill', 'label' => 'Facebook', 'url' => 'https://www.facebook.com/people/Adorable-It-Services/61558386194972/'],
                ['icon' => 'ri-instagram-line', 'label' => 'Instagram', 'url' => 'https://www.instagram.com/adorableitservices/'], ['icon' => 'ri-twitter-x-line', 'label' => 'X', 'url' => 'https://x.com/AdorableIT05'],
                ['icon' => 'ri-youtube-fill', 'label' => 'YouTube', 'url' => 'https://www.youtube.com/@adorableitservices'], ['icon' => 'ri-whatsapp-line', 'label' => 'WhatsApp', 'url' => 'https://wa.me/919871864942']],
            'copyright' => '© {year} Zveca. All rights reserved.'],
        'layout' => ['order' => array_keys(SITE_BODY), 'hidden' => []],
    ];
}

/** Field definitions for the editor: [key => [label, type, extra]]. Types: text, textarea, url, icon, image, bool, select, color, list. */
function site_schema(): array
{
    $ic = ['icon', 'Icon', 'icon'];
    $head = ['kicker' => ['Small heading', 'text'], 'title' => ['Heading', 'text'], 'text' => ['Intro text', 'textarea']];
    return [
        'general' => ['company' => ['Company name', 'text'], 'product' => ['Product name', 'text'], 'site_url' => ['Company website', 'url'], 'phone' => ['Phone', 'text'],
            'whatsapp' => ['WhatsApp number (country code, digits only)', 'text', ['pattern' => '\d{8,15}']], 'email' => ['E-mail', 'text'], 'address' => ['Address', 'textarea'],
            'map_url' => ['Map link', 'url'], 'demo_message' => ['WhatsApp demo message', 'textarea'], 'chat_message' => ['WhatsApp chat button message', 'textarea'],
            'logo' => ['Header logo (PNG / JPG / WebP)', 'image'], 'footer_logo' => ['Footer logo (light, for the dark footer)', 'image'], 'show_chat' => ['Show the floating WhatsApp chat button', 'bool']],
        'seo' => ['title' => ['Browser / Google title', 'text', ['maxlength' => 70, 'counter' => 60]], 'description' => ['Meta description', 'textarea', ['maxlength' => 200, 'counter' => 160]],
            'og_title' => ['Share title (WhatsApp, LinkedIn…)', 'text'], 'og_description' => ['Share description', 'textarea'], 'og_image' => ['Share image (1200 × 630)', 'image'], 'theme_color' => ['Browser theme colour', 'color']],
        'announce' => ['enabled' => ['Show the announcement bar', 'bool'], 'pill' => ['Badge', 'text'], 'text' => ['Message', 'text'], 'link_label' => ['Link text', 'text'], 'link_url' => ['Link', 'text']],
        'nav' => ['links' => ['Menu links', 'list', ['label' => ['Label', 'text'], 'href' => ['Link (#section, page or https://…)', 'text']]], 'jobs_button' => ['Show the "Find jobs" button', 'bool'], 'jobs_label' => ['"Find jobs" button text', 'text']],
        'hero' => ['eyebrow' => ['Eyebrow', 'text'], 'title' => ['Headline', 'text'], 'highlight' => ['Highlighted part of the headline', 'text'], 'lead' => ['Sub-headline', 'textarea'],
            'cta_guest' => ['Main button (visitors)', 'text'], 'cta_user' => ['Main button (signed-in users)', 'text'], 'demo_label' => ['Second button', 'text'], 'demo_url' => ['Second button link (blank = WhatsApp demo)', 'text'],
            'checks' => ['Trust points', 'list', ['icon' => ['Icon', 'icon'], 'text' => ['Text', 'text']]], 'image' => ['Hero image (optional — replaces the dashboard illustration)', 'image'],
            'floats' => ['Floating cards (around the illustration)', 'list', ['icon' => ['Icon', 'icon'], 'title' => ['Title', 'text'], 'sub' => ['Subtitle', 'text']]]],
        'marquee' => ['items' => ['Ticker items', 'list', ['text' => ['Text', 'text']]]],
        'stats' => ['items' => ['Numbers', 'list', ['value' => ['Number / value', 'text'], 'suffix' => ['Suffix (e.g. +)', 'text'], 'label' => ['Label', 'text'], 'animate' => ['Count up', 'bool']]]],
        'tour' => $head + ['button' => ['Button text', 'text'], 'tabs' => ['Tabs', 'list', ['icon' => ['Icon', 'icon'], 'label' => ['Tab label', 'text'], 'title' => ['Panel heading', 'text'],
            'points' => ['Bullet points (one per line)', 'textarea'], 'rows' => ['Activity rows (one per line: icon | title | subtitle | tag)', 'textarea']]]],
        'lifecycle' => $head + ['steps' => ['Stages', 'list', ['icon' => ['Icon', 'icon'], 'title' => ['Title', 'text'], 'text' => ['Text', 'text']]], 'spot_pill' => ['Spotlight badge', 'text'],
            'spot_title' => ['Spotlight heading', 'text'], 'spot_text' => ['Spotlight text', 'textarea'], 'cards' => ['Spotlight cards', 'list', ['icon' => ['Icon', 'icon'], 'value' => ['Value', 'text'], 'label' => ['Label', 'text'], 'sub' => ['Small text', 'text']]]],
        'features' => $head + ['items' => ['Feature cards', 'list', ['icon' => ['Icon', 'icon'], 'title' => ['Title', 'text'], 'text' => ['Text', 'textarea'], 'style' => ['Card style', 'select', ['' => 'Normal', 'wide' => 'Wide', 'new' => 'With "New" badge']]]]],
        'pricing' => $head + ['link_label' => ['Comparison link text', 'text']],
        'roles' => $head + ['button_guest' => ['Button (visitors)', 'text'], 'button_user' => ['Button (signed-in)', 'text'], 'items' => ['Roles', 'list', ['icon' => ['Icon', 'icon'], 'title' => ['Role', 'text'], 'text' => ['Text', 'text']]]],
        'security' => $head + ['items' => ['Points', 'list', ['icon' => ['Icon', 'icon'], 'title' => ['Title', 'text'], 'text' => ['Text', 'textarea']]]],
        'services' => $head + ['items' => ['HR services', 'list', ['icon' => ['Icon', 'icon'], 'title' => ['Title', 'text'], 'text' => ['Text', 'textarea']]], 'it_title' => ['IT block heading', 'text'],
            'it_text' => ['IT block text', 'text'], 'it_items' => ['IT services', 'list', ['icon' => ['Icon', 'icon'], 'title' => ['Title', 'text']]], 'quote_label' => ['Quote button', 'text'], 'quote_url' => ['Quote link', 'url']],
        'about' => $head + ['team' => ['Team', 'list', ['name' => ['Name', 'text'], 'role' => ['Role', 'text']]],
            'testimonials' => ['Testimonials', 'list', ['name' => ['Name', 'text'], 'role' => ['Role / company', 'text'], 'quote' => ['Quote', 'textarea'], 'rating' => ['Stars', 'select', ['5' => '★★★★★', '4' => '★★★★', '3' => '★★★']]]]],
        'faq' => ['kicker' => ['Small heading', 'text'], 'title' => ['Heading', 'text'], 'items' => ['Questions', 'list', ['q' => ['Question', 'text'], 'a' => ['Answer', 'textarea']]]],
        'cta' => ['title' => ['Heading', 'text'], 'text' => ['Text', 'textarea'], 'button_guest' => ['Button (visitors)', 'text'], 'button_user' => ['Button (signed-in)', 'text'], 'demo_label' => ['Demo button', 'text']],
        'contact' => $head,
        'footer' => ['text' => ['About text', 'textarea'], 'col1' => ['Column 1 heading', 'text'], 'links1' => ['Column 1 links', 'list', ['label' => ['Label', 'text'], 'href' => ['Link', 'text']]],
            'col2' => ['Column 2 heading', 'text'], 'links2' => ['Column 2 links', 'list', ['label' => ['Label', 'text'], 'href' => ['Link', 'text']]], 'col3' => ['Contact column heading', 'text'],
            'socials' => ['Social links', 'list', ['icon' => ['Icon', 'icon'], 'label' => ['Name', 'text'], 'url' => ['Link', 'url']]], 'copyright' => ['Copyright line ({year} = current year)', 'text']],
    ];
}

/** Stored JSON for every section (live or draft). Empty when the table is missing or the database is down. */
function site_stored(bool $draft): array
{
    try {
        if (!table_exists('hrm_site_content')) return [];
        $out = [];
        foreach (all('SELECT section, draft, live FROM hrm_site_content') as $r) {
            $json = $draft ? ($r['draft'] ?? $r['live']) : $r['live'];
            $data = $json ? json_decode((string) $json, true) : null;
            if (is_array($data)) $out[$r['section']] = $data;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Page content: defaults overlaid with what the Super Admin saved (top-level keys; lists replace the whole list). */
function site_content(bool $draft = false): array
{
    $c = site_defaults();
    foreach (site_stored($draft) as $sec => $data) {
        if (isset($c[$sec])) $c[$sec] = array_replace($c[$sec], array_intersect_key($data, $c[$sec]));
    }
    $order = array_values(array_intersect($c['layout']['order'], array_keys(SITE_BODY)));
    foreach (array_keys(SITE_BODY) as $k) if (!in_array($k, $order, true)) $order[] = $k; // sections added later appear at the end
    $c['layout']['order'] = $order;
    $c['layout']['hidden'] = array_values(array_intersect((array) $c['layout']['hidden'], array_keys(SITE_BODY)));
    return $c;
}

/** Sections with saved changes that are not published yet. */
function site_pending(): array
{
    if (!table_exists('hrm_site_content')) return [];
    return array_column(all('SELECT section FROM hrm_site_content WHERE draft IS NOT NULL AND (live IS NULL OR draft <> live)'), 'section');
}

/** Only links the page can safely use: #anchors, relative paths, http(s), mailto, tel, wa.me. */
function site_href(?string $u): string
{
    $u = trim((string) $u);
    if ($u === '') return '#';
    return preg_match('#^(\#|/|[a-z0-9_-]+(\.php)?([/?\#].*)?$|https?://|mailto:|tel:)#i', $u) && !preg_match('#^\s*javascript:#i', $u) ? $u : '#';
}

/** Remix icon class only (the editor and the page never output arbitrary class names). */
function site_icon(?string $i, string $fallback = 'ri-checkbox-blank-circle-line'): string
{
    return preg_match('/^ri-[a-z0-9-]+$/', (string) $i) ? (string) $i : $fallback;
}
