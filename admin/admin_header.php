<?php
// admin/admin_header.php

?>
<nav class="navbar navbar-dark sticky-top bg-dark flex-md-nowrap p-0 shadow">
    <a class="navbar-brand col-md-3 col-lg-2 me-0 px-3" href="dashboard.php">
        <i class="fas fa-shield-alt"></i> CAAMS Admin
    </a>
    
    <div class="navbar-nav px-3">
        <div class="nav-item text-nowrap">
            <span class="nav-link text-white">
                <i class="fas fa-user"></i> <?php echo $user_name ?? 'Admin'; ?>
            </span>
        </div>
    </div>
    
    <ul class="navbar-nav px-3">
        <li class="nav-item text-nowrap">
            <a class="nav-link text-white" href="../logout.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </li>
    </ul>
</nav>