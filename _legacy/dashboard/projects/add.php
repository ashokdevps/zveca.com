<?php include '../includes/header.php'; ?>
<div class="app-container">

  <!-- App hero header starts -->
  <div class="app-hero-header d-flex align-items-center">

    <!-- Breadcrumb starts -->
    <ol class="breadcrumb">
      <li class="breadcrumb-item">
        <i class="ri-home-8-line lh-1 pe-3 me-3 border-end"></i>
        <a href="index.html">Home</a>
      </li>
      <li class="breadcrumb-item text-primary" aria-current="page">
        Add Project
      </li>
    </ol>
    <!-- Breadcrumb ends -->
    
  </div>
  <!-- App Hero header ends -->

  <!-- App body starts -->
  <div class="app-body">

    <!-- Row starts -->
    <div class="row gx-3">
      <div class="col-sm-12">
        <div class="card">
          <div class="card-header">
            <h5 class="card-title">Add Project Details</h5>
          </div>
          <div class="card-body">

            <form action="ucode.php" method="POST">
            <!-- Row starts -->
            <div class="row gx-3">
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a1">Project Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="project_name" id="a1" placeholder="Enter Project Name">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a2">Client Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="client_name" id="a2" placeholder="Enter Client Name">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a2">Dead Line <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="deadline" id="a2" placeholder="Enter Dead Line">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a3">Team Members <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="team_members" id="a3" placeholder="Enter Team Members">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="status">Status <span class="text-danger">*</span></label>
                  <select class="form-select" name="status" id="status" required>
                    <option value="">Select Status</option>
                    <option value="Active">Active</option>
                    <option value="Pending">Pending</option>
                    <option value="Closed">Closed</option>
                  </select>
                </div>
              </div>

              <div class="col-sm-12">
                <div class="d-flex gap-2 justify-content-end">
                  <a href="list.php" type="button" class="btn btn-outline-secondary">
                    Cancel
                  </a>
                  <button type="submit" class="btn btn-primary">Save</button>
                </div>
              </div>
            </div>
            <!-- Row ends -->
            </form>

          </div>
        </div>
      </div>
    </div>
    <!-- Row ends -->

  </div>
  <!-- App body ends -->

  <!-- App footer starts -->
  <div class="app-footer bg-white">
    <span>© Adorableit 2025</span>
  </div>
  <!-- App footer ends -->

</div>
<?php include '../includes/footer.php'; ?>