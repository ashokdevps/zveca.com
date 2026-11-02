<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Apply Now
    </title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://hrms.adorableit.com/dashboard/assets/css/bootstrap.min.css"
    >

    <link
        rel="stylesheet"
        href="https://hrms.adorableit.com/dashboard/assets/fonts/remix/remixicon.css"
    >

    <link
        rel="stylesheet"
        href="https://hrms.adorableit.com/dashboard/assets/css/landing.css"
    >

    <style>

        body {
            margin: 0;
            background: #f7f8fc;
            font-family: Inter, sans-serif;
        }

        .apply-page {
            padding: 140px 0 70px;
        }

        .apply-wrapper {
            max-width: 850px;
            margin: 0 auto;
        }

        .apply-back {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 25px;
            text-decoration: none;
            font-weight: 600;
        }

        .apply-title {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 40px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .apply-subtitle {
            color: #6b7280;
            margin-bottom: 30px;
        }

        .apply-card {
            background: #ffffff;
            padding: 35px;
            border-radius: 18px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.06);
        }

        .job-info {
            background: #f5f7ff;
            border: 1px solid #e5e7ff;
            padding: 18px;
            border-radius: 12px;
            margin-bottom: 30px;
        }

        .job-info small {
            display: block;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .job-info strong {
            font-size: 18px;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .required {
            color: #dc3545;
        }

        .form-control-custom {
            width: 100%;
            padding: 13px 15px;
            border: 1px solid #dfe3ea;
            border-radius: 9px;
            font-size: 15px;
            outline: none;
            box-sizing: border-box;
            background: #fff;
        }

        .form-control-custom:focus {
            border-color: #635bff;
            box-shadow: 0 0 0 3px rgba(99, 91, 255, 0.10);
        }

        textarea.form-control-custom {
            resize: vertical;
        }

        input[type="file"].form-control-custom {
            padding: 10px;
        }

        .resume-help {
            display: block;
            margin-top: 7px;
            color: #777;
            font-size: 13px;
        }

        .submit-button {
            width: 100%;
            border: 0;
            padding: 15px 20px;
            border-radius: 9px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }

        @media (max-width: 767px) {

            .apply-page {
                padding: 110px 15px 50px;
            }

            .apply-title {
                font-size: 30px;
            }

            .apply-card {
                padding: 22px;
            }

        }

    </style>

</head>


<body class="careers">


<header class="lp-header scrolled">

    <div class="container lp-nav">

        <a
            class="lp-logo"
            href="https://adorableit.com/"
        >

            <img
                src="https://hrms.adorableit.com/dashboard/assets/images/adorableIT_dark_logo.png"
                alt="Adorable IT Services"
            >

        </a>


        <nav class="lp-menu">

            <a href="https://adorableit.com/">
                Home
            </a>

            <a
                href="https://hrms.adorableit.com/find-jobs.php"
                class="active"
            >
                Find Jobs
            </a>

            <a href="https://adorableit.com/#hr-services">
                HR Services
            </a>

            <a href="https://adorableit.com/#contact">
                Contact
            </a>

        </nav>


        <div class="lp-actions">

            <a
                class="lp-btn lp-btn-primary lp-header-cta"
                href="https://hrms.adorableit.com/sign-in.php"
            >

                <i class="ri-login-box-line"></i>

                Sign in

            </a>

        </div>

    </div>

</header>


<main>

<section class="apply-page">

    <div class="container">

        <div class="apply-wrapper">


            <a
                href="javascript:history.back()"
                class="apply-back"
            >

                <i class="ri-arrow-left-line"></i>

                Back to Job

            </a>


            <h1 class="apply-title">
                Apply Now
            </h1>


            <p class="apply-subtitle">
                Submit your application for this position.
            </p>


            <div class="apply-card">


                <div class="job-info">

                    <small>
                        Applying for this job
                    </small>

                </div>


                <form
                    method="POST"
                    action="/job-application/submit.php"
                    enctype="multipart/form-data"
                    >

                    <!-- Security Nonce -->
                    <input
                        type="hidden"
                        name="job_application_nonce"
                        value=""
                    >

                    <!-- Job ID -->
                    <input
                        type="hidden"
                        name="job_id"
                        value=""
                    >

                    <!-- Job Title -->
                    <input
                        type="hidden"
                        name="job_title"
                        value=""
                    >


                    <!-- Full Name -->
                    <div class="form-group">
                        <label>
                            Full Name
                            <span class="required">*</span>
                        </label>

                        <input
                            type="text"
                            name="name"
                            class="form-control-custom"
                            placeholder="Enter your full name"
                            required
                        >
                    </div>


                    <!-- Email -->
                    <div class="form-group">
                        <label>
                            Email Address
                            <span class="required">*</span>
                        </label>

                        <input
                            type="email"
                            name="email"
                            class="form-control-custom"
                            placeholder="Enter your email"
                            required
                        >
                    </div>


                    <!-- Phone -->
                    <div class="form-group">
                        <label>
                            Phone Number
                            <span class="required">*</span>
                        </label>

                        <input
                            type="tel"
                            name="phone"
                            class="form-control-custom"
                            placeholder="Enter your phone number"
                            required
                        >
                    </div>


                    <!-- Work Experience -->
                    <div class="form-group">
                        <label>
                            Experience
                        </label>

                        <input
                            type="text"
                            name="work_exp"
                            class="form-control-custom"
                            placeholder="e.g. 4 years"
                        >
                    </div>


                    <!-- Key Skills -->
                    <div class="form-group">
                        <label>
                            Key Skills
                        </label>

                        <input
                            type="text"
                            name="key_skills"
                            class="form-control-custom"
                            placeholder="e.g. PHP, WordPress, MySQL, Laravel"
                        >
                    </div>


                    <!-- Current CTC -->
                    <div class="form-group">
                        <label>
                            Current CTC
                        </label>

                        <input
                            type="text"
                            name="current_ctc"
                            class="form-control-custom"
                            placeholder="e.g. 3.5 LPA"
                        >
                    </div>


                    <!-- Expected CTC -->
                    <div class="form-group">
                        <label>
                            Expected CTC
                        </label>

                        <input
                            type="text"
                            name="expected_ctc"
                            class="form-control-custom"
                            placeholder="e.g. 5 LPA"
                        >
                    </div>


                    <!-- Current Location -->
                    <div class="form-group">
                        <label>
                            Current Location
                        </label>

                        <input
                            type="text"
                            name="current_location"
                            class="form-control-custom"
                            placeholder="e.g. Noida, Uttar Pradesh"
                        >
                    </div>


                    <!-- Preferred Location -->
                    <div class="form-group">
                        <label>
                            Preferred Location
                        </label>

                        <input
                            type="text"
                            name="preferred_location"
                            class="form-control-custom"
                            placeholder="e.g. Noida / Delhi NCR"
                        >
                    </div>


                    <!-- Current Employer -->
                    <div class="form-group">
                        <label>
                            Current Employer
                        </label>

                        <input
                            type="text"
                            name="current_employer"
                            class="form-control-custom"
                            placeholder="Enter your current company"
                        >
                    </div>


                    <!-- Designation -->
                    <div class="form-group">
                        <label>
                            Designation
                        </label>

                        <input
                            type="text"
                            name="designation"
                            class="form-control-custom"
                            placeholder="e.g. WordPress Developer"
                        >
                    </div>


                    <!-- Education -->
                    <div class="form-group">
                        <label>
                            Education
                        </label>

                        <input
                            type="text"
                            name="education"
                            class="form-control-custom"
                            placeholder="e.g. B.Tech / Diploma"
                        >
                    </div>


                    <!-- Notice Period -->
                    <div class="form-group">
                        <label>
                            Notice Period
                        </label>

                        <input
                            type="text"
                            name="notice_period"
                            class="form-control-custom"
                            placeholder="e.g. Immediate / 30 Days"
                        >
                    </div>


                    <!-- Resume -->
                    <div class="form-group">
                        <label>
                            Resume
                            <span class="required">*</span>
                        </label>

                        <input
                            type="file"
                            name="resume"
                            class="form-control-custom"
                            accept=".pdf,.doc,.docx"
                            required
                        >

                        <small class="resume-help">
                            PDF, DOC or DOCX — Maximum 5 MB
                        </small>
                    </div>


                    <!-- Cover Letter -->
                    <div class="form-group">
                        <label>
                            Cover Letter
                        </label>

                        <textarea
                            name="notes"
                            class="form-control-custom"
                            rows="6"
                            placeholder="Write a short message..."
                        ></textarea>
                    </div>


                    <!-- Submit -->
                    <button
                        type="submit"
                        name="submit"
                        value="1"
                        class="lp-btn lp-btn-primary submit-button"
                    >
                        <i class="ri-send-plane-2-line"></i>
                        Submit Application
                    </button>

                </form>

            </div>

        </div>

    </div>

</section>

</main>


<footer class="lp-footer">

    <div class="container">

        <div class="lp-footer-bottom">

            <span>
                © <?= date('Y') ?> Adorable IT Services. All rights reserved.
            </span>

            <span>

                <a href="https://adorableit.com/">
                    Home
                </a>

                ·

                <a href="https://hrms.adorableit.com/find-jobs.php">
                    Find Jobs
                </a>

                ·

                <a href="https://adorableit.com/#contact">
                    Contact
                </a>

            </span>

        </div>

    </div>

</footer>


</body>

</html>