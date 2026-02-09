<!-- includes/sidebar.php - v2.0 (SaaS) with GSAP Animations -->
<nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse" id="mainSidebar">
    <div class="position-sticky pt-3">
        <div class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-decoration-none px-3 sidebar-brand">
            <i class="bi bi-people-fill fs-4 me-2 text-primary"></i>
            <span class="fs-5 fw-semibold"><?php echo htmlspecialchars($_SESSION['associazione_nome'] ?? 'Manager'); ?></span>
        </div>

        <?php if (($_SESSION['user_role'] ?? '') === 'super_admin'): ?>
        <?php
        try {
            $sidebar_assoc_list = $pdo->query("SELECT id, nome FROM associazioni WHERE attiva = 1 ORDER BY nome")->fetchAll();
        } catch (PDOException $e) {
            $sidebar_assoc_list = [];
        }
        $sidebar_current_id = $_SESSION['associazione_id'] ?? null;
        $sidebar_current_nome = $_SESSION['associazione_nome'] ?? '';
        ?>
        <div class="sidebar-assoc-picker">
            <label class="sidebar-assoc-label"><i class="bi bi-building me-1"></i>Associazione</label>
            <select class="sidebar-assoc-select" onchange="if(this.value){window.location='index.php?page=dashboard&assoc_id='+this.value}else{window.location='index.php?page=dashboard&switch_assoc=1'}">
                <option value="">-- Seleziona --</option>
                <?php foreach ($sidebar_assoc_list as $sa): ?>
                    <option value="<?php echo htmlspecialchars($sa['id']); ?>" <?php echo ((string)$sidebar_current_id === (string)$sa['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sa['nome']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <hr>

        <?php $sidebar_has_assoc = !empty($_SESSION['associazione_id']); ?>

        <?php if ($sidebar_has_assoc): ?>
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
            <?php $emailPages = ['comunicazioni','email-templates','email-coda','email-log','email-impostazioni']; $emailActive = isset($_GET['page']) && in_array($_GET['page'], $emailPages, true); ?>
            <li class="nav-item">
                <a class="nav-link d-flex justify-content-between align-items-center <?php echo $emailActive ? '' : 'collapsed'; ?>" data-bs-toggle="collapse" href="#emailSubmenu" role="button" aria-expanded="<?php echo $emailActive ? 'true' : 'false'; ?>">
                    <span><i class="bi bi-envelope me-2"></i>Email &amp; Notifiche</span>
                    <i class="bi bi-chevron-down small"></i>
                </a>
                <div class="collapse <?php echo $emailActive ? 'show' : ''; ?>" id="emailSubmenu">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item"><a class="nav-link py-1 <?php echo (($_GET['page'] ?? '') === 'comunicazioni') ? 'active' : ''; ?>" href="index.php?page=comunicazioni"><i class="bi bi-send me-2"></i>Invia Comunicazione</a></li>
                        <li class="nav-item"><a class="nav-link py-1 <?php echo (($_GET['page'] ?? '') === 'email-templates') ? 'active' : ''; ?>" href="index.php?page=email-templates"><i class="bi bi-palette me-2"></i>Template Email</a></li>
                        <li class="nav-item"><a class="nav-link py-1 <?php echo (($_GET['page'] ?? '') === 'email-coda') ? 'active' : ''; ?>" href="index.php?page=email-coda"><i class="bi bi-hourglass-split me-2"></i>Coda Invio</a></li>
                        <li class="nav-item"><a class="nav-link py-1 <?php echo (($_GET['page'] ?? '') === 'email-log') ? 'active' : ''; ?>" href="index.php?page=email-log"><i class="bi bi-clock-history me-2"></i>Storico Email</a></li>
                        <li class="nav-item"><a class="nav-link py-1 <?php echo (($_GET['page'] ?? '') === 'email-impostazioni') ? 'active' : ''; ?>" href="index.php?page=email-impostazioni"><i class="bi bi-gear me-2"></i>Impostazioni SMTP</a></li>
                    </ul>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'documenti') ? 'active' : ''; ?>" href="index.php?page=documenti">
                    <i class="bi bi-file-earmark-text me-2"></i>
                    Documenti
                </a>
            </li>
        </ul>
        <?php else: ?>
        <div class="px-3 mt-4 mb-3">
            <div class="alert alert-warning small mb-0 py-2">
                <i class="bi bi-info-circle me-1"></i>Seleziona un'associazione per accedere al menu.
            </div>
        </div>
        <?php endif; ?>

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
            <?php if ($sidebar_has_assoc): ?>
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
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] == 'configurazioni' && (isset($_GET['section']) && $_GET['section']==='sedi')) ? 'active' : ''; ?>" href="index.php?page=configurazioni&section=sedi">
                    <i class="bi bi-geo-alt me-2"></i>
                    Sedi
                </a>
            </li>
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
            <?php endif; ?>
            <?php if ($sidebar_has_assoc || ($_SESSION['user_role'] ?? '') === 'super_admin'): ?>
            <?php $apiPages = ['api-keys', 'api-docs']; $apiActive = isset($_GET['page']) && in_array($_GET['page'], $apiPages, true); ?>
            <li class="nav-item">
                <a class="nav-link d-flex justify-content-between align-items-center <?php echo $apiActive ? '' : 'collapsed'; ?>" data-bs-toggle="collapse" href="#apiSubmenu" role="button" aria-expanded="<?php echo $apiActive ? 'true' : 'false'; ?>">
                    <span><i class="bi bi-hdd-network me-2"></i>API REST</span>
                    <i class="bi bi-chevron-down small"></i>
                </a>
                <div class="collapse <?php echo $apiActive ? 'show' : ''; ?>" id="apiSubmenu">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item"><a class="nav-link py-1 <?php echo (($_GET['page'] ?? '') === 'api-keys') ? 'active' : ''; ?>" href="index.php?page=api-keys"><i class="bi bi-key me-2"></i>Chiavi API</a></li>
                        <li class="nav-item"><a class="nav-link py-1 <?php echo (($_GET['page'] ?? '') === 'api-docs') ? 'active' : ''; ?>" href="index.php?page=api-docs"><i class="bi bi-book me-2"></i>Documentazione</a></li>
                    </ul>
                </div>
            </li>
            <?php endif; ?>
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
