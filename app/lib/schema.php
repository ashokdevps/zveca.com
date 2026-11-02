<?php
/**
 * Database schema + migrations. Runs automatically on the first request after
 * deployment (see ensure_schema()), so going live is just "upload the files".
 * Every statement is idempotent.
 */

const SCHEMA_VERSION = 25;

function schema_tables(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS it_login (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            email VARCHAR(190) NULL,
            password VARCHAR(255) NOT NULL,
            usertype VARCHAR(30) NOT NULL DEFAULT 'employee',
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            reset_otp VARCHAR(10) NULL,
            otp_expires DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_settings (
            k VARCHAR(64) PRIMARY KEY,
            v TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_departments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            code VARCHAR(20) NULL,
            head_employee_id INT NULL,
            description TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dept_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_designations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(120) NOT NULL,
            department_id INT NULL,
            level VARCHAR(40) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            emp_code VARCHAR(30) NOT NULL,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            gender VARCHAR(20) NULL,
            dob DATE NULL,
            marital_status VARCHAR(20) NULL,
            blood_group VARCHAR(5) NULL,
            address TEXT NULL,
            city VARCHAR(80) NULL,
            state VARCHAR(80) NULL,
            pincode VARCHAR(12) NULL,
            department_id INT NULL,
            designation_id INT NULL,
            manager_id INT NULL,
            employment_type VARCHAR(30) DEFAULT 'Full-time',
            work_location VARCHAR(120) NULL,
            join_date DATE NULL,
            exit_date DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Active',
            ctc_annual DECIMAL(12,2) NULL,
            pan_no VARCHAR(20) NULL,
            aadhaar_no VARCHAR(20) NULL,
            uan_no VARCHAR(20) NULL,
            bank_name VARCHAR(120) NULL,
            bank_account VARCHAR(40) NULL,
            bank_ifsc VARCHAR(20) NULL,
            emergency_name VARCHAR(120) NULL,
            emergency_phone VARCHAR(30) NULL,
            photo VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_emp_code (emp_code),
            KEY k_emp_status (status),
            FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL,
            FOREIGN KEY (designation_id) REFERENCES hrm_designations(id) ON DELETE SET NULL,
            FOREIGN KEY (manager_id) REFERENCES hrm_employees(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_employee_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            title VARCHAR(150) NOT NULL,
            file VARCHAR(255) NOT NULL,
            uploaded_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_salary_structures (
            employee_id INT PRIMARY KEY,
            basic DECIMAL(12,2) NOT NULL DEFAULT 0,
            hra DECIMAL(12,2) NOT NULL DEFAULT 0,
            conveyance DECIMAL(12,2) NOT NULL DEFAULT 0,
            medical DECIMAL(12,2) NOT NULL DEFAULT 0,
            special_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            other_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            pf_enabled TINYINT(1) NOT NULL DEFAULT 1,
            professional_tax DECIMAL(12,2) NOT NULL DEFAULT 0,
            tds DECIMAL(12,2) NOT NULL DEFAULT 0,
            other_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            date DATE NOT NULL,
            check_in DATETIME NULL,
            check_out DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Present',
            is_late TINYINT(1) NOT NULL DEFAULT 0,
            work_minutes INT NULL,
            note VARCHAR(255) NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'self',
            UNIQUE KEY uq_att (employee_id, date),
            KEY k_att_date (date),
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_leave_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            code VARCHAR(10) NOT NULL,
            annual_quota DECIMAL(5,1) NOT NULL DEFAULT 0,
            is_paid TINYINT(1) NOT NULL DEFAULT 1,
            color VARCHAR(10) DEFAULT '#6366f1',
            active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_leaves (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            leave_type_id INT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            half_day TINYINT(1) NOT NULL DEFAULT 0,
            days DECIMAL(5,1) NOT NULL,
            reason TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            approver_id INT NULL,
            approver_note VARCHAR(255) NULL,
            decided_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_leave_status (status),
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
            FOREIGN KEY (leave_type_id) REFERENCES hrm_leave_types(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_holidays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            date DATE NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'Public',
            description VARCHAR(255) NULL,
            UNIQUE KEY uq_holiday (date, title)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_payroll_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            month CHAR(7) NOT NULL,
            working_days DECIMAL(5,1) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Draft',
            total_net DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            finalized_at DATETIME NULL,
            UNIQUE KEY uq_run_month (month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_payslips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            run_id INT NOT NULL,
            employee_id INT NOT NULL,
            working_days DECIMAL(5,1) NOT NULL,
            paid_days DECIMAL(5,1) NOT NULL,
            lop_days DECIMAL(5,1) NOT NULL DEFAULT 0,
            basic DECIMAL(12,2) NOT NULL DEFAULT 0,
            hra DECIMAL(12,2) NOT NULL DEFAULT 0,
            conveyance DECIMAL(12,2) NOT NULL DEFAULT 0,
            medical DECIMAL(12,2) NOT NULL DEFAULT 0,
            special_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            other_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            gross DECIMAL(12,2) NOT NULL DEFAULT 0,
            lop_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            pf DECIMAL(12,2) NOT NULL DEFAULT 0,
            professional_tax DECIMAL(12,2) NOT NULL DEFAULT 0,
            tds DECIMAL(12,2) NOT NULL DEFAULT 0,
            other_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
            net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_slip (run_id, employee_id),
            FOREIGN KEY (run_id) REFERENCES hrm_payroll_runs(id) ON DELETE CASCADE,
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            department_id INT NULL,
            location VARCHAR(120) NULL,
            employment_type VARCHAR(30) DEFAULT 'Full-time',
            openings INT NOT NULL DEFAULT 1,
            experience VARCHAR(60) NULL,
            salary_range VARCHAR(80) NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Open',
            closing_date DATE NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_candidates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_id INT NULL,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            work_exp VARCHAR(60) NULL,
            key_skills TEXT NULL,
            current_ctc VARCHAR(60) NULL,
            expected_ctc VARCHAR(60) NULL,
            current_location VARCHAR(120) NULL,
            preferred_location VARCHAR(120) NULL,
            current_employer VARCHAR(150) NULL,
            designation VARCHAR(120) NULL,
            education VARCHAR(190) NULL,
            notice_period VARCHAR(60) NULL,
            source VARCHAR(60) NULL,
            stage VARCHAR(20) NOT NULL DEFAULT 'Applied',
            rating TINYINT NULL,
            interview_at DATETIME NULL,
            resume VARCHAR(255) NULL,
            notes TEXT NULL,
            employee_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY k_cand_stage (stage),
            FOREIGN KEY (job_id) REFERENCES hrm_jobs(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(120) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            address TEXT NULL,
            gst_no VARCHAR(30) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            client_id INT NULL,
            manager_id INT NULL,
            start_date DATE NULL,
            deadline DATE NULL,
            budget DECIMAL(14,2) NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
            status VARCHAR(20) NOT NULL DEFAULT 'Not Started',
            progress TINYINT NOT NULL DEFAULT 0,
            description TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES hrm_clients(id) ON DELETE SET NULL,
            FOREIGN KEY (manager_id) REFERENCES hrm_employees(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_project_members (
            project_id INT NOT NULL,
            employee_id INT NOT NULL,
            role VARCHAR(80) NULL,
            PRIMARY KEY (project_id, employee_id),
            FOREIGN KEY (project_id) REFERENCES hrm_projects(id) ON DELETE CASCADE,
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NULL,
            title VARCHAR(200) NOT NULL,
            assignee_id INT NULL,
            due_date DATE NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
            status VARCHAR(20) NOT NULL DEFAULT 'To Do',
            description TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES hrm_projects(id) ON DELETE CASCADE,
            FOREIGN KEY (assignee_id) REFERENCES hrm_employees(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_trainings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            trainer VARCHAR(120) NULL,
            type VARCHAR(40) NULL,
            mode VARCHAR(20) NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            cost DECIMAL(12,2) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Scheduled',
            description TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_training_participants (
            training_id INT NOT NULL,
            employee_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Enrolled',
            PRIMARY KEY (training_id, employee_id),
            FOREIGN KEY (training_id) REFERENCES hrm_trainings(id) ON DELETE CASCADE,
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(40) NOT NULL,
            client_id INT NULL,
            project_id INT NULL,
            issue_date DATE NOT NULL,
            due_date DATE NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            tax_percent DECIMAL(5,2) NOT NULL DEFAULT 18,
            total DECIMAL(14,2) NOT NULL DEFAULT 0,
            paid DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_invoice_no (invoice_no),
            FOREIGN KEY (client_id) REFERENCES hrm_clients(id) ON DELETE SET NULL,
            FOREIGN KEY (project_id) REFERENCES hrm_projects(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            paid_on DATE NOT NULL,
            method VARCHAR(30) NULL,
            reference VARCHAR(120) NULL,
            notes VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (invoice_id) REFERENCES hrm_invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v13: invoice line items (description, SAC, qty x rate). The invoice keeps the totals.
        "CREATE TABLE IF NOT EXISTS hrm_invoice_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            description VARCHAR(500) NOT NULL,
            sac VARCHAR(10) NULL,
            qty DECIMAL(12,2) NOT NULL DEFAULT 1,
            rate DECIMAL(14,2) NOT NULL DEFAULT 0,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            sort SMALLINT NOT NULL DEFAULT 0,
            KEY (invoice_id),
            FOREIGN KEY (invoice_id) REFERENCES hrm_invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v14: HR policies with versioned acknowledgements, and face-verified punches.
        "CREATE TABLE IF NOT EXISTS hrm_policies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(160) NOT NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'General',
            summary VARCHAR(300) NULL,
            content MEDIUMTEXT NULL,
            attachment VARCHAR(255) NULL,
            version VARCHAR(20) NOT NULL DEFAULT '1.0',
            effective_date DATE NULL,
            review_date DATE NULL,
            department_id INT NULL,
            requires_ack TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(12) NOT NULL DEFAULT 'Draft',
            owner VARCHAR(120) NULL,
            published_at DATETIME NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            KEY k_policy_status (status),
            FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_policy_acks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            policy_id INT NOT NULL,
            employee_id INT NOT NULL,
            version VARCHAR(20) NOT NULL,
            acknowledged_at DATETIME NOT NULL,
            ip VARCHAR(45) NULL,
            UNIQUE KEY uq_policy_ack (policy_id, employee_id, version),
            FOREIGN KEY (policy_id) REFERENCES hrm_policies(id) ON DELETE CASCADE,
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_face_profiles (
            employee_id INT PRIMARY KEY,
            descriptor TEXT NOT NULL,
            samples TINYINT NOT NULL DEFAULT 1,
            photo VARCHAR(255) NULL,
            consent_at DATETIME NOT NULL,
            enrolled_by INT NULL,
            enrolled_at DATETIME NOT NULL,
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            category VARCHAR(60) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            expense_date DATE NOT NULL,
            description VARCHAR(255) NULL,
            receipt VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            approver_id INT NULL,
            decided_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            body TEXT NOT NULL,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Every punch-in / punch-out, kept forever as the raw attendance record.
        "CREATE TABLE IF NOT EXISTS hrm_punches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            work_date DATE NOT NULL,
            type VARCHAR(3) NOT NULL,
            punched_at DATETIME NOT NULL,
            source VARCHAR(10) NOT NULL DEFAULT 'self',
            ip VARCHAR(45) NULL,
            device VARCHAR(120) NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            note VARCHAR(255) NULL,
            created_by INT NULL,
            KEY k_punch_day (employee_id, work_date),
            KEY k_punch_date (work_date),
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(40) NOT NULL,
            entity VARCHAR(60) NULL,
            entity_id INT NULL,
            details VARCHAR(255) NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_log_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v7: sub-vendors (staffing partners) who submit candidates against shared jobs.
        "CREATE TABLE IF NOT EXISTS hrm_vendors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(120) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            city VARCHAR(80) NULL,
            address TEXT NULL,
            gst_no VARCHAR(30) NULL,
            pan_no VARCHAR(20) NULL,
            specialization VARCHAR(255) NULL,
            fee_type VARCHAR(20) NOT NULL DEFAULT 'Percent',
            fee_value DECIMAL(10,2) NULL,
            payment_terms VARCHAR(120) NULL,
            replacement_days INT NULL,
            agreement_until DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vendor_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Jobs shared with specific vendors. A vendor-visible job with no rows here is shared with every active vendor.
        "CREATE TABLE IF NOT EXISTS hrm_job_vendors (
            job_id INT NOT NULL,
            vendor_id INT NOT NULL,
            PRIMARY KEY (job_id, vendor_id),
            FOREIGN KEY (job_id) REFERENCES hrm_jobs(id) ON DELETE CASCADE,
            FOREIGN KEY (vendor_id) REFERENCES hrm_vendors(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Candidate accounts: the profile a job seeker keeps for current and future openings.
        "CREATE TABLE IF NOT EXISTS hrm_talent (
            id INT AUTO_INCREMENT PRIMARY KEY,
            login_id INT NULL,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            headline VARCHAR(190) NULL,
            total_exp DECIMAL(4,1) NULL,
            designation VARCHAR(120) NULL,
            current_employer VARCHAR(150) NULL,
            current_ctc VARCHAR(60) NULL,
            expected_ctc VARCHAR(60) NULL,
            notice_period VARCHAR(60) NULL,
            current_location VARCHAR(120) NULL,
            preferred_location VARCHAR(190) NULL,
            key_skills TEXT NULL,
            education VARCHAR(190) NULL,
            linkedin_url VARCHAR(255) NULL,
            portfolio_url VARCHAR(255) NULL,
            summary TEXT NULL,
            preferred_roles VARCHAR(255) NULL,
            employment_types VARCHAR(120) NULL,
            open_to_work TINYINT(1) NOT NULL DEFAULT 1,
            job_alerts TINYINT(1) NOT NULL DEFAULT 1,
            resume VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_talent_login (login_id),
            KEY k_talent_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Helpdesk.
        "CREATE TABLE IF NOT EXISTS hrm_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_no VARCHAR(20) NOT NULL,
            subject VARCHAR(200) NOT NULL,
            category VARCHAR(60) NOT NULL DEFAULT 'General',
            priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
            status VARCHAR(30) NOT NULL DEFAULT 'Open',
            channel VARCHAR(20) NOT NULL DEFAULT 'Portal',
            requester_id INT NULL,
            assignee_id INT NULL,
            sla_due_at DATETIME NULL,
            sla_paused_at DATETIME NULL,
            first_response_at DATETIME NULL,
            resolved_at DATETIME NULL,
            closed_at DATETIME NULL,
            rating TINYINT NULL,
            rating_comment VARCHAR(255) NULL,
            last_activity_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ticket_no (ticket_no),
            KEY k_ticket_status (status),
            KEY k_ticket_requester (requester_id),
            KEY k_ticket_assignee (assignee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_ticket_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            user_id INT NULL,
            body TEXT NOT NULL,
            internal TINYINT(1) NOT NULL DEFAULT 0,
            event TINYINT(1) NOT NULL DEFAULT 0,
            attachment VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_tmsg_ticket (ticket_id),
            FOREIGN KEY (ticket_id) REFERENCES hrm_tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_kb_articles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            category VARCHAR(60) NOT NULL DEFAULT 'General',
            audience VARCHAR(20) NOT NULL DEFAULT 'Everyone',
            body TEXT NOT NULL,
            tags VARCHAR(255) NULL,
            published TINYINT(1) NOT NULL DEFAULT 1,
            views INT NOT NULL DEFAULT 0,
            helpful INT NOT NULL DEFAULT 0,
            not_helpful INT NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // AI assistant conversation log (drives chat history, usage stats and unanswered-question insights).
        "CREATE TABLE IF NOT EXISTS hrm_ai_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role VARCHAR(10) NOT NULL,
            content TEXT NOT NULL,
            source VARCHAR(20) NULL,
            tools VARCHAR(255) NULL,
            input_tokens INT NULL,
            output_tokens INT NULL,
            feedback TINYINT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_ai_user (user_id, id),
            KEY k_ai_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v9: every stage change, for timelines and hiring analytics.
        "CREATE TABLE IF NOT EXISTS hrm_stage_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            candidate_id INT NOT NULL,
            from_stage VARCHAR(30) NULL,
            to_stage VARCHAR(30) NOT NULL,
            note VARCHAR(255) NULL,
            user_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_sh_cand (candidate_id),
            FOREIGN KEY (candidate_id) REFERENCES hrm_candidates(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v9: HireFlow / Resdex — profiles a client saved, and every contact made with a candidate.
        "CREATE TABLE IF NOT EXISTS hrm_saved_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            talent_id INT NOT NULL,
            folder VARCHAR(80) NOT NULL DEFAULT 'Saved',
            note VARCHAR(255) NULL,
            user_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_saved (client_id, talent_id),
            FOREIGN KEY (talent_id) REFERENCES hrm_talent(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_candidate_contacts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NULL,
            talent_id INT NULL,
            candidate_id INT NULL,
            job_id INT NULL,
            user_id INT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'Email',
            subject VARCHAR(200) NULL,
            message TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_cc_talent (talent_id),
            KEY k_cc_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v9: persistent brute-force throttle (per IP and per login name).
        "CREATE TABLE IF NOT EXISTS hrm_login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            login VARCHAR(190) NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_la_ip (ip, created_at),
            KEY k_la_login (login, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v11: website sales — orders / subscriptions paid through Razorpay, and every gateway event.
        "CREATE TABLE IF NOT EXISTS hrm_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_no VARCHAR(30) NULL,
            plan VARCHAR(20) NOT NULL,
            cycle VARCHAR(10) NOT NULL DEFAULT 'monthly',
            users INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            list_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(12,2) NOT NULL,
            tax_rate DECIMAL(5,2) NOT NULL DEFAULT 18,
            cgst DECIMAL(12,2) NOT NULL DEFAULT 0,
            sgst DECIMAL(12,2) NOT NULL DEFAULT 0,
            igst DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'INR',
            company VARCHAR(150) NOT NULL,
            contact_name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            gstin VARCHAR(15) NULL,
            address VARCHAR(300) NULL,
            state VARCHAR(60) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'created',
            gateway VARCHAR(20) NOT NULL DEFAULT 'razorpay',
            gateway_order_id VARCHAR(60) NULL,
            gateway_payment_id VARCHAR(60) NULL,
            payment_method VARCHAR(30) NULL,
            failure_reason VARCHAR(255) NULL,
            invoice_no VARCHAR(30) NULL,
            paid_at DATETIME NULL,
            period_start DATE NULL,
            period_end DATE NULL,
            renewal_of INT NULL,
            reminder_sent_at DATETIME NULL,
            notes TEXT NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sub_order (order_no),
            UNIQUE KEY uq_sub_invoice (invoice_no),
            KEY k_sub_gw (gateway_order_id),
            KEY k_sub_status (status, period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_payment_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NULL,
            event_id VARCHAR(80) NULL,
            event VARCHAR(60) NOT NULL,
            gateway_order_id VARCHAR(60) NULL,
            gateway_payment_id VARCHAR(60) NULL,
            amount DECIMAL(12,2) NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'webhook',
            payload MEDIUMTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pe_event (event_id),
            KEY k_pe_sub (subscription_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v10: performance management — review cycles, goals / KPIs, reviews (cycle + probation) and kudos.
        "CREATE TABLE IF NOT EXISTS hrm_review_cycles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'Annual',
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            self_due DATE NULL,
            manager_due DATE NULL,
            goal_weight TINYINT NOT NULL DEFAULT 60,
            status VARCHAR(20) NOT NULL DEFAULT 'Draft',
            created_by INT NULL,
            launched_at DATETIME NULL,
            closed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_goals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            cycle_id INT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            category VARCHAR(20) NOT NULL DEFAULT 'Goal',
            target VARCHAR(150) NULL,
            weight TINYINT NOT NULL DEFAULT 0,
            progress TINYINT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'Not Started',
            due_date DATE NULL,
            self_rating TINYINT NULL,
            manager_rating TINYINT NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY k_goal_emp (employee_id, cycle_id),
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
            FOREIGN KEY (cycle_id) REFERENCES hrm_review_cycles(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(20) NOT NULL DEFAULT 'Cycle',
            cycle_id INT NULL,
            employee_id INT NOT NULL,
            reviewer_id INT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Self Review',
            period_start DATE NULL,
            period_end DATE NULL,
            due_date DATE NULL,
            self_ratings TEXT NULL,
            self_comments TEXT NULL,
            self_submitted_at DATETIME NULL,
            manager_ratings TEXT NULL,
            strengths TEXT NULL,
            improvements TEXT NULL,
            manager_comments TEXT NULL,
            manager_submitted_at DATETIME NULL,
            manager_user_id INT NULL,
            overall_rating DECIMAL(3,2) NULL,
            recommendation VARCHAR(40) NULL,
            increment_pct DECIMAL(5,2) NULL,
            decision VARCHAR(20) NULL,
            extend_months TINYINT NULL,
            hr_note TEXT NULL,
            finalized_by INT NULL,
            finalized_at DATETIME NULL,
            employee_comment TEXT NULL,
            acknowledged_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_rev_emp (employee_id),
            KEY k_rev_cycle (cycle_id),
            KEY k_rev_reviewer (reviewer_id, status),
            FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
            FOREIGN KEY (cycle_id) REFERENCES hrm_review_cycles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_kudos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_employee INT NOT NULL,
            to_employee INT NOT NULL,
            badge VARCHAR(30) NOT NULL DEFAULT 'Great work',
            message VARCHAR(500) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_kudos_to (to_employee),
            FOREIGN KEY (from_employee) REFERENCES hrm_employees(id) ON DELETE CASCADE,
            FOREIGN KEY (to_employee) REFERENCES hrm_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v16: onboarding — one case per offer, from final selection to joining (app/lib/onboarding.php).
        "CREATE TABLE IF NOT EXISTS hrm_onboarding_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(20) NOT NULL DEFAULT 'offer',
            name VARCHAR(150) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            version INT NOT NULL DEFAULT 1,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            updated_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY k_tpl_kind (kind, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_onboardings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            candidate_id INT NULL,
            job_id INT NULL,
            talent_id INT NULL,
            login_id INT NULL,
            employee_id INT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Draft',
            revision TINYINT NOT NULL DEFAULT 1,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(30) NULL,
            designation_id INT NULL,
            department_id INT NULL,
            manager_id INT NULL,
            employment_type VARCHAR(30) NOT NULL DEFAULT 'Full-time',
            work_location VARCHAR(120) NULL,
            ctc_annual DECIMAL(12,2) NULL,
            salary TEXT NULL,
            joining_date DATE NULL,
            offer_expires DATE NULL,
            probation_track VARCHAR(10) NULL,
            login_role VARCHAR(20) NOT NULL DEFAULT 'employee',
            template_id INT NULL,
            offer_ref VARCHAR(30) NULL,
            offer_html MEDIUMTEXT NULL,
            offer_hash CHAR(64) NULL,
            hr_notes TEXT NULL,
            created_by INT NULL,
            submitted_at DATETIME NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            approver_note VARCHAR(500) NULL,
            sent_at DATETIME NULL,
            sent_count TINYINT NOT NULL DEFAULT 0,
            token_hash CHAR(64) NULL,
            token_expires DATETIME NULL,
            otp_hash CHAR(64) NULL,
            otp_expires DATETIME NULL,
            email_verified_at DATETIME NULL,
            accepted_at DATETIME NULL,
            declined_at DATETIME NULL,
            decline_reason VARCHAR(500) NULL,
            response_ip VARCHAR(45) NULL,
            profile MEDIUMTEXT NULL,
            form_submitted_at DATETIME NULL,
            declaration_at DATETIME NULL,
            return_note TEXT NULL,
            returned_at DATETIME NULL,
            compliance TEXT NULL,
            hr_approved_by INT NULL,
            hr_approved_at DATETIME NULL,
            activated_by INT NULL,
            activated_at DATETIME NULL,
            joined_on DATE NULL,
            induction_at DATETIME NULL,
            buddy_id INT NULL,
            induction_notes TEXT NULL,
            access_checklist TEXT NULL,
            joined_by INT NULL,
            withdrawn_reason VARCHAR(500) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_onb_ref (offer_ref),
            UNIQUE KEY uq_onb_token (token_hash),
            KEY k_onb_status (status),
            KEY k_onb_candidate (candidate_id),
            KEY k_onb_login (login_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_onboarding_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            onboarding_id INT NOT NULL,
            doc_type VARCHAR(40) NOT NULL,
            title VARCHAR(150) NOT NULL,
            file VARCHAR(255) NOT NULL,
            original_name VARCHAR(190) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            review_note VARCHAR(255) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_onbdoc (onboarding_id, doc_type),
            FOREIGN KEY (onboarding_id) REFERENCES hrm_onboardings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_onboarding_signatures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            onboarding_id INT NOT NULL,
            doc_key VARCHAR(40) NOT NULL,
            title VARCHAR(190) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            ref_id INT NULL,
            version VARCHAR(20) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            content_hash CHAR(64) NOT NULL,
            signed_name VARCHAR(150) NOT NULL,
            signature_file VARCHAR(255) NOT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            signed_at DATETIME NOT NULL,
            UNIQUE KEY uq_onbsig (onboarding_id, doc_key),
            FOREIGN KEY (onboarding_id) REFERENCES hrm_onboardings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_onboarding_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            onboarding_id INT NOT NULL,
            event VARCHAR(40) NOT NULL,
            note VARCHAR(500) NULL,
            actor VARCHAR(12) NOT NULL DEFAULT 'hr',
            user_id INT NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_onbev (onboarding_id),
            FOREIGN KEY (onboarding_id) REFERENCES hrm_onboardings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v17: client onboarding — HR / IT service requests ("engagements"), client documents with versions and signatures.
        "CREATE TABLE IF NOT EXISTS hrm_engagements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ref VARCHAR(30) NULL,
            client_id INT NOT NULL,
            service VARCHAR(5) NOT NULL,
            title VARCHAR(190) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Draft',
            form MEDIUMTEXT NULL,
            submitted_by INT NULL,
            submitted_at DATETIME NULL,
            owner_id INT NULL,
            info_note TEXT NULL,
            info_requested_at DATETIME NULL,
            fee_model VARCHAR(20) NULL,
            fee_value DECIMAL(12,2) NULL,
            estimate_amount DECIMAL(14,2) NULL,
            advance_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            payment_terms VARCHAR(300) NULL,
            replacement_days INT NULL,
            valid_until DATE NULL,
            sop_text MEDIUMTEXT NULL,
            proposal_sent_at DATETIME NULL,
            advance_invoice_id INT NULL,
            payment_waived TINYINT(1) NOT NULL DEFAULT 0,
            waiver_note VARCHAR(500) NULL,
            authorized_by INT NULL,
            authorized_at DATETIME NULL,
            authorization_note VARCHAR(500) NULL,
            job_id INT NULL,
            project_id INT NULL,
            activated_at DATETIME NULL,
            delivered_at DATETIME NULL,
            closed_at DATETIME NULL,
            closure_type VARCHAR(20) NULL,
            closure_note TEXT NULL,
            client_rating TINYINT NULL,
            client_feedback TEXT NULL,
            purged_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_eng_ref (ref),
            KEY k_eng_client (client_id, status),
            KEY k_eng_status (status),
            FOREIGN KEY (client_id) REFERENCES hrm_clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_client_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            engagement_id INT NULL,
            kind VARCHAR(20) NOT NULL,
            service VARCHAR(5) NULL,
            title VARCHAR(190) NOT NULL,
            version INT NOT NULL DEFAULT 1,
            content MEDIUMTEXT NULL,
            content_hash CHAR(64) NULL,
            file VARCHAR(255) NULL,
            original_name VARCHAR(190) NULL,
            template_version INT NULL,
            status VARCHAR(25) NOT NULL DEFAULT 'Awaiting Signature',
            requires_signature TINYINT(1) NOT NULL DEFAULT 1,
            supersedes_id INT NULL,
            superseded_by INT NULL,
            review_note VARCHAR(500) NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            KEY k_cdoc_client (client_id, kind),
            KEY k_cdoc_eng (engagement_id),
            FOREIGN KEY (client_id) REFERENCES hrm_clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_client_signatures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            party VARCHAR(10) NOT NULL DEFAULT 'client',
            login_id INT NULL,
            signer_name VARCHAR(150) NOT NULL,
            signer_designation VARCHAR(120) NULL,
            signer_email VARCHAR(190) NULL,
            method VARCHAR(20) NOT NULL DEFAULT 'otp_draw',
            otp_verified_at DATETIME NULL,
            signature_file VARCHAR(255) NULL,
            content_hash CHAR(64) NOT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            signed_at DATETIME NOT NULL,
            KEY k_csig_doc (document_id),
            FOREIGN KEY (document_id) REFERENCES hrm_client_documents(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_engagement_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            engagement_id INT NOT NULL,
            login_id INT NULL,
            kind VARCHAR(12) NOT NULL DEFAULT 'comment',
            body TEXT NOT NULL,
            internal TINYINT(1) NOT NULL DEFAULT 0,
            meeting_at DATETIME NULL,
            meeting_link VARCHAR(255) NULL,
            attachment VARCHAR(255) NULL,
            ticket_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_emsg (engagement_id),
            FOREIGN KEY (engagement_id) REFERENCES hrm_engagements(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_engagement_milestones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            engagement_id INT NOT NULL,
            title VARCHAR(190) NOT NULL,
            due_date DATE NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'Planned',
            invoice_id INT NULL,
            sort SMALLINT NOT NULL DEFAULT 0,
            KEY k_ems (engagement_id),
            FOREIGN KEY (engagement_id) REFERENCES hrm_engagements(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_client_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            engagement_id INT NULL,
            event VARCHAR(40) NOT NULL,
            note VARCHAR(500) NULL,
            actor VARCHAR(10) NOT NULL DEFAULT 'staff',
            user_id INT NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_cev_client (client_id, id),
            KEY k_cev_eng (engagement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_consents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            login_id INT NULL,
            client_id INT NULL,
            purpose VARCHAR(40) NOT NULL,
            text_hash CHAR(64) NOT NULL,
            text VARCHAR(2000) NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            given_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_consent_login (login_id),
            KEY k_consent_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v19: every schema upgrade (from → to, build, backup taken, columns added).
        "CREATE TABLE IF NOT EXISTS hrm_schema_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_version INT NOT NULL,
            to_version INT NOT NULL,
            build VARCHAR(60) NULL,
            backup_file VARCHAR(190) NULL,
            columns_added TEXT NULL,
            duration_ms INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_notification_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            k VARCHAR(190) NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_notif (k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v20: vendor performance score per industry (internal rating, not a credit score).
        "CREATE TABLE IF NOT EXISTS hrm_industries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_industry_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_vendor_scores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            industry_id INT NOT NULL,
            score TINYINT UNSIGNED NOT NULL,
            note VARCHAR(500) NULL,
            updated_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vendor_industry (vendor_id, industry_id),
            KEY k_vs_industry (industry_id, score),
            FOREIGN KEY (vendor_id) REFERENCES hrm_vendors(id) ON DELETE CASCADE,
            FOREIGN KEY (industry_id) REFERENCES hrm_industries(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_vendor_score_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            industry_id INT NOT NULL,
            old_score TINYINT UNSIGNED NULL,
            new_score TINYINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            user_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_vsh_vendor (vendor_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v20: company-specific candidate ownership (vendor lock + timer). client_id 0 = our own company (jobs without a client).
        "CREATE TABLE IF NOT EXISTS hrm_candidate_locks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            candidate_id INT NULL,
            email_norm VARCHAR(190) NULL,
            phone_norm VARCHAR(15) NULL,
            client_id INT NOT NULL DEFAULT 0,
            job_id INT NULL,
            vendor_id INT NOT NULL,
            sub_vendor_id INT NULL,
            locked_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            extensions TINYINT UNSIGNED NOT NULL DEFAULT 0,
            released_at DATETIME NULL,
            released_by INT NULL,
            release_reason VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY k_lock_email (client_id, email_norm, status),
            KEY k_lock_phone (client_id, phone_norm, status),
            KEY k_lock_status (status, expires_at),
            KEY k_lock_vendor (vendor_id, status),
            KEY k_lock_candidate (candidate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_lock_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lock_id INT NULL,
            candidate_id INT NULL,
            client_id INT NULL,
            vendor_id INT NULL,
            event VARCHAR(30) NOT NULL,
            detail VARCHAR(500) NULL,
            user_id INT NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_le_lock (lock_id),
            KEY k_le_event (event, created_at),
            KEY k_le_candidate (candidate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Submissions whose e-mail and mobile point to different existing records: held for an authorised decision, never merged automatically.
        "CREATE TABLE IF NOT EXISTS hrm_ownership_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_id INT NOT NULL,
            client_id INT NOT NULL DEFAULT 0,
            vendor_id INT NOT NULL,
            sub_vendor_id INT NULL,
            submitted_by INT NULL,
            payload MEDIUMTEXT NOT NULL,
            email_norm VARCHAR(190) NULL,
            phone_norm VARCHAR(15) NULL,
            conflict VARCHAR(500) NULL,
            matched_lock_ids VARCHAR(190) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            decided_by INT NULL,
            decided_at DATETIME NULL,
            decision_note VARCHAR(500) NULL,
            candidate_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_or_status (status, created_at),
            KEY k_or_vendor (vendor_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v21: central candidate database — identity registry (one row per normalised e-mail / phone of a master record).
        // The UNIQUE key makes two simultaneous creations of the same person impossible.
        "CREATE TABLE IF NOT EXISTS hrm_candidate_identities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(5) NOT NULL,
            value VARCHAR(190) NOT NULL,
            candidate_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_identity (kind, value),
            KEY k_ident_candidate (candidate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Potential duplicates waiting for (or decided by) an authorised administrator. Records are never merged automatically.
        "CREATE TABLE IF NOT EXISTS hrm_candidate_dup_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            candidate_id INT NULL,
            matched_ids VARCHAR(255) NOT NULL,
            match_reason VARCHAR(255) NOT NULL,
            source VARCHAR(40) NOT NULL,
            payload MEDIUMTEXT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'Pending',
            resolution VARCHAR(30) NULL,
            resolution_reason VARCHAR(500) NULL,
            requested_by INT NULL,
            resolved_by INT NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_dup_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Resdex search index: only approved, non-confidential attributes of eligible master candidates (no e-mail, phone, LinkedIn or resume path).
        "CREATE TABLE IF NOT EXISTS hrm_resdex_index (
            candidate_id INT PRIMARY KEY,
            talent_id INT NULL,
            name VARCHAR(150) NOT NULL,
            headline VARCHAR(190) NULL,
            designation VARCHAR(120) NULL,
            department VARCHAR(120) NULL,
            employer VARCHAR(150) NULL,
            skills TEXT NULL,
            summary TEXT NULL,
            exp_years DECIMAL(4,1) NULL,
            city VARCHAR(80) NULL,
            state VARCHAR(60) NULL,
            country VARCHAR(60) NULL,
            location VARCHAR(190) NULL,
            qual_level TINYINT NULL,
            education VARCHAR(255) NULL,
            industry_id INT NULL,
            ctc_current DECIMAL(14,2) NULL,
            ctc_expected DECIMAL(14,2) NULL,
            currency CHAR(3) NULL,
            notice_period VARCHAR(40) NULL,
            source VARCHAR(60) NULL,
            has_resume TINYINT(1) NOT NULL DEFAULT 0,
            active_at DATETIME NULL,
            indexed_at DATETIME NOT NULL,
            KEY k_rx_exp (exp_years), KEY k_rx_qual (qual_level), KEY k_rx_ind (industry_id), KEY k_rx_ctc (ctc_current), KEY k_rx_ectc (ctc_expected),
            KEY k_rx_city (city), KEY k_rx_state (state), KEY k_rx_active (active_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v21: Resdex subscriptions & credits for client organisations (separate from the HRMS licence in hrm_subscriptions).
        "CREATE TABLE IF NOT EXISTS hrm_rdx_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(60) NOT NULL,
            tagline VARCHAR(190) NULL,
            price DECIMAL(12,2) NOT NULL,
            annual_price DECIMAL(12,2) NULL,
            credits INT NOT NULL,
            duration_days INT NOT NULL DEFAULT 30,
            recruiter_limit INT NOT NULL DEFAULT 1,
            features TEXT NULL,
            gst_applicable TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(12) NOT NULL DEFAULT 'Active',
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rdx_plan (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_rdx_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_no VARCHAR(30) NULL,
            client_id INT NOT NULL,
            user_id INT NULL,
            plan_id INT NOT NULL,
            cycle VARCHAR(10) NOT NULL DEFAULT 'monthly',
            list_price DECIMAL(12,2) NOT NULL,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(12,2) NOT NULL,
            tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            cgst DECIMAL(12,2) NOT NULL DEFAULT 0,
            sgst DECIMAL(12,2) NOT NULL DEFAULT 0,
            igst DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL,
            bonus_credits INT NOT NULL DEFAULT 0,
            promo_ids VARCHAR(100) NULL,
            referral_id INT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'created',
            gateway VARCHAR(20) NULL,
            gateway_order_id VARCHAR(60) NULL,
            gateway_payment_id VARCHAR(60) NULL,
            payment_method VARCHAR(30) NULL,
            payment_ref VARCHAR(120) NULL,
            invoice_no VARCHAR(30) NULL,
            paid_at DATETIME NULL,
            failure_reason VARCHAR(255) NULL,
            subscription_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rdx_order_no (order_no),
            KEY k_rdx_order_client (client_id, status),
            KEY k_rdx_order_gw (gateway_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_rdx_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            plan_id INT NOT NULL,
            order_id INT NULL,
            cycle VARCHAR(10) NOT NULL DEFAULT 'monthly',
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            starts_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            credits_per_period INT NOT NULL,
            allocations_total INT NOT NULL DEFAULT 1,
            allocations_done INT NOT NULL DEFAULT 0,
            next_allocation_at DATETIME NULL,
            recruiter_limit INT NOT NULL DEFAULT 1,
            features TEXT NULL,
            note VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY k_rdx_sub_client (client_id, status),
            KEY k_rdx_sub_exp (status, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // One row per organisation; changed only inside a transaction that also writes the ledger (SELECT … FOR UPDATE).
        "CREATE TABLE IF NOT EXISTS hrm_credit_accounts (
            client_id INT PRIMARY KEY,
            balance INT NOT NULL DEFAULT 0,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Append-only. Corrections are separate adjustment / reversal entries; txn_ref makes every request idempotent.
        "CREATE TABLE IF NOT EXISTS hrm_credit_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            txn_ref VARCHAR(120) NOT NULL,
            client_id INT NOT NULL,
            user_id INT NULL,
            subscription_id INT NULL,
            type VARCHAR(20) NOT NULL,
            candidate_id INT NULL,
            job_id INT NULL,
            export_id INT NULL,
            order_id INT NULL,
            credits INT NOT NULL,
            balance_before INT NOT NULL,
            balance_after INT NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'completed',
            reason VARCHAR(255) NULL,
            reverses_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ledger_ref (txn_ref),
            KEY k_ledger_client (client_id, created_at),
            KEY k_ledger_type (type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_rdx_unlocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            candidate_id INT NOT NULL,
            user_id INT NULL,
            ledger_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_unlock (client_id, candidate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_rdx_cv_downloads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            candidate_id INT NOT NULL,
            resume VARCHAR(255) NOT NULL,
            user_id INT NULL,
            ledger_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cvdl (client_id, candidate_id, resume)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_rdx_exports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            user_id INT NULL,
            candidate_ids MEDIUMTEXT NOT NULL,
            count_new INT NOT NULL DEFAULT 0,
            count_unlocked INT NOT NULL DEFAULT 0,
            credits INT NOT NULL DEFAULT 0,
            ledger_id INT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            error VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_export_client (client_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_rdx_access_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            user_id INT NULL,
            candidate_id INT NULL,
            action VARCHAR(20) NOT NULL,
            detail VARCHAR(255) NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_access_client (client_id, created_at),
            KEY k_access_action (action, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Saved Resdex profiles keyed by the central candidate id (the older hrm_saved_profiles is keyed by talent profile).
// v22: agreement & document management — audit trail of every document action (append-only).
        "CREATE TABLE IF NOT EXISTS hrm_document_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            event VARCHAR(30) NOT NULL,
            detail VARCHAR(500) NULL,
            login_id INT NULL,
            party VARCHAR(10) NOT NULL DEFAULT 'staff',
            ip VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_docev_doc (document_id, id),
            KEY k_docev_event (event, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Explicit grants on a document (sharing with a client / vendor, vendor edit rights) and their revocation.
        "CREATE TABLE IF NOT EXISTS hrm_document_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            client_id INT NULL,
            vendor_id INT NULL,
            login_id INT NULL,
            permission VARCHAR(12) NOT NULL,
            granted_by INT NULL,
            granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            revoked_by INT NULL,
            revoked_at DATETIME NULL,
            KEY k_docacc_doc (document_id, revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v24: HR Document Centre — categories and document types (configurable by HR; seeded with the standard lifecycle lists).
        "CREATE TABLE IF NOT EXISTS hrm_hr_doc_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            sort SMALLINT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_hr_doc_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            response VARCHAR(12) NOT NULL DEFAULT 'acknowledge',
            sort SMALLINT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_hrdt_cat (category_id, active, sort)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Reusable templates (the record) and every uploaded / edited version (never overwritten).
        "CREATE TABLE IF NOT EXISTS hrm_hr_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type_id INT NOT NULL,
            name VARCHAR(190) NOT NULL,
            subcategory VARCHAR(120) NULL,
            description VARCHAR(1000) NULL,
            applies_to VARCHAR(20) NOT NULL DEFAULT 'All',
            current_version INT NOT NULL DEFAULT 1,
            status VARCHAR(10) NOT NULL DEFAULT 'Active',
            approval_status VARCHAR(20) NOT NULL DEFAULT 'Not required',
            approved_by INT NULL,
            approved_at DATETIME NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NULL,
            updated_at DATETIME NULL,
            deleted_by INT NULL,
            deleted_at DATETIME NULL,
            KEY k_hrt_type (type_id, status, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_hr_template_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            version INT NOT NULL,
            file VARCHAR(255) NULL,
            file_format VARCHAR(5) NULL,
            file_hash CHAR(64) NULL,
            original_name VARCHAR(190) NULL,
            content MEDIUMTEXT NULL,
            note VARCHAR(255) NULL,
            uploaded_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hrtv (template_id, version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Recipient-specific documents generated from a template version (their own frozen copy, versions, status).
        "CREATE TABLE IF NOT EXISTS hrm_hr_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NULL,
            template_version INT NULL,
            type_id INT NULL,
            title VARCHAR(190) NOT NULL,
            candidate_id INT NULL,
            employee_id INT NULL,
            onboarding_id INT NULL,
            recipient_login_id INT NULL,
            recipient_email VARCHAR(190) NULL,
            content MEDIUMTEXT NULL,
            content_hash CHAR(64) NULL,
            file VARCHAR(255) NULL,
            version INT NOT NULL DEFAULT 1,
            supersedes_id INT NULL,
            superseded_by INT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Draft',
            response VARCHAR(12) NOT NULL DEFAULT 'acknowledge',
            signatory_id INT NULL,
            allow_download TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NULL,
            updated_at DATETIME NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            shared_by INT NULL,
            shared_at DATETIME NULL,
            viewed_at DATETIME NULL,
            responded_at DATETIME NULL,
            response_note VARCHAR(500) NULL,
            response_ip VARCHAR(45) NULL,
            expires_at DATETIME NULL,
            revoked_by INT NULL,
            revoked_at DATETIME NULL,
            KEY k_hrd_cand (candidate_id, id),
            KEY k_hrd_emp (employee_id, id),
            KEY k_hrd_status (status, updated_at),
            KEY k_hrd_tpl (template_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Audit trail for templates, generated documents, signatures and induction (append-only; never passwords or tokens).
        "CREATE TABLE IF NOT EXISTS hrm_hr_doc_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NULL,
            template_id INT NULL,
            signatory_id INT NULL,
            employee_id INT NULL,
            event VARCHAR(30) NOT NULL,
            detail VARCHAR(500) NULL,
            login_id INT NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_hrde_doc (document_id, id),
            KEY k_hrde_tpl (template_id, id),
            KEY k_hrde_emp (employee_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Authorised signatories and their signature images (inserted into prepared documents; not an e-signature).
        "CREATE TABLE IF NOT EXISTS hrm_signatories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            designation VARCHAR(150) NOT NULL,
            email VARCHAR(190) NULL,
            login_id INT NULL,
            image VARCHAR(255) NULL,
            notes VARCHAR(255) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Induction & policies: what is assigned (content, policy or library template), to whom, mandatory / acknowledgement rules.
        "CREATE TABLE IF NOT EXISTS hrm_induction_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            kind VARCHAR(12) NOT NULL DEFAULT 'content',
            policy_id INT NULL,
            template_id INT NULL,
            body MEDIUMTEXT NULL,
            file VARCHAR(255) NULL,
            url VARCHAR(255) NULL,
            mandatory TINYINT(1) NOT NULL DEFAULT 1,
            requires_ack TINYINT(1) NOT NULL DEFAULT 1,
            audience VARCHAR(12) NOT NULL DEFAULT 'all',
            department_id INT NULL,
            cohort_from DATE NULL,
            cohort_to DATE NULL,
            due_days SMALLINT NULL,
            effective_from DATE NULL,
            version INT NOT NULL DEFAULT 1,
            sort SMALLINT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_induction_assignees (
            item_id INT NOT NULL,
            employee_id INT NOT NULL,
            assigned_by INT NULL,
            assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (item_id, employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Opens and acknowledgements of induction items, per item version (policy acknowledgements stay in hrm_policy_acks).
        "CREATE TABLE IF NOT EXISTS hrm_induction_acks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            item_version VARCHAR(20) NOT NULL,
            employee_id INT NOT NULL,
            login_id INT NULL,
            opened_at DATETIME NULL,
            acknowledged_at DATETIME NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            UNIQUE KEY uq_indack (item_id, item_version, employee_id),
            KEY k_indack_emp (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Delivery log of system e-mails that matter (onboarding credentials, shared documents, reminders): accepted / failed.
        "CREATE TABLE IF NOT EXISTS hrm_mail_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purpose VARCHAR(30) NOT NULL,
            ref_type VARCHAR(20) NULL,
            ref_id INT NULL,
            recipient VARCHAR(190) NOT NULL,
            subject VARCHAR(190) NOT NULL,
            status VARCHAR(10) NOT NULL,
            error VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_mail_ref (ref_type, ref_id, id),
            KEY k_mail_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v25: in-app notification feed (per login; one row per event, deduplicated by key; read state per user).
        "CREATE TABLE IF NOT EXISTS hrm_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            login_id INT NOT NULL,
            type VARCHAR(30) NOT NULL,
            title VARCHAR(190) NOT NULL,
            body VARCHAR(500) NULL,
            url VARCHAR(255) NULL,
            icon VARCHAR(40) NULL,
            tone VARCHAR(10) NOT NULL DEFAULT 'info',
            k VARCHAR(120) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            UNIQUE KEY uq_notif_k (login_id, k),
            KEY k_notif_login (login_id, read_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v25: website content managed by the Super Admin (draft / live per section) and every published version.
        "CREATE TABLE IF NOT EXISTS hrm_site_content (
            section VARCHAR(40) PRIMARY KEY,
            draft MEDIUMTEXT NULL,
            live MEDIUMTEXT NULL,
            updated_by INT NULL,
            updated_at DATETIME NULL,
            published_by INT NULL,
            published_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_site_revisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section VARCHAR(40) NOT NULL,
            data MEDIUMTEXT NOT NULL,
            note VARCHAR(190) NULL,
            published_by INT NULL,
            published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_siterev (section, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v25: privacy-friendly website analytics: no cookies, no raw IP — visitor = daily-rotating salted hash.
        "CREATE TABLE IF NOT EXISTS hrm_visits (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            visitor CHAR(16) NOT NULL,
            page VARCHAR(20) NOT NULL,
            path VARCHAR(255) NOT NULL,
            referrer VARCHAR(120) NULL,
            utm_source VARCHAR(60) NULL,
            utm_medium VARCHAR(60) NULL,
            utm_campaign VARCHAR(80) NULL,
            device VARCHAR(8) NULL,
            browser VARCHAR(20) NULL,
            os VARCHAR(20) NULL,
            login_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_visit_time (created_at),
            KEY k_visit_page (page, created_at),
            KEY k_visit_visitor (visitor, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // v25: CRM usage per login per day (first / last seen, activity pings): who uses the CRM and who doesn't.
        "CREATE TABLE IF NOT EXISTS hrm_user_days (
            login_id INT NOT NULL,
            day DATE NOT NULL,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            hits INT NOT NULL DEFAULT 1,
            PRIMARY KEY (login_id, day),
            KEY k_ud_day (day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_rdx_saved (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            candidate_id INT NOT NULL,
            folder VARCHAR(80) NOT NULL DEFAULT 'Saved',
            note VARCHAR(255) NULL,
            user_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rdx_saved (client_id, candidate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_rdx_saved_searches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            user_id INT NOT NULL,
            name VARCHAR(80) NOT NULL,
            query TEXT NOT NULL,
            shared TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY k_ss_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_promotions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL,
            name VARCHAR(120) NOT NULL,
            kind VARCHAR(12) NOT NULL,
            description VARCHAR(500) NULL,
            plan_codes VARCHAR(100) NULL,
            cycles VARCHAR(20) NOT NULL DEFAULT 'monthly,annual',
            bonus TEXT NULL,
            price TEXT NULL,
            referrer_bonus INT NOT NULL DEFAULT 0,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            max_redemptions INT NULL,
            new_customers_only TINYINT(1) NOT NULL DEFAULT 1,
            verified_only TINYINT(1) NOT NULL DEFAULT 1,
            stackable TINYINT(1) NOT NULL DEFAULT 0,
            auto_apply TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(12) NOT NULL DEFAULT 'Active',
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_promo_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // One redemption per promotion per company (pending until the qualifying payment succeeds).
        "CREATE TABLE IF NOT EXISTS hrm_promo_redemptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            promotion_id INT NOT NULL,
            client_id INT NOT NULL,
            order_id INT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            bonus_credits INT NOT NULL DEFAULT 0,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_promo_client (promotion_id, client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hrm_referrals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            referrer_client_id INT NOT NULL,
            referred_client_id INT NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            order_id INT NULL,
            rewarded_at DATETIME NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_referred (referred_client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

/**
 * Schema drift repair: a table created by an older version of the app keeps its old columns, because
 * CREATE TABLE IF NOT EXISTS does nothing when the table exists. Compare every table definition in
 * schema_tables() with the real table and add any column that is missing (indexes and keys are left alone).
 * Returns "table.column" for each column added.
 */
function add_missing_columns(): array
{
    $added = [];
    foreach (schema_tables() as $sql) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?\s*\((.*)\)\s*ENGINE/is', $sql, $m) || !table_exists($m[1])) continue;
        $table = $m[1];
        foreach (preg_split('/,\s*\n/', trim($m[2])) as $line) {
            $line = trim($line);
            if (!preg_match('/^`?([a-z_][a-z0-9_]*)`?\s+(\w.*)$/i', $line, $c)) continue;
            if (preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|FOREIGN|CONSTRAINT|FULLTEXT|CHECK)$/i', $c[1])) continue;
            if (column_exists($table, $c[1])) continue;
            $def = preg_replace('/\b(AUTO_INCREMENT|PRIMARY KEY)\b/i', '', $c[2]);
            try {
                db()->exec("ALTER TABLE `$table` ADD COLUMN `{$c[1]}` $def");
                $added[] = "$table.{$c[1]}";
            } catch (PDOException $e) {
                error_log("Schema repair: could not add $table.{$c[1]}: " . $e->getMessage());
            }
        }
    }
    if ($added) error_log('Schema repair added missing columns: ' . implode(', ', $added));
    return $added;
}

/** Brings the database up to SCHEMA_VERSION. Cheap no-op once up to date. */
function ensure_schema(): void
{
    try {
        $v = (int) val("SELECT v FROM hrm_settings WHERE k = 'schema_version'");
    } catch (PDOException $e) {
        $v = 0;
    }
    if ($v >= SCHEMA_VERSION) {
        return;
    }
    // One request upgrades; parallel requests wait for it (then see the new version and return).
    $locked = schema_lock();
    try {
        $v = (int) val("SELECT v FROM hrm_settings WHERE k = 'schema_version'");
    } catch (PDOException $e) {
        $v = 0;
    }
    if ($v >= SCHEMA_VERSION) {
        if ($locked) schema_unlock();
        return;
    }
    $started = microtime(true);
    // A database that already holds HRMS data (any version) is backed up before the first statement runs.
    $fresh = !table_exists('hrm_settings') || !table_exists('hrm_employees');
    $backup = null;
    if (!$fresh) {
        try {
            $backup = basename(db_backup('before-upgrade-v' . $v . '-to-v' . SCHEMA_VERSION));
        } catch (Throwable $e) {
            error_log('Pre-upgrade backup failed (upgrade continues; it is additive only): ' . $e->getMessage());
        }
    }

    /*
     * DATA-SAFETY RULES for everything below (see app/lib/deploy.php):
     *   allowed  CREATE TABLE IF NOT EXISTS, ADD COLUMN, ADD INDEX, INSERT of new settings / templates (INSERT IGNORE),
     *            and giving a value to a column that was added in this same run (it had no data before)
     *   never    UPDATE or DELETE of values that already exist, DROP / MODIFY of columns or tables
     *            (single exception: making a NOT NULL column nullable, which changes no stored value — v22 client_id).
     *   Changes to existing rows go to data_updates(): offered under Database & Updates, applied only by a Super Admin.
     */
    foreach (schema_tables() as $sql) {
        db()->exec($sql);
    }
    // v15: tables created by older versions may lack columns added to their definition later.
    add_missing_columns();

    // Columns the app needs on the pre-existing login table.
    $loginCols = [
        'email'       => 'VARCHAR(190) NULL',
        'employee_id' => 'INT NULL',
        'permissions' => 'TEXT NULL',
        'client_id'   => 'INT NULL',
        'status'      => "VARCHAR(20) NOT NULL DEFAULT 'active'",
        'last_login'  => 'DATETIME NULL',
        'reset_otp'   => 'VARCHAR(10) NULL',
        'otp_expires' => 'DATETIME NULL',
        'created_at'  => 'DATETIME NULL',
        'updated_at'  => 'DATETIME NULL',
    ];
    foreach ($loginCols as $col => $def) {
        if (!column_exists('it_login', $col)) {
            db()->exec("ALTER TABLE it_login ADD COLUMN `$col` $def");
        }
    }

    // v6: client portal — jobs can be raised for a client; clients leave feedback on candidates.
    $extraCols = [
        'hrm_jobs'       => ['client_id' => 'INT NULL'],
        'hrm_candidates' => ['client_decision' => 'VARCHAR(20) NULL', 'client_note' => 'TEXT NULL', 'client_feedback_at' => 'DATETIME NULL'],
    ];
    // v7: sub-vendor + candidate portals.
    $extraCols['it_login']['vendor_id'] = 'INT NULL';
    $extraCols['hrm_jobs']['visibility'] = "VARCHAR(20) NOT NULL DEFAULT 'Public'";
    $extraCols['hrm_jobs']['skills'] = 'VARCHAR(255) NULL';
    $extraCols['hrm_candidates'] += ['vendor_id' => 'INT NULL', 'talent_id' => 'INT NULL', 'submitted_by' => 'INT NULL'];
    // v8: helpdesk columns (also repairs databases whose v7 tables were created before these existed).
    $extraCols['hrm_tickets'] = ['sla_paused_at' => 'DATETIME NULL'];
    $extraCols['hrm_ticket_messages'] = ['event' => 'TINYINT(1) NOT NULL DEFAULT 0'];
    // v9: employee exit workflow, client HireFlow jobs, offer / joining details.
    $extraCols['hrm_employees'] = ['exit_reason' => 'TEXT NULL', 'rehire_eligible' => 'TINYINT(1) NULL', 'exit_processed_by' => 'INT NULL', 'exit_processed_at' => 'DATETIME NULL'];
    $extraCols['hrm_jobs'] += ['posted_by' => 'INT NULL', 'origin' => "VARCHAR(20) NOT NULL DEFAULT 'HR'", 'published_at' => 'DATETIME NULL', 'approved_by' => 'INT NULL'];
    $extraCols['hrm_candidates'] += ['offer_ctc' => 'VARCHAR(60) NULL', 'joining_date' => 'DATE NULL', 'stage_changed_at' => 'DATETIME NULL'];
    // v10: probation (junior 6 months / senior 3 months by default, see probation_months()).
    $extraCols['hrm_employees'] += ['probation_track' => 'VARCHAR(10) NULL', 'probation_end' => 'DATE NULL', 'probation_status' => 'VARCHAR(20) NULL',
        'confirmation_date' => 'DATE NULL', 'probation_extensions' => 'TINYINT NOT NULL DEFAULT 0'];
    // v12: renewal reminders (v11 databases created the table without it).
    $extraCols['hrm_subscriptions'] = ['reminder_sent_at' => 'DATETIME NULL'];
    // v13: GST tax invoices — client state, place of supply and the CGST / SGST / IGST split.
    $extraCols['hrm_clients'] = ['state' => 'VARCHAR(60) NULL'];
    // v14: designation grade; face-verified punches (match distance, snapshot, GPS accuracy, distance from office).
    $extraCols['hrm_designations'] = ['grade' => 'VARCHAR(20) NULL'];
    $extraCols['hrm_punches'] = ['method' => "VARCHAR(10) NOT NULL DEFAULT 'button'", 'face_distance' => 'DECIMAL(5,4) NULL', 'face_photo' => 'VARCHAR(255) NULL',
        'accuracy_m' => 'INT NULL', 'office_distance_m' => 'INT NULL'];
    $extraCols['hrm_invoices'] = ['place_of_supply' => 'VARCHAR(60) NULL', 'cgst' => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
        'sgst' => 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'igst' => 'DECIMAL(14,2) NOT NULL DEFAULT 0'];
    // v17: client onboarding — per-login service scope and signatory flag, company profile, links to engagements.
    $extraCols['it_login'] += ['client_scope' => 'VARCHAR(5) NULL', 'is_signatory' => 'TINYINT(1) NOT NULL DEFAULT 0', 'designation' => 'VARCHAR(120) NULL', 'phone' => 'VARCHAR(30) NULL'];
    $extraCols['hrm_clients'] += ['service_lines' => 'VARCHAR(10) NULL', 'industry' => 'VARCHAR(80) NULL', 'company_size' => 'VARCHAR(20) NULL', 'website' => 'VARCHAR(190) NULL',
        'registered_via' => 'VARCHAR(20) NULL', 'verified_at' => 'DATETIME NULL'];
    $extraCols['hrm_invoices'] += ['engagement_id' => 'INT NULL'];
    $extraCols['hrm_tickets'] += ['engagement_id' => 'INT NULL'];
    // v20: sub-vendor hierarchy (a partner's own sub-vendors share its candidate ownership) and the hiring industry of a job.
    $extraCols['hrm_vendors'] = ['parent_vendor_id' => 'INT NULL'];
    $extraCols['hrm_jobs'] += ['industry_id' => 'INT NULL'];
    // v21: central candidate database — identity (normalised e-mail / E.164 phone), person vs. application (master_id), extended profile,
    // numeric salary, consent / retention and Resdex index status. All new columns start empty; nothing existing is rewritten.
    $extraCols['hrm_candidates'] += [
        'master_id' => 'INT NULL', 'email_norm' => 'VARCHAR(190) NULL', 'phone_norm' => 'VARCHAR(20) NULL', 'phone_country' => 'VARCHAR(2) NULL',
        'identity_status' => 'VARCHAR(20) NULL', 'dup_status' => 'VARCHAR(12) NULL', 'linkedin_url' => 'VARCHAR(255) NULL',
        'country' => 'VARCHAR(60) NULL', 'state' => 'VARCHAR(60) NULL', 'city' => 'VARCHAR(80) NULL', 'department' => 'VARCHAR(120) NULL',
        'relevant_exp' => 'VARCHAR(60) NULL', 'industry_id' => 'INT NULL', 'previous_industry' => 'VARCHAR(120) NULL', 'employment_history' => 'TEXT NULL',
        'qualification_level' => 'TINYINT NULL', 'degree' => 'VARCHAR(120) NULL', 'specialization' => 'VARCHAR(120) NULL', 'institution' => 'VARCHAR(190) NULL',
        'passing_year' => 'SMALLINT NULL', 'other_education' => 'TEXT NULL', 'current_ctc_amount' => 'DECIMAL(14,2) NULL', 'expected_ctc_amount' => 'DECIMAL(14,2) NULL',
        'ctc_currency' => 'CHAR(3) NULL', 'salary_period' => 'VARCHAR(10) NULL', 'recruiter_id' => 'INT NULL', 'record_status' => "VARCHAR(20) NOT NULL DEFAULT 'Active'",
        'consent_status' => 'VARCHAR(20) NULL', 'consent_at' => 'DATETIME NULL', 'consent_source' => 'VARCHAR(60) NULL', 'retention_until' => 'DATE NULL',
        'deletion_requested_at' => 'DATETIME NULL', 'resdex_opt_out' => 'TINYINT(1) NOT NULL DEFAULT 0', 'resume_uploaded_at' => 'DATETIME NULL',
        'index_status' => 'VARCHAR(10) NULL', 'indexed_at' => 'DATETIME NULL', 'index_error' => 'VARCHAR(255) NULL',
    ];
    // v22: agreement & document management on the existing client document table (types, service category, vendor / candidate /
    // offer links, uploads, lifecycle, recipients, permissions), BD account owner and offer-letter entitlement on clients.
    $extraCols['hrm_client_documents'] = ($extraCols['hrm_client_documents'] ?? []) + [
        'doc_type' => 'VARCHAR(40) NULL', 'service_category' => 'VARCHAR(20) NULL', 'vendor_id' => 'INT NULL', 'candidate_id' => 'INT NULL', 'onboarding_id' => 'INT NULL',
        'source' => 'VARCHAR(12) NULL', 'file_format' => 'VARCHAR(5) NULL', 'file_hash' => 'CHAR(64) NULL', 'is_current' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'last_modified_by' => 'INT NULL', 'approved_by' => 'INT NULL', 'approved_at' => 'DATETIME NULL', 'sent_by' => 'INT NULL', 'sent_at' => 'DATETIME NULL',
        'viewed_at' => 'DATETIME NULL', 'signed_at' => 'DATETIME NULL', 'expires_at' => 'DATETIME NULL', 'recipient_login_id' => 'INT NULL', 'recipient_email' => 'VARCHAR(190) NULL',
        'allow_download' => 'TINYINT(1) NOT NULL DEFAULT 1', 'allow_changes' => 'TINYINT(1) NOT NULL DEFAULT 1', 'countersign' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'vendor_editable' => 'TINYINT(1) NOT NULL DEFAULT 0', 'execution_note' => 'VARCHAR(500) NULL', 'cancelled_by' => 'INT NULL', 'cancelled_at' => 'DATETIME NULL',
    ];
    $extraCols['hrm_clients'] += ['account_manager_id' => 'INT NULL', 'offer_letter_access' => 'TINYINT(1) NULL'];
    // v23: careers applications (job and open applications share one record): type, reference, preferences, links, letters,
    // and an idempotency key so a double click or a retried upload never creates a second application.
    $extraCols['hrm_candidates'] = ($extraCols['hrm_candidates'] ?? []) + [
        'application_type' => 'VARCHAR(10) NULL', 'application_ref' => 'VARCHAR(20) NULL', 'preferred_role' => 'VARCHAR(150) NULL', 'preferred_department_id' => 'INT NULL',
        'employment_pref' => 'VARCHAR(20) NULL', 'experience_level' => 'VARCHAR(20) NULL', 'portfolio_url' => 'VARCHAR(255) NULL', 'cover_letter' => 'TEXT NULL',
        'additional_info' => 'TEXT NULL', 'submission_key' => 'CHAR(32) NULL',
    ];
    // v24: employee onboarding credentials & documents — official e-mail (never invented), account activation link (hash only),
    // welcome e-mail delivery, offer letter drafts edited per candidate, library template and signatory on the offer.
    $extraCols['hrm_employees'] = ($extraCols['hrm_employees'] ?? []) + ['official_email' => 'VARCHAR(190) NULL', 'official_email_status' => 'VARCHAR(12) NULL', 'candidate_id' => 'INT NULL'];
    $extraCols['it_login'] = ($extraCols['it_login'] ?? []) + ['activation_hash' => 'CHAR(64) NULL', 'activation_expires' => 'DATETIME NULL', 'password_set_at' => 'DATETIME NULL'];
    // v25: last request time of each login (throttled), for "online now" / active users on the Super Admin dashboard.
    $extraCols['it_login'] += ['last_seen_at' => 'DATETIME NULL'];
    $extraCols['hrm_onboardings'] = ($extraCols['hrm_onboardings'] ?? []) + ['offer_draft' => 'MEDIUMTEXT NULL', 'hr_template_id' => 'INT NULL', 'signatory_id' => 'INT NULL',
        'welcome_status' => 'VARCHAR(12) NULL', 'welcome_sent_at' => 'DATETIME NULL', 'welcome_attempts' => 'SMALLINT NOT NULL DEFAULT 0', 'welcome_error' => 'VARCHAR(255) NULL'];
    $added = [];
    foreach ($extraCols as $table => $cols) {
        foreach ($cols as $col => $def) {
            if (!column_exists($table, $col)) {
                db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                $added[] = "$table.$col";
            }
        }
    }
    // Initial values for columns created just now (they held no data before this run).
    if (in_array('hrm_client_documents.is_current', $added, true)) {
        q('UPDATE hrm_client_documents SET is_current = 0 WHERE superseded_by IS NOT NULL'); // data-safe: is_current was added in this run (no prior values)
    }
    // v22: vendor documents have no client. Making the column nullable changes no stored value (nullability only).
    if (table_exists('hrm_client_documents') && (string) val("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hrm_client_documents' AND COLUMN_NAME = 'client_id'") === 'NO') {
        db()->exec('ALTER TABLE hrm_client_documents MODIFY client_id INT NULL'); // data-safe: NOT NULL → NULL only; no stored value changes
        $added[] = 'hrm_client_documents.client_id (nullable)';
    }
    if (in_array('hrm_jobs.visibility', $added, true)) {
        // Jobs raised for a client stay off the public careers page until HR decides otherwise.
        q("UPDATE hrm_jobs SET visibility = 'Vendors' WHERE client_id IS NOT NULL"); // data-safe: visibility was added in this run (no prior values)
    }

    seed_defaults(); // new settings only (INSERT IGNORE): existing settings are never overwritten
    if (in_array('hrm_employees.probation_end', $added, true)) {
        // Probation end dates for people already on probation (new column, needs the v10 settings seeded above).
        foreach (all("SELECT * FROM hrm_employees WHERE status = 'On Probation' AND probation_end IS NULL") as $e) {
            probation_start($e);
        }
    }
    if (in_array('hrm_employees.probation_status', $added, true)) {
        q("UPDATE hrm_employees SET probation_status = 'Confirmed' WHERE status = 'Active' AND probation_status IS NULL"); // data-safe: probation_status was added in this run
    }
    if ($v === 0) {
        import_legacy_data(); // first conversion of the pre-HRMS portal tables only; never on an upgraded installation
    }
    seed_onboarding_templates(); // only when there are no templates of that kind yet
    seed_hr_doc_types();         // v24: only when the HR Document Centre has no categories yet
    seed_client_templates();
    seed_industries(); // v20: starter industry master, only when the table is empty
    seed_rdx_plans(); // v21: Lite / Standard / Pro and the launch offers, only when none exist
    ensure_indexes(); // v18: indexes for large lists (candidates, talent pool, logs, tasks, leaves)

    // Changes that older versions made to existing rows are offered, not applied (Administration → Database & Updates).
    if ($v > 0) data_updates_offer($v);

    set_setting('schema_version', SCHEMA_VERSION);
    try {
        insert('hrm_schema_log', ['from_version' => $v, 'to_version' => SCHEMA_VERSION, 'build' => APP_BUILD, 'backup_file' => $backup,
            'columns_added' => mb_substr(implode(', ', $added), 0, 2000) ?: null, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
    } catch (Throwable $e) {
        error_log('Schema log: ' . $e->getMessage());
    }
    if ($locked) schema_unlock();
}

/** Starter offer letter, employment agreement and NDA (Indian context). HR edits them under Onboarding → Templates. */
/** HR Document Centre starter categories and types (v24). Runs only while the category table is empty; HR edits them later. */
function seed_hr_doc_types(): void
{
    if (!table_exists('hrm_hr_doc_categories') || val('SELECT COUNT(*) FROM hrm_hr_doc_categories')) return;
    $common = ['Offer Letter' => 'accept', 'Appointment Letter' => 'acknowledge', 'Employment Agreement' => 'acknowledge'];
    $lists = [
        'Recruitment & New Joiner — Fresher' => $common + ['Employee Handbook / Company Policies' => 'acknowledge', 'Code of Conduct' => 'acknowledge', 'Job Description (JD)' => 'acknowledge',
            'Salary Structure / CTC Breakup' => 'none', 'Probation Confirmation / Probation Terms' => 'acknowledge', 'Salary Slip / Payslip' => 'none', 'Form 16' => 'none',
            'PF / UAN Details' => 'none', 'ESIC Insurance Details' => 'none', 'Leave & Attendance Policy' => 'acknowledge',
            'POSH Policy / Internal Complaints Committee Details' => 'acknowledge', 'Induction Material / Induction Documents' => 'acknowledge'],
        'Recruitment & New Joiner — Experienced Employee' => $common + ['Employee ID Card' => 'none', 'Employee Handbook / Company Policies' => 'acknowledge', 'Code of Conduct' => 'acknowledge',
            'Job Description (JD)' => 'acknowledge', 'Salary Structure / CTC Breakup' => 'none', 'Probation Terms' => 'acknowledge', 'Salary Slip / Payslip' => 'none', 'Form 16' => 'none',
            'PF / UAN Transfer or Membership Details' => 'none', 'ESIC Insurance Details' => 'none', 'Leave & Attendance Policy' => 'acknowledge',
            'POSH Policy / Internal Complaints Committee Details' => 'acknowledge', 'Induction Material / Induction Documents' => 'acknowledge'],
        'During Employment' => ['Confirmation Letter' => 'acknowledge', 'Salary Increment Letter' => 'acknowledge', 'Promotion Letter' => 'acknowledge', 'Transfer Letter' => 'acknowledge',
            'Performance Appraisal Letter' => 'acknowledge', 'Bonus / Incentive Letter' => 'acknowledge', 'Warning Letter' => 'acknowledge', 'Appreciation / Recognition Letter' => 'none',
            'Maternity Benefit Communication' => 'acknowledge', 'Experience / Service Certificate' => 'none'],
        'Employee Exit' => ['Relieving Letter' => 'none', 'Experience Certificate / Service Certificate' => 'none', 'Full & Final Settlement Statement' => 'acknowledge', 'Final Salary Slip' => 'none',
            'Form 16' => 'none', 'No-Dues Certificate' => 'none', 'PF Exit / Date of Leaving Update' => 'none', 'Gratuity Payment Details' => 'none', 'Resignation Acceptance Letter' => 'acknowledge'],
    ];
    $c = 0;
    foreach ($lists as $cat => $types) {
        $cid = insert('hrm_hr_doc_categories', ['name' => $cat, 'sort' => ++$c]);
        $t = 0;
        foreach ($types as $name => $response) insert('hrm_hr_doc_types', ['category_id' => $cid, 'name' => $name, 'response' => $response, 'sort' => ++$t]);
    }
}

function seed_onboarding_templates(): void
{
    if (val('SELECT COUNT(*) FROM hrm_onboarding_templates')) return;
    $offer = <<<'TXT'
{{offer_date}}

**{{candidate_name}}**
{{email}} · {{phone}}

Dear {{first_name}},

We are delighted to offer you the position of **{{designation}}** in the {{department}} department at **{{company_name}}**. This letter sets out the main terms of our offer.

## Position and joining
- Designation: **{{designation}}**
- Department: {{department}}
- Reporting to: {{manager}}
- Employment type: {{employment_type}}
- Work location: {{work_location}}
- Date of joining: **{{joining_date}}**

## Compensation
Your annual cost to company (CTC) will be **{{ctc}}** ({{ctc_words}}). The break-up is given below.

{{salary_table}}

Salary is paid monthly, after statutory deductions (provident fund, professional tax and income tax as applicable). Salary details are confidential.

## Probation
You will be on probation for **{{probation_months}} months** from your date of joining. On successful completion your employment will be confirmed in writing. During probation either party may end the employment with notice as per company policy.

## Conditions of this offer
This offer is subject to:
1. Satisfactory verification of your documents, education, previous employment and references.
2. You providing the documents and information requested in the onboarding portal before joining.
3. You signing the employment agreement, the non-disclosure agreement and acknowledging company policies.

Detailed terms of employment are set out in the employment agreement that you will sign during onboarding.

## Acceptance
Please accept this offer in the onboarding portal by **{{offer_expiry}}**, after which it will lapse.

We look forward to welcoming you to {{company_name}}.

For **{{company_name}}**
{{hr_name}}
TXT;
    $agreement = <<<'TXT'
This Employment Agreement is made between **{{company_name}}**, {{company_address}} (the "Company") and **{{candidate_name}}**, {{address}} (the "Employee").

## 1. Appointment
The Company appoints the Employee as **{{designation}}** ({{employment_type}}) with effect from **{{joining_date}}**, based at {{work_location}}, reporting to {{manager}} or such other person as the Company may designate.

## 2. Probation and confirmation
The Employee will be on probation for {{probation_months}} months. The Company may confirm, extend the probation or end the employment based on performance and conduct. Confirmation will be communicated in writing.

## 3. Compensation
The Employee's annual cost to company is {{ctc}}. Salary is paid monthly by bank transfer after deduction of applicable taxes and statutory contributions (EPF, ESI where applicable, professional tax and TDS). Increments are at the Company's discretion based on performance reviews.

## 4. Working hours, attendance and leave
The Employee will follow the working hours, attendance (including punch-in through the HRMS) and leave policies of the Company as amended from time to time.

## 5. Duties and conduct
The Employee will perform the duties assigned with diligence and honesty, follow lawful instructions and comply with the Company's code of conduct, information-security, anti-harassment (POSH) and other policies.

## 6. Exclusive service
During employment the Employee will not take up any other employment, business or consulting assignment without the Company's prior written consent.

## 7. Confidentiality and intellectual property
The Employee will keep the Company's and its clients' information confidential, as set out in the separate Non-Disclosure Agreement. All work products, code, documents and inventions created in the course of employment belong to the Company.

## 8. Notice period and separation
After confirmation, either party may terminate employment by giving {{notice_period}} written notice or salary in lieu of notice (at the Company's discretion). The Company may terminate employment without notice for misconduct. On separation the Employee will return all Company property and complete the handover.

## 9. Verification
Employment is subject to satisfactory verification of the documents and information provided. Any false information may lead to termination at any stage.

## 10. Governing law
This agreement is governed by the laws of India. Courts at the Company's registered office have jurisdiction.

By signing electronically, the Employee confirms having read, understood and accepted this agreement.
TXT;
    $nda = <<<'TXT'
This Non-Disclosure Agreement is made between **{{company_name}}** (the "Company") and **{{candidate_name}}** (the "Employee"), joining as {{designation}} on {{joining_date}}.

## 1. Confidential information
"Confidential Information" means all non-public information of the Company and its clients, including source code, designs, product plans, client lists and data, pricing, financial information, employee data, credentials and trade secrets, in any form.

## 2. Obligations
The Employee will:
- use Confidential Information only to perform their duties for the Company;
- not disclose it to anyone outside the Company (or inside, unless they need to know);
- not copy, upload or transfer it to personal devices, personal e-mail or unapproved cloud services;
- follow the Company's information-security policy and report any suspected breach immediately.

## 3. Personal data
The Employee will handle personal data of colleagues, clients and candidates in line with the Digital Personal Data Protection Act, 2023 and Company policy.

## 4. Return of information
On separation, or whenever asked, the Employee will return or securely delete all Confidential Information and Company property.

## 5. Duration
These obligations apply during employment and continue for three years after it ends; obligations on trade secrets and personal data continue indefinitely.

## 6. Remedies
A breach may cause the Company irreparable harm. The Company may seek an injunction in addition to any other remedy available in law.

By signing electronically, the Employee confirms having read, understood and accepted this agreement.
TXT;
    foreach ([['offer', 'Standard offer letter', $offer], ['agreement', 'Employment agreement', $agreement], ['nda', 'Non-disclosure agreement', $nda]] as [$kind, $name, $body]) {
        insert('hrm_onboarding_templates', ['kind' => $kind, 'name' => $name, 'body' => $body, 'is_default' => 1, 'active' => 1]);
    }
}

/**
 * Resumes uploaded by the old stand-alone apply forms (apply-now/, job-application/) were saved in public
 * folders and referenced by full URL. Move each into private storage and point the candidate at it.
 */
function migrate_public_resumes(): void
{
    $rows = all("SELECT id, resume FROM hrm_candidates WHERE resume LIKE 'http%' OR resume LIKE '%job-application%' OR resume LIKE '%apply-now%'");
    foreach ($rows as $r) {
        $path = parse_url((string) $r['resume'], PHP_URL_PATH) ?: (string) $r['resume'];
        if (!preg_match('#(apply-now|job-application)/((?:job-applications|uploads/resumes)/[A-Za-z0-9._-]+)$#', $path, $m)) continue;
        // The old folders now live in _legacy/ (not web-accessible); also check the original location.
        $src = PUBLIC_DIR . '/_legacy/' . $m[1] . '/' . $m[2];
        if (!is_file($src)) $src = PUBLIC_DIR . '/' . $m[1] . '/' . $m[2];
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        if (!is_file($src) || !in_array($ext, UPLOAD_RULES['resumes'], true)) continue;
        $dir = STORAGE_DIR . '/uploads/resumes';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (copy($src, "$dir/$name")) {
            q('UPDATE hrm_candidates SET resume = ? WHERE id = ?', ["resumes/$name", $r['id']]); // data-safe: only run from data_updates() (Super Admin, after a backup), never by an upgrade
        }
    }
}

function seed_defaults(): void
{
    $defaults = [
        'company_name'    => 'Adorable IT Services',
        'company_email'   => 'hr@adorableit.com',
        'company_phone'   => '',
        'company_address' => '',
        'currency'        => '₹',
        'emp_code_prefix' => 'ADIT',
        'office_start'    => '09:30',
        'office_end'      => '18:30',
        // v24: HR Document Centre, employee activation and induction
        'hr_doc_max_mb'          => '10',
        'hr_doc_require_approval' => '0',
        'activation_link_hours'  => '72',
        'induction_window_days'  => '90',
        'onboarding_support_contact' => '',
        // v25: website analytics, notifications
        'site_tracking'          => '1',
        'visit_retention_days'   => '395',
        'notif_poll_seconds'     => '60',
        'grace_minutes'   => '15',
        'weekend_days'    => '0,6',
        'pf_percent'      => '12',
        'pf_wage_ceiling' => '15000',
        // v7
        'careers_enabled'   => '1',
        'hireflow_job_approval' => '1',
        // v10
        'probation_months_junior' => '6',
        'probation_months_senior' => '3',
        'probation_reminder_days' => '15',
        // v11: pricing (₹ per user per month) and billing
        'price_light'      => '49',
        'price_standard'   => '99',
        'price_pro'        => '149',
        'annual_discount'  => '20',
        'min_users'        => '10',
        'max_online_users' => '500',
        'gst_percent'      => '18',
        'company_state'    => 'Uttar Pradesh',
        'invoice_sac'      => '998315',
        // v14: face punch (off / optional / required), match strictness, office geofence
        'face_punch'           => 'off',
        'face_match_threshold' => '0.50',
        'punch_require_location' => '0',
        'office_lat'           => '',
        'office_lng'           => '',
        'geofence_radius'      => '',
        // v13: client invoices
        'client_invoice_sac'  => '998313',
        'invoice_due_days'    => '15',
        'invoice_terms'       => "Payment is due by the due date shown above.\nPlease quote the invoice number with your payment.",
        // v16: onboarding
        'offer_approval'          => '1',
        'offer_valid_days'        => '7',
        'onboarding_activation'   => 'approval',
        'onboarding_probation'    => '1',
        'onboarding_signatory'    => 'Human Resources',
        'notice_period_days'      => '30',
        'salary_basic_pct'        => '40',
        'salary_hra_pct'          => '50',
        'default_professional_tax' => '200',
        'onboarding_documents'    => ONB_DEFAULT_DOCUMENTS,
        'onboarding_compliance'   => ONB_DEFAULT_COMPLIANCE,
        'onboarding_tasks'        => ONB_DEFAULT_TASKS,
        'onboarding_access_items' => ONB_DEFAULT_ACCESS,
        // v17: client onboarding
        'client_signup'          => '1',
        'eng_reminder_days'      => '3',
        'eng_replacement_days'   => '90',
        'eng_hr_fee_percent'     => '8.33',
        'eng_advance_percent'    => '30',
        'eng_job_visibility'     => 'Vendors',
        'eng_hr_sac'             => '998512',
        'eng_jurisdiction'       => 'Noida, Uttar Pradesh',
        'eng_retention_months'   => '96',
        'esign_mode'             => 'otp',
        // v20: vendor performance score + candidate ownership lock
        'vendor_score_labels'    => 'Excellent|Good|Better',
        'lock_enabled'           => '1',
        'lock_days'              => '30',
        'lock_reminder_days'     => '3',
        'lock_max_extension_days' => '30',
        'lock_hold_stages'       => 'Shortlisted,HR Screening,Interview,Client Interview,Selected,Offered',
        // v21: central candidate database, Resdex credits & subscriptions
        'default_phone_country'  => 'IN',
        'rdx_billing_enabled'    => '1',
        'rdx_cost_job_post'      => '5',
        'rdx_cost_unlock'        => '2',
        'rdx_cost_cv'            => '2',
        'rdx_cost_export_new'    => '3',
        'rdx_cost_export_unlocked' => '2',
        'rdx_cv_redownload_free' => '1',
        'rdx_repost_charge'      => '0',
        'rdx_carry_forward'      => '1',
        'rdx_consent_mode'       => 'not_withdrawn',
        'rdx_export_max'         => '500',
        'rdx_daily_unlock_limit' => '300',
        'rdx_expiry_reminder_days' => '7,1',
        // v22: agreement & document management
        'doc_sign_days'          => '14',
        'doc_require_approval'   => '0',
        'doc_max_mb'             => '10',
        'candidate_signup'  => '1',
        'vendor_dup_days'   => '90',
        'ticket_prefix'     => 'TKT',
        'sla_hours_Critical' => '4',
        'sla_hours_High'     => '8',
        'sla_hours_Medium'   => '24',
        'sla_hours_Low'      => '72',
        'ai_enabled'        => '1',
        'ai_model'          => 'claude-opus-5',
        'ai_effort'         => 'low',
        'ai_rate_limit'     => '20',
    ];
    foreach ($defaults as $k => $v) {
        q('INSERT IGNORE INTO hrm_settings (k, v) VALUES (?, ?)', [$k, $v]);
    }

    if (!val('SELECT COUNT(*) FROM hrm_leave_types')) {
        $types = [
            ['Casual Leave', 'CL', 12, 1, '#6366f1'],
            ['Sick Leave', 'SL', 8, 1, '#ef4444'],
            ['Earned Leave', 'EL', 15, 1, '#10b981'],
            ['Loss of Pay', 'LOP', 0, 0, '#64748b'],
        ];
        foreach ($types as $t) {
            q('INSERT INTO hrm_leave_types (name, code, annual_quota, is_paid, color) VALUES (?,?,?,?,?)', $t);
        }
    }

    if (!val('SELECT COUNT(*) FROM hrm_departments')) {
        foreach (['Management', 'Human Resources', 'Engineering', 'Sales & Marketing', 'Finance & Accounts', 'Operations'] as $d) {
            q('INSERT INTO hrm_departments (name) VALUES (?)', [$d]);
        }
    }
}

/**
 * One-time copy of records from the tables used by the previous version of the
 * portal (candidates, hr_sales, it_projects, it_members). Old tables are left untouched.
 */
function import_legacy_data(): void
{
    if (setting('legacy_imported')) {
        return;
    }

    $candidateMap = [
        'name' => 'candidate_name', 'email' => 'email', 'phone' => 'contact_no', 'work_exp' => 'work_exp',
        'key_skills' => 'expert_key_skills', 'current_ctc' => 'annual_salary', 'current_location' => 'current_location',
        'preferred_location' => 'preferred_location', 'current_employer' => 'current_employer',
        'designation' => 'designation', 'education' => 'education', 'notes' => 'comment_1',
    ];
    foreach (['candidates' => 'Legacy portal', 'hr_sales' => 'HR Sales (legacy)'] as $table => $source) {
        if (!table_exists($table)) {
            continue;
        }
        foreach (all('SELECT * FROM ' . qi($table)) as $r) {
            $row = ['source' => $source, 'stage' => 'Applied'];
            foreach ($candidateMap as $new => $old) {
                $row[$new] = isset($r[$old]) && $r[$old] !== '' ? mb_substr((string) $r[$old], 0, 190) : null;
            }
            if (!$row['name']) {
                continue;
            }
            if (!empty($r['resume'])) {
                $row['resume'] = 'resumes/' . basename($r['resume']);
            }
            insert('hrm_candidates', $row);
        }
    }

    if (table_exists('it_projects')) {
        $statusMap = ['Active' => 'In Progress', 'Pending' => 'Not Started', 'Closed' => 'Completed'];
        foreach (all('SELECT * FROM it_projects') as $r) {
            $clientId = null;
            if (!empty($r['client_name'])) {
                $clientId = val('SELECT id FROM hrm_clients WHERE name = ?', [$r['client_name']])
                    ?: insert('hrm_clients', ['name' => $r['client_name']]);
            }
            $deadline = !empty($r['deadline']) ? date('Y-m-d', strtotime($r['deadline']) ?: time()) : null;
            insert('hrm_projects', [
                'name'        => $r['project_name'] ?: 'Untitled project',
                'client_id'   => $clientId,
                'deadline'    => $deadline,
                'status'      => $statusMap[$r['status'] ?? ''] ?? 'Not Started',
                'description' => !empty($r['team_members']) ? 'Team: ' . $r['team_members'] : null,
            ]);
        }
    }

    if (table_exists('it_members')) {
        foreach (all('SELECT * FROM it_members') as $r) {
            if (empty($r['member_name'])) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($r['member_name']), 2);
            $designationId = null;
            if (!empty($r['designation'])) {
                $designationId = val('SELECT id FROM hrm_designations WHERE title = ?', [$r['designation']])
                    ?: insert('hrm_designations', ['title' => $r['designation']]);
            }
            insert('hrm_employees', [
                'emp_code'       => next_emp_code(),
                'first_name'     => $parts[0],
                'last_name'      => $parts[1] ?? null,
                'email'          => $r['email'] ?? null,
                'phone'          => $r['contact'] ?? null,
                'dob'            => !empty($r['dob']) && strtotime($r['dob']) ? date('Y-m-d', strtotime($r['dob'])) : null,
                'designation_id' => $designationId,
                'join_date'      => date('Y-m-d'),
            ]);
        }
    }

    set_setting('legacy_imported', date('c'));
}


/** Starter client NDA, recruitment agreement, work agreement, SOP and proposal (Indian context — have them reviewed). */
function seed_client_templates(): void
{
    if (val("SELECT COUNT(*) FROM hrm_onboarding_templates WHERE kind LIKE 'client%'")) return;
    $nda = <<<'TXT'
This Mutual Non-Disclosure Agreement ("Agreement") is made on {{today}} between **{{company_name}}**, {{company_address}} (GSTIN {{company_gstin}}) and **{{client_company}}**, {{client_address}} (GSTIN {{client_gstin}}), each a "Party".

## 1. Purpose
The Parties wish to evaluate and carry out recruitment and / or IT services (the "Purpose") and may share confidential information for that Purpose.

## 2. Confidential information
"Confidential Information" means any non-public information disclosed by a Party, in any form, including business plans, pricing, client and candidate data, source code, designs, credentials and know-how. It does not include information that is public without breach, already lawfully known to the recipient, independently developed, or lawfully received from a third party.

## 3. Obligations
Each Party will:
- use the other's Confidential Information only for the Purpose;
- disclose it only to employees, consultants and advisers who need to know and are bound by similar obligations;
- protect it with at least reasonable care, and report any unauthorised disclosure promptly.

## 4. Personal data
Personal data (including candidate resumes and employee data) will be processed only for the Purpose, in line with the Digital Personal Data Protection Act, 2023 and applicable rules, and deleted or returned when no longer needed.

## 5. Term
This Agreement applies to disclosures during the relationship and the confidentiality obligations survive for **three (3) years** after it ends; obligations on trade secrets and personal data continue as long as the information remains confidential.

## 6. Return of information
On request, each Party will return or securely delete the other's Confidential Information, except copies it must keep by law.

## 7. General
Nothing in this Agreement grants any licence or obliges either Party to enter into a transaction. This Agreement is governed by the laws of India and the courts at {{jurisdiction}} have exclusive jurisdiction.

Signed electronically by the authorised signatory of {{client_company}} and accepted by {{company_name}}.
TXT;
    $hr = <<<'TXT'
This Recruitment Services Agreement is made on {{today}} between **{{company_name}}** ("Service Provider") and **{{client_company}}**, {{client_address}} ("Client").

## 1. Services
The Service Provider will source, screen and present candidates for the vacancies the Client shares through the client portal, coordinate interviews and support offers and joining. Each vacancy is activated only after the Client approves its commercial proposal.

## 2. Fees
- The recruitment fee for each vacancy is set out in the commercial proposal for that vacancy.
- A fee is earned when a candidate presented by the Service Provider joins the Client (or its group company) within **12 months** of being presented.
- Fees are invoiced on the joining date and payable within {{payment_terms}}. GST is charged extra.

## 3. Replacement guarantee
If a placed candidate leaves or is terminated (other than for redundancy) within **{{replacement_days}} days** of joining, the Service Provider will provide a replacement for the same role once, at no additional fee, provided the fee for the original placement has been paid in full.

## 4. Client responsibilities
The Client will share accurate job descriptions, give interview feedback within 3 working days, and inform the Service Provider of offers made, offer acceptances and joining dates.

## 5. Candidate data
Candidate profiles are shared only for the specific vacancy. The Client will use them solely to evaluate the candidate, will not share them with third parties, and will delete profiles of candidates not hired when asked or within 6 months. Both parties comply with the Digital Personal Data Protection Act, 2023.

## 6. Non-solicitation
Directly approaching or hiring a candidate presented by the Service Provider without paying the fee is a breach; the full fee becomes payable.

## 7. Term and termination
This Agreement continues until terminated by either party with 30 days' written notice. Fees for candidates who joined (or join within the period in clause 2) remain payable.

## 8. Liability
The Service Provider verifies information to the extent reasonably possible but does not guarantee the performance of any candidate. Its total liability is limited to the fees received for the vacancy concerned.

## 9. Governing law
This Agreement is governed by the laws of India; courts at {{jurisdiction}} have exclusive jurisdiction.
TXT;
    $it = <<<'TXT'
This Master Services Agreement ("Agreement") is made on {{today}} between **{{company_name}}** ("Service Provider") and **{{client_company}}**, {{client_address}} ("Client").

## 1. Scope
The Service Provider will deliver the IT services described in a Statement of Work ("SOP") agreed for each project. If this Agreement and an SOP conflict, the SOP prevails for that project.

## 2. Changes
Changes to scope, timeline or cost are made through a written change request approved by both parties in the client portal. Approved changes create a new SOP version.

## 3. Fees and payment
Fees, advances and milestones are set out in the commercial proposal / SOP. Invoices are payable within {{payment_terms}}; GST is charged extra. Work may be paused while undisputed invoices are overdue by more than 15 days.

## 4. Client responsibilities
The Client will provide timely information, content, access and approvals, and nominate a single point of contact. Delays caused by the Client may extend the timeline.

## 5. Acceptance
Each deliverable is shared in the client portal. The Client will accept it or report specific defects against the SOP within **7 working days**; otherwise it is deemed accepted.

## 6. Intellectual property
On full payment, the Client owns the deliverables created specifically for it. The Service Provider keeps its pre-existing tools, libraries and know-how and grants the Client a perpetual licence to use them as part of the deliverables. Open-source components remain under their own licences.

## 7. Warranty and support
The Service Provider fixes defects reported within **30 days** of acceptance at no cost. Further support is provided under a separate support plan.

## 8. Confidentiality and data protection
The Mutual NDA between the parties applies. Each party complies with the Digital Personal Data Protection Act, 2023 for personal data it processes, and the Service Provider follows reasonable security practices (IT Act, 2000, s.43A).

## 9. Liability
Neither party is liable for indirect or consequential loss. Each party's total liability under an SOP is limited to the fees paid under that SOP in the preceding 12 months, except for breach of confidentiality or wilful misconduct.

## 10. Term and termination
This Agreement continues until terminated by either party with 30 days' written notice, or immediately for material breach not cured within 15 days. The Client pays for work done up to termination.

## 11. Governing law
This Agreement is governed by the laws of India; courts at {{jurisdiction}} have exclusive jurisdiction.
TXT;
    $sop = <<<'TXT'
**Project:** {{engagement_title}} ({{engagement_ref}})
**Client:** {{client_company}}
**Service provider:** {{company_name}}

This Statement of Work is issued under the Master Services Agreement between the parties.

## 1. Requirement as submitted
{{requirement_summary}}

## 2. Scope of work
{{sop_scope}}

## 3. Timeline
Start: {{start_date}} · Target completion: {{deadline}}. Detailed milestones are tracked in the client portal.

## 4. Commercials
{{commercials_table}}

## 5. Acceptance
Deliverables are accepted in the client portal as per the Master Services Agreement. Any change to this scope needs a new version of this SOP.
TXT;
    $proposal = <<<'TXT'
**To:** {{client_company}}
**Reference:** {{engagement_ref}} · {{engagement_title}}
**Date:** {{today}}

Thank you for choosing {{company_name}} for your {{service}} requirement. Based on the details you shared, we propose the following.

## Your requirement
{{requirement_summary}}

## Commercials
{{commercials_table}}

## Next steps
1. Accept this proposal and sign the agreements in the client portal.
2. Pay the advance (if any) against the invoice we issue.
3. After internal authorisation we activate your requirement and our team starts work.

This proposal is valid until {{validity_date}}.
TXT;
    foreach ([['client_nda', 'Mutual non-disclosure agreement', $nda], ['client_hr_agreement', 'Recruitment services agreement', $hr], ['client_it_agreement', 'Master services agreement (IT)', $it],
        ['client_sop', 'Statement of work', $sop], ['client_proposal', 'Commercial proposal', $proposal]] as [$kind, $name, $body]) {
        insert('hrm_onboarding_templates', ['kind' => $kind, 'name' => $name, 'body' => $body, 'is_default' => 1, 'active' => 1]);
    }
}

/**
 * Indexes that keep list pages fast on large databases (tens of thousands of candidates / log rows): the default
 * sort columns, foreign keys used as filters, and duplicate checks. Each is added only if missing, so it is safe to re-run.
 */
function ensure_indexes(): void
{
    $want = [
        ['hrm_candidates', 'k_cand_created', 'created_at'],
        ['hrm_candidates', 'k_cand_email', 'email'],
        ['hrm_candidates', 'k_cand_phone', 'phone'],
        ['hrm_candidates', 'k_cand_job_stage', 'job_id, stage'],
        ['hrm_candidates', 'k_cand_vendor', 'vendor_id'],
        ['hrm_candidates', 'k_cand_talent', 'talent_id'],
        ['hrm_candidates', 'k_cand_source', 'source'],
        ['hrm_talent', 'k_talent_updated', 'updated_at'],
        ['hrm_activity_log', 'k_log_entity', 'entity, entity_id'],
        ['hrm_activity_log', 'k_log_user', 'user_id, created_at'],
        ['hrm_employees', 'k_emp_name', 'first_name, last_name'],
        ['hrm_tasks', 'k_task_assignee', 'assignee_id, status'],
        ['hrm_leaves', 'k_leave_emp', 'employee_id, start_date'],
        ['hrm_invoices', 'k_inv_client', 'client_id, status'],
        ['hrm_jobs', 'k_job_status', 'status, created_at'],
        ['hrm_candidates', 'k_cand_email_norm', 'email_norm'],
        ['hrm_candidates', 'k_cand_phone_norm', 'phone_norm'],
        ['hrm_candidates', 'k_cand_master', 'master_id'],
        ['hrm_candidates', 'k_cand_index', 'index_status'],
        ['hrm_candidates', 'k_cand_record', 'record_status'],
        ['hrm_client_documents', 'k_cdoc_vendor', 'vendor_id'],
        ['hrm_client_documents', 'k_cdoc_onb', 'onboarding_id'],
        ['hrm_client_documents', 'k_cdoc_type', 'doc_type, service_category'],
        ['hrm_candidates', 'k_cand_apptype', 'application_type, created_at'],
        ['hrm_candidates', 'k_cand_appref', 'application_ref'],
        ['hrm_employees', 'k_emp_candidate', 'candidate_id'],
        ['hrm_onboardings', 'k_onb_welcome', 'welcome_status'],
    ];
    foreach ($want as [$table, $name, $cols]) {
        if (!table_exists($table)) continue;
        $has = val('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $name]);
        if ($has) continue;
        try {
            db()->exec("ALTER TABLE `$table` ADD INDEX `$name` ($cols)");
        } catch (PDOException $e) {
            error_log("Index $table.$name not added: " . $e->getMessage());
        }
    }
    // v23: one application per submission key (NULL for every older record, so nothing existing can collide).
    if (table_exists('hrm_candidates') && column_exists('hrm_candidates', 'submission_key')
        && !val("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'hrm_candidates' AND index_name = 'uq_cand_submission'")) {
        try { db()->exec('ALTER TABLE `hrm_candidates` ADD UNIQUE INDEX `uq_cand_submission` (submission_key)'); } catch (PDOException $e) { error_log('Index uq_cand_submission not added: ' . $e->getMessage()); }
    }
}
/** Starter industry master (v20). Super Admin / authorised users add, rename or deactivate them under Recruitment → Industries. */
function seed_industries(): void
{
    if (val('SELECT COUNT(*) FROM hrm_industries')) return;
    foreach (['IT & Software', 'BFSI (Banking, Financial Services & Insurance)', 'Oil & Gas', 'Energy & Power', 'Healthcare & Pharma', 'Manufacturing & Engineering',
                 'Automotive', 'Retail & E-commerce', 'FMCG', 'Telecom', 'Logistics & Supply Chain', 'Construction & Real Estate', 'Education & EdTech',
                 'Hospitality & Travel', 'Media & Entertainment', 'Government & PSU', 'Consulting & Professional Services', 'BPO / KPO'] as $n) {
        q('INSERT IGNORE INTO hrm_industries (name) VALUES (?)', [$n]);
    }
}
/** Resdex plans and the launch promotions (v21). Seeded only when the tables are empty; edited later under Resdex & Credits. */
function seed_rdx_plans(): void
{
    if (!val('SELECT COUNT(*) FROM hrm_rdx_plans')) {
        $plans = [
            ['lite', 'Lite', 'For freelancers, consultants and small recruiters', 999, 9990, 500, 1, ['filters' => 'basic', 'saved_searches' => false, 'folders' => false, 'notes' => false, 'bulk_export' => false, 'reports' => false, 'support' => 'Standard']],
            ['standard', 'Standard', 'For growing recruitment agencies and HR teams', 2499, 24990, 1500, 3, ['filters' => 'advanced', 'saved_searches' => true, 'folders' => true, 'notes' => true, 'bulk_export' => false, 'reports' => false, 'support' => 'E-mail']],
            ['pro', 'Pro', 'For established agencies and high-volume recruiters', 4999, 49990, 4000, 5, ['filters' => 'all', 'saved_searches' => true, 'folders' => true, 'shared_folders' => true, 'notes' => true, 'bulk_export' => true, 'reports' => true, 'support' => 'Priority']],
        ];
        foreach ($plans as $i => [$code, $name, $tag, $price, $annual, $credits, $rec, $feat]) {
            q('INSERT IGNORE INTO hrm_rdx_plans (code, name, tagline, price, annual_price, credits, duration_days, recruiter_limit, features, sort) VALUES (?,?,?,?,?,?,30,?,?,?)',
                [$code, $name, $tag, $price, $annual, $credits, $rec, json_encode($feat), $i]);
        }
    }
    if (!val('SELECT COUNT(*) FROM hrm_promotions')) {
        $now = date('Y-m-d H:i:s');
        $promos = [
            ['LAUNCH', 'New customer launch offer', 'bonus', 'Bonus credits on your first paid plan (full listed price) during the launch period.', 'lite,standard,pro', 'monthly,annual', ['lite' => 100, 'standard' => 300, 'pro' => 800], null, 0, $now, date('Y-m-d H:i:s', strtotime('+30 days')), null, 1, 1],
            ['STARTUP799', 'Startup & small agency', 'price', 'First month of Lite at ₹799 instead of ₹999, for the first 100 verified businesses. Not combinable with other price discounts.', 'lite', 'monthly', null, ['lite' => 799], 0, $now, null, 100, 0, 0],
            ['REFER', 'Refer a recruiter', 'referral', 'The new customer gets 100 bonus credits and the referrer 200, after the new customer\'s first paid plan.', 'lite,standard,pro', 'monthly,annual', ['lite' => 100, 'standard' => 100, 'pro' => 100], null, 200, $now, null, null, 0, 0],
        ];
        foreach ($promos as [$code, $name, $kind, $desc, $plans, $cycles, $bonus, $price, $refBonus, $start, $end, $max, $auto, $stack]) {
            q('INSERT IGNORE INTO hrm_promotions (code, name, kind, description, plan_codes, cycles, bonus, price, referrer_bonus, starts_at, ends_at, max_redemptions, auto_apply, stackable, new_customers_only, verified_only)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1)', [$code, $name, $kind, $desc, $plans, $cycles, $bonus ? json_encode($bonus) : null, $price ? json_encode($price) : null, $refBonus, $start, $end, $max, $auto, $stack]);
        }
    }
}