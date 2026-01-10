<?php
session_start();
$pageTitle = "Unauthorized Access";
include 'includes/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h4><i class="fas fa-exclamation-triangle"></i> Access Denied</h4>
                </div>
                <div class="card-body">
                    <h5 class="card-title">Unauthorized Access</h5>
                    <p class="card-text">
                        You do not have permission to access this page with your current role.
                    </p>
                    <p class="text-muted">
                        Logged in as: <strong><?php echo isset($_SESSION['name']) ? $_SESSION['name'] : 'Unknown'; ?></strong><br>
                        Role: <strong><?php echo isset($_SESSION['role']) ? ucfirst($_SESSION['role']) : 'Unknown'; ?></strong>
                    </p>
                    <a href="index.php" class="btn btn-primary">Back to Home</a>
                    <a href="logout.php" class="btn btn-secondary">Logout</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>