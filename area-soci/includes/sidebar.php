<nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
    <div class="position-sticky pt-3">
        <div class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-decoration-none px-3">
            <i class="bi bi-person-fill fs-4 me-2 text-primary"></i>
            <span class="fs-5 fw-semibold">Area Socio</span>
        </div>
        
        <hr>

        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted text-uppercase">
            <span>Menu Socio</span>
        </h6>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo (!isset($_GET['page']) || $_GET['page'] == 'dashboard') ? 'active' : ''; ?>" href="index.php?page=dashboard">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'profilo') ? 'active' : ''; ?>" href="index.php?page=profilo">
                    <i class="bi bi-person me-2"></i>
                    Il Mio Profilo
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'quote') ? 'active' : ''; ?>" href="index.php?page=quote">
                    <i class="bi bi-credit-card me-2"></i>
                    Le Mie Quote
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'tessere') ? 'active' : ''; ?>" href="index.php?page=tessere">
                    <i class="bi bi-credit-card-2-front me-2"></i>
                    Le Mie Tessere
                </a>
            </li>
            <!-- Aggiungi qui altri link futuri -->
        </ul>
        
        <hr>
        <div class="px-3 mt-auto">
            <div class="d-flex align-items-center mb-2">
                <i class="bi bi-person-circle fs-4 me-2"></i>
                <div class="d-flex flex-column lh-1">
                    <span class="small fw-bold"><?php echo htmlspecialchars($_SESSION['socio_nome'] ?? 'Socio'); ?></span>
                </div>
            </div>
            <a href="logout.php" class="btn btn-outline-danger btn-sm w-100">
                <i class="bi bi-box-arrow-right me-2"></i>
                Logout
            </a>
        </div>
    </div>
</nav>
