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
        Update Patient
      </li>
    </ol>
    <!-- Breadcrumb ends -->

    <!-- Sales stats starts -->
    <div class="ms-auto d-lg-flex d-none flex-row">
      <div class="d-flex flex-row gap-1 day-sorting">
        <button class="btn btn-sm btn-primary">Today</button>
        <button class="btn btn-sm">7d</button>
        <button class="btn btn-sm">2w</button>
        <button class="btn btn-sm">1m</button>
        <button class="btn btn-sm">3m</button>
        <button class="btn btn-sm">6m</button>
        <button class="btn btn-sm">1y</button>
      </div>
    </div>
    <!-- Sales stats ends -->

  </div>
  <!-- App Hero header ends -->

  <!-- App body starts -->
  <div class="app-body">

    <!-- Row starts -->
    <div class="row gx-3">
      <div class="col-sm-12">
        <div class="card">
          <div class="card-header">
            <h5 class="card-title">Update Patient Details</h5>
          </div>
          <div class="card-body">
          <?php
          $id = $_GET['u'];
          $conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");
          if ($conn == true) {
            $cmd = "select * from it_projects where id='$id'";
            $r = mysqli_query($conn, $cmd);
            if (mysqli_num_rows($r)>0) {
              while ($rs = mysqli_fetch_assoc($r)) { 
                ?>
            <form action="update.php" method="POST">
            <!-- Row starts -->
            <div class="row gx-3">
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a1">Project Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" value="<?php echo $rs['project_name']; ?>" name="project_name" id="a1" placeholder="Enter Project Name">
                  <input type="hidden" value="<?php echo $rs['id'] ?>" name="id">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a2">Client Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" value="<?php echo $rs['client_name']; ?>" name="client_name" id="a2" placeholder="Enter Client Name">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a3">Dead Line <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" value="<?php echo $rs['deadline']; ?>" name="deadline" id="a3" placeholder="Enter Dead Line">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a4">Team Members <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" value="<?php echo $rs['team_members']; ?>" name="team_members" id="a4" placeholder="Enter Team Members">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="status">Status <span class="text-danger">*</span></label>
                  <select class="form-select" name="status" id="status">
                    <option value="<?php echo $rs['status']; ?>"><?php echo $rs['status']; ?></option>
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
                  <button type="submit" class="btn btn-primary">Update</button>
                </div>
              </div>
            </div>
            <!-- Row ends -->
            </form>
            <?php
                }
              }
            } else{
              mysqli_error($conn);
            }
            ?>

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