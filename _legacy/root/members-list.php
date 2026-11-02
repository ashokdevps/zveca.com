<?php include 'includes/header.php'; ?>
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
        Members List
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
          <div class="card-header d-flex align-items-center justify-content-between">
            <h5 class="card-title">Members List</h5>
            
            <a href="add-member.php" class="btn btn-primary ms-auto">Add Member</a>
          </div>
          <div class="card-body">

            <!-- Table starts -->
            <div class="table-responsive">
              <table id="basicExample" class="table truncate m-0 align-middle">
                <thead>
                  <tr>
                    <th>No.</th>
                    <th>Member Name</th>
                    <th>Designation</th>
                    <th>Mobile</th>
                    <th>Email</th>
                    <th>DOB</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $conn = mysqli_connect("localhost", "root", "", "login_register");
                  if ($conn == true) {
                    $cmd = "select * from it_members";
                    $r = mysqli_query($conn, $cmd);
                    if (mysqli_num_rows($r)>0) {
                      while ($rs = mysqli_fetch_assoc($r)) {
                        echo "<tr>
                    <td>".$rs["id"]."</td>
                    <td>".$rs["member_name"]."</td>
                    <td>".$rs["designation"]."</td>
                    <td>".$rs["contact"]."</td>
                    <td>".$rs["email"]."</td>
                    <td>".$rs["dob"]."</td>
                    <td>
                      <div class='d-inline-flex gap-1'>
                        <a href='m_delete.php?d=".$rs["id"]."' class='btn btn-outline-danger btn-sm'
                          data-bs-toggle='tooltip' data-bs-placement='top' data-bs-title='Delete User Details'>
                          <i class='ri-delete-bin-line'></i>
                        </a>
                        <a href='m_edit.php?u=".$rs["id"]."' class='btn btn-outline-success btn-sm'
                          data-bs-toggle='tooltip' data-bs-placement='top' data-bs-title='Edit User Details'>
                          <i class='ri-edit-box-line'></i>
                        </a>
                        <a href='m_show.php?s=".$rs["id"]."' class='btn btn-outline-info btn-sm'
                          data-bs-toggle='tooltip' data-bs-placement='top' data-bs-title='View List'>
                          <i class='ri-eye-line'></i>
                        </a>
                      </div>
                    </td>
                    </tr>";
                      }
                    } else {
                      echo "No Record Found!";
                    }
                  } else {
                    mysqli_error($conn);
                  }
                  ?>
                </tbody>
              </table>
            </div>
            <!-- Table ends -->

            <!-- Modal Delete Row -->
            <div class="modal fade" id="delRow" tabindex="-1" aria-labelledby="delRowLabel" aria-hidden="true">
              <div class="modal-dialog modal-sm">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title" id="delRowLabel">
                      Confirm
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    Are you sure you want to delete the patient?
                  </div>
                  <div class="modal-footer">
                    <div class="d-flex justify-content-end gap-2">
                      <button class="btn btn-outline-secondary" data-bs-dismiss="modal"
                        aria-label="Close">No</button>
                      <button class="btn btn-danger" data-bs-dismiss="modal" aria-label="Close">Yes</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
    <!-- Row ends -->

  </div>
  <!-- App body ends -->

  <!-- App footer starts -->
  <div class="app-footer bg-white">
    <span>© Adorableit admin 2025</span>
  </div>
  <!-- App footer ends -->

</div>
<?php include 'includes/footer.php'; ?>