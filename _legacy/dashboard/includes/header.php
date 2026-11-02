<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
    <meta name="description" content="Marketplace for Bootstrap Admin Dashboards">
    <meta name="author" content="Bootstrap Gallery">
    <link rel="canonical" href="https://www.bootstrap.gallery/">
    <meta property="og:url" content="https://www.bootstrap.gallery/">
    <meta property="og:title" content="Admin Templates - Dashboard Templates | Bootstrap Gallery">
    <meta property="og:description" content="Marketplace for Bootstrap Admin Dashboards">
    <meta property="og:type" content="Website">
    <meta property="og:site_name" content="Bootstrap Gallery">
    <link rel="shortcut icon" href="https://adorableit.com/wp-content/uploads/2025/03/cropped-adorableIT_favicon.png">
    <link rel="stylesheet" href="../assets/fonts/remix/remixicon.css">
    <link rel="stylesheet" href="../assets/css/main.min.css">
    <link rel="stylesheet" href="../assets/vendor/overlay-scroll/OverlayScrollbars.min.css">
    
    <!-- Data Tables -->
    <link rel="stylesheet" href="../assets/vendor/datatables/dataTables.bs5.css">
    <link rel="stylesheet" href="../assets/vendor/datatables/dataTables.bs5-custom.css">
    <link rel="stylesheet" href="../assets/vendor/datatables/buttons/dataTables.bs5-custom.css">
</head>
  <body>
    <div id="loading-wrapper">
      <div class='spin-wrapper'>
        <div class='spin'>
          <div class='inner'></div>
        </div>
        <div class='spin'>
          <div class='inner'></div>
        </div>
        <div class='spin'>
          <div class='inner'></div>
        </div>
        <div class='spin'>
          <div class='inner'></div>
        </div>
        <div class='spin'>
          <div class='inner'></div>
        </div>
        <div class='spin'>
          <div class='inner'></div>
        </div>
      </div>
    </div>
    <div class="page-wrapper">
      <div class="app-header d-flex align-items-center">
        <div class="d-flex">
          <button class="toggle-sidebar">
            <i class="ri-menu-line"></i>
          </button>
          <button class="pin-sidebar">
            <i class="ri-menu-line"></i>
          </button>
        </div>
        <div class="app-brand ms-3">
          <a href="https://hrms.adorableit.com/dashboard/" class="d-lg-block d-none">
            <img src="../assets/images/adorableit-logo-light.png" class="logo" alt="Admin">
          </a>
          <a href="https://hrms.adorableit.com/dashboard/" class="d-lg-none d-md-block">
            <img src="../assets/images/logo-sm.svg" class="logo" alt="Admin">
          </a>
        </div>
        <div class="header-actions">
          <div class="search-container d-lg-block d-none mx-3">
            <input type="text" class="form-control" id="searchId" placeholder="Search">
            <i class="ri-search-line"></i>
          </div>
          <div class="d-lg-flex d-none gap-2">
            
          </div>
          <div class="dropdown ms-2">
            <a id="userSettings" class="dropdown-toggle d-flex align-items-center" href="#!" role="button"
              data-bs-toggle="dropdown" aria-expanded="false">
              <!-- <div class="sidebar-profile">
                <img src="../assets/images/user6.png" class="img-shadow img-3x me-3 rounded-5" alt="Hospital Admin Templates">
                <div class="m-0">
                  <h5 class="mb-1 profile-name text-nowrap text-truncate">Ashok Kumar</h5>
                  <p class="m-0 small profile-name text-nowrap text-truncate">Dev Admin</p>
                </div>
              </div> -->
              <div class="avatar-box"> <img src="../assets/images/user6.png" class="img-shadow img-3x me-3 rounded-5" alt="Hospital Admin Templates"><span class="status busy"></span></div>
            </a>
            <div class="dropdown-menu dropdown-menu-end shadow-lg">
              <div class="px-3 py-2">
                <span class="small">Admin</span>
                <h6 class="m-0">Ashok Kumar</h6>
              </div>
              <div class="mx-3 my-2 d-grid">
                <a href="logout.php" class="btn btn-danger">Logout</a>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="main-container">
      <nav id="sidebar" class="sidebar-wrapper">
        <div class="sidebar-profile">
          <img src="../assets/images/user6.png" class="img-shadow img-3x me-3 rounded-5" alt="Hospital Admin Templates">
          <div class="m-0">
            <h5 class="mb-1 profile-name text-nowrap text-truncate">Ashok Kumar</h5>
            <p class="m-0 small profile-name text-nowrap text-truncate">Dev Admin</p>
          </div>
        </div>
        <div class="sidebarMenuScroll">
          <ul class="sidebar-menu">
            <li class="treeview active current-page">
              <a href="#!">
                <i class="ri-heart-pulse-line"></i>
                <span class="menu-text">Dashboard</span>
              </a>
              <!--<ul class="treeview-menu">-->
              <!--  <li>-->
              <!--    <a href="pindex.php">Client Dashboard</a>-->
              <!--  </li>-->
              <!--  <li>-->
              <!--    <a href="index.php">Vendor Dashboard</a>-->
              <!--  </li>-->
              <!--  <li>-->
              <!--    <a href="index.php">Employee Dashboard</a>-->
              <!--  </li>-->
              <!--</ul>-->
            </li>
            
              
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Candidates</span>
                </a>
                <ul class="treeview-menu active-sub">
                  <li>
                    <a href="/dashboard/candidates/list.php">All Candidates</a>
                  </li>
                  <li>
                    <a href="/dashboard/candidates/cards.php">Candidate Cards</a>
                  </li>
                  <li>
                    <a href="/dashboard/candidates/add.php">Add Candidates</a>
                  </li>
                  <li>
                    <a href="/dashboard/candidates/edit-user.php">Edit Candidates</a>
                  </li>
                  <!-- <li>
                    <a href="#">Employee Shift</a>
                  </li>
                  <li>
                    <a href="#">Employee Profile</a>
                  </li> -->
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Projects</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/projects/list.php">All Project</a>
                  </li>
                  <li>
                    <a href="/projects/add.php">Add Project</a>
                  </li>
                  <!-- <li>
                    <a href="#">Estimates</a>
                  </li>
                  <li>
                    <a href="#">Project Details</a>
                  </li> -->
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Employees</span>
                </a>
                <ul class="treeview-menu active-sub">
                  <li>
                    <a href="/employees/list.php">All Employee</a>
                  </li>
                  <li>
                    <a href="/employees/add.php">Add Employee</a>
                  </li>
                  <li>
                    <a href="/employees/edit-user.php">Edit Employee</a>
                  </li>
                  <!-- <li>
                    <a href="#">Employee Shift</a>
                  </li>
                  <li>
                    <a href="#">Employee Profile</a>
                  </li> -->
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Leave Management</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/leave_management/list.php">All Leave Request</a>
                  </li>
                  <li>
                    <a href="/leave_management/add.php">Leave Balance</a>
                  </li>
                  <!-- <li>
                    <a href="#">Leave Types</a>
                  </li>
                  <li>
                    <a href="#">Leave Settings</a>
                  </li> -->
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Attendance</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/attendance/list.php">Today's Attendance</a>
                  </li>
                  <li>
                    <a href="/attendance/add.php">Employee Attendance</a>
                  </li>
                  <!-- <li>
                    <a href="#">Attendance Sheet</a>
                  </li> -->
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Holidays</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/holidays/list.php">All Holidays</a>
                  </li>
                  <li>
                    <a href="/holidays/add.php">Add Holidays</a>
                  </li>
                  <!-- <li>
                    <a href="#">Edit Holidays</a>
                  </li> -->
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Clients</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/clients/list.php">All Clients</a>
                  </li>
                  <li>
                    <a href="/clients/add.php">Add Clients</a>
                  </li>
                  <!-- <li>
                    <a href="#">Edit Clients</a>
                  </li> -->
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Payroll</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/payroll/list.php">Employee Salary</a>
                  </li>
                  <li>
                    <a href="/payroll/add.php">Payslip</a>
                  </li>
                </ul>
              </li>
              <li class="">
                <a href="#">
                  <i class="ri-home-5-line"></i>
                  <span class="menu-text">Leader</span>
                </a>
              </li>
               <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Jobs</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/jobs/list.php">Jobs List</a>
                  </li>
                  <li>
                    <a href="/jobs/add.php">Resumes</a>
                  </li>
                  <!-- <li>
                    <a href="#">Candidates</a>
                  </li>
                  <li>
                    <a href="#">Shortlist Candidates</a>
                  </li> -->
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Departments</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/departments/list.php">All Departments</a>
                  </li>
                  <li>
                    <a href="/departments/add.php">Add Department</a>
                  </li>
                  <!-- <li>
                    <a href="#">Edit Department</a>
                  </li> -->
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Training</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/training/list.php">Training List</a>
                  </li>
                  <li>
                    <a href="/training/add.php">Trainers</a>
                  </li>
                  <!-- <li>
                    <a href="#">Training Types</a>
                  </li> -->
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Accounts</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/accounts/list.php">Client Payments</a>
                  </li>
                  <li>
                    <a href="/accounts/add.php">Add Payments</a>
                  </li>
                  <li>
                    <a href="#">Invoice</a>
                  </li>
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Reports</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/reports/list.php">Leave Report</a>
                  </li>
                  <li>
                    <a href="/reports/list.php">Expense Reports</a>
                  </li>
                </ul>
              </li>
              <li class="">
                <a href="#">
                  <i class="ri-home-5-line"></i>
                  <span class="menu-text">Task</span>
                </a>
              </li>
              <li class="">
                <a href="#">
                  <i class="ri-home-5-line"></i>
                  <span class="menu-text">Contacts</span>
                </a>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Email</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="#">Inbox</a>
                  </li>
                  <li>
                    <a href="#">Compose</a>
                  </li>
                  <li>
                    <a href="#">Read Mail</a>
                  </li>
                </ul>
              </li>
               <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Authentication</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="#">Sign In</a>
                  </li>
                  <li>
                    <a href="#">Sign Up</a>
                  </li>
                  <li>
                    <a href="#">Forgot Password</a>
                  </li>
                  <li>
                    <a href="#">Locked</a>
                  </li>
                </ul>
              </li>
               <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Logout</span>
                </a>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-nurse-line"></i>
                  <span class="menu-text">Staff</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="/member/list.php">Members List</a>
                  </li>
                  <li>
                    <a href="/member/list.php" class="active-sub">Add Member</a>
                  </li>
                </ul>
              </li>
              <li class="treeview">
                <a href="#!">
                  <i class="ri-money-dollar-circle-line"></i>
                  <span class="menu-text">Salaries</span>
                </a>
                <ul class="treeview-menu">
                  <li>
                    <a href="salary-list.php" class="active-sub">Salary List</a>
                  </li>
                  <li>
                    <a href="#">Payslip</a>
                  </li>
                </ul>
              </li>
              <li class="treeview">
              <a href="#!">
                <i class="ri-heart-pulse-line"></i>
                <span class="menu-text">Patients</span>
              </a>
              <ul class="treeview-menu">
                <li>
                  <a href="patient-dashboard.php">Users Dashboard</a>
                </li>
                <li>
                  <a href="/employees/patients-list.php">Users List</a>
                </li>
                <li>
                  <a href="/employees/add-patient.php">Add User</a>
                </li>
                <li>
                  <a href="/employees/edit-user.php">Edit User Details</a>
                </li>
              </ul>
            </li>
          </ul>
        </div>
        <div class="sidebar-contact">
          <p class="fw-light mb-1 text-nowrap text-truncate">Emergency Contact</p>
          <h5 class="m-0 lh-1 text-nowrap text-truncate">+91-9871864942</h5>
          <i class="ri-phone-line"></i>
        </div>
      </nav>