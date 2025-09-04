<!-- includes/sidebar.php - v2.0 (SaaS) with GSAP Animations -->
<nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse" id="mainSidebar">
    <div class="position-sticky pt-3">
        <div class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-decoration-none px-3 sidebar-brand">
            <i class="bi bi-people-fill fs-4 me-2 text-primary"></i>
            <span class="fs-5 fw-semibold"><?php echo htmlspecialchars($_SESSION['associazione_nome'] ?? 'Manager'); ?></span>
        </div>
        
        <hr>

        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted text-uppercase">
            <span>Menu Principale</span>
        </h6>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo (!isset($_GET['page']) || $_GET['page'] == 'dashboard') ? 'active' : ''; ?>" href="index.php?page=dashboard">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'soci') ? 'active' : ''; ?>" href="index.php?page=soci">
                    <i class="bi bi-people me-2"></i>
                    Soci
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'tessere') ? 'active' : ''; ?>" href="index.php?page=tessere">
                    <i class="bi bi-credit-card-2-front me-2"></i>
                    Tessere
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'quote') ? 'active' : ''; ?>" href="index.php?page=quote">
                    <i class="bi bi-credit-card me-2"></i>
                    Quote
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'eventi') ? 'active' : ''; ?>" href="index.php?page=eventi">
                    <i class="bi bi-calendar-event me-2"></i>
                    Eventi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'comunicazioni') ? 'active' : ''; ?>" href="index.php?page=comunicazioni">
                    <i class="bi bi-envelope me-2"></i>
                    Comunicazioni
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'documenti') ? 'active' : ''; ?>" href="index.php?page=documenti">
                    <i class="bi bi-file-earmark-text me-2"></i>
                    Documenti
                </a>
            </li>
        </ul>

        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted text-uppercase">
            <span>Amministrazione</span>
        </h6>
        <ul class="nav flex-column mb-2">
            <?php if (($_SESSION['user_role'] ?? '') === 'super_admin'): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'associazioni') ? 'active' : ''; ?>" href="index.php?page=associazioni">
                    <i class="bi bi-buildings me-2"></i>
                    Associazioni
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'configurazioni') ? 'active' : ''; ?>" href="index.php?page=configurazioni">
                    <i class="bi bi-gear me-2"></i>
                    Impostazioni
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'amministratori') ? 'active' : ''; ?>" href="index.php?page=amministratori">
                    <i class="bi bi-person-badge me-2"></i>
                    Utenti
                </a>
            </li>
            <?php 
            $role = $_SESSION['user_role'] ?? ''; 
            $has_assoc = !empty($_SESSION['associazione_id'] ?? null);
            if ($has_assoc): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'configurazioni' && (isset($_GET['section']) && $_GET['section']==='sedi')) ? 'active' : ''; ?>" href="index.php?page=configurazioni&section=sedi">
                    <i class="bi bi-geo-alt me-2"></i>
                    Sedi
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'config_campi') ? 'active' : ''; ?>" href="index.php?page=config_campi">
                    <i class="bi bi-journal-plus me-2"></i>
                    Campi Personalizzati
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'config_tags') ? 'active' : ''; ?>" href="index.php?page=config_tags">
                    <i class="bi bi-tags me-2"></i>
                    Gestione Tag
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'categorie-socio') ? 'active' : ''; ?>" href="index.php?page=categorie-socio">
                    <i class="bi bi-bookmark-star me-2"></i>
                    Categorie Socio
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'tipi-socio') ? 'active' : ''; ?>" href="index.php?page=tipi-socio">
                    <i class="bi bi-person-badge me-2"></i>
                    Tipi Socio
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'config_gruppi') ? 'active' : ''; ?>" href="index.php?page=config_gruppi">
                    <i class="bi bi-collection me-2"></i>
                    Gruppi Dinamici
                </a>
            </li>
        </ul>
        
        <hr>
        
        <div class="px-3 mt-auto">
            <div class="d-flex align-items-center mb-2">
                <i class="bi bi-person-circle fs-4 me-2"></i>
                <div class="d-flex flex-column lh-1">
                    <span class="small fw-bold"><?php echo htmlspecialchars($_SESSION['user_nome_completo'] ?? 'Utente'); ?></span>
                    <span class="small text-muted"><?php echo htmlspecialchars($_SESSION['user_role'] ?? 'ruolo'); ?></span>
                </div>
            </div>
            <a href="auth/logout.php" class="btn btn-outline-danger btn-sm w-100">
                <i class="bi bi-box-arrow-right me-2"></i>
                Logout
            </a>
        </div>
    </div>
</nav>
