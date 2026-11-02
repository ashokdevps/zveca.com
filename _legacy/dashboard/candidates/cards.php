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
                Doctors Cards
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
              <div class="col-sm-12 col-12">
                <div class="card mb-3">
                  <div class="card-body">

                    <!-- Row starts -->
                    <div class="d-flex align-items-end gap-2">
                      <div class="m-0">
                        <label class="form-label" for="a1">Doctor Name</label>
                        <input type="text" class="form-control" id="a1" placeholder="Search By Doctors Name">
                      </div>
                      <div class="m-0">
                        <label class="form-label" for="a2">Department</label>
                        <input type="text" class="form-control" id="a2" placeholder="Search By Department">
                      </div>
                      <div class="m-0">
                        <button class="btn btn-primary">Search</button>
                      </div>
                    </div>
                    <!-- Row ends -->

                  </div>
                </div>
              </div>

              <?php
                  $conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");
                  if ($conn == true) {
                    $cmd = "select * from candidates";
                    $r = mysqli_query($conn, $cmd);
                    if (mysqli_num_rows($r)>0) {
                      while ($rs = mysqli_fetch_assoc($r)) { ?>

              <div class="col-xxl-3 col-sm-6 col-12">
                <div class="card mb-3">
                  <div class="card-body">
                    <div class="text-center">
                      <a class="d-flex align-items-center flex-column">
                        <img src="../assets/images/user1.png" alt="Hospitals Admin Template" class="img-7x rounded-circle mb-3">
                        <h5><?php echo $rs["candidate_name"]; ?></h5>
                        <h6 class="text-truncate"><?php echo $rs["designation"]; ?></h6>
                        <p><?php echo $rs["work_exp"]; ?></p>
                      </a>
                      <!-- <div class="rating-stars m-auto mb-3">
                        <div class="readonly5" title="Bootstrap Gallery" style="width: 120px;"><img src="img/../assets/images/star-selected.svg" alt="1" title="Bootstrap Gallery">&nbsp;<img src="img/../assets/images/star-selected.svg" alt="2" title="Bootstrap Gallery">&nbsp;<img src="img/../assets/images/star-selected.svg" alt="3" title="Bootstrap Gallery">&nbsp;<img src="img/../assets/images/star-selected.svg" alt="4" title="Bootstrap Gallery">&nbsp;<img src="img/../assets/images/star-selected.svg" alt="5" title="Bootstrap Gallery"><input type="hidden" name="score" value="5" readonly="readonly"></div>
                      </div> -->
                      <a href="show.php?s=<?php echo $rs["id"]; ?>" class="btn btn-primary">
                        View Details
                      </a>
                    </div>
                  </div>
                </div>
              </div>
              <?php
                }
                  } else {
                    echo "No Record Found!";
                  }
                } else {
                  mysqli_error($conn);
                }
                ?>
            </div>
            <!-- Row ends -->

          </div>
          <!-- App body ends -->

          <!-- App footer starts -->
          <div class="app-footer bg-white">
            <span>© Medflex admin 2024</span>
          </div>
          <!-- App footer ends -->

        </div>
<?php include '../includes/footer.php'; ?>