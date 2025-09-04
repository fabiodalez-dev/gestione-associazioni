<?php
// pages/404.php
?>
<div class="container text-center py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <i class="bi bi-exclamation-triangle display-1 text-warning"></i>
            <h1 class="display-4 fw-bold">404</h1>
            <h2 class="mb-4">Pagina non trovata</h2>
            <p class="lead mb-4">
                La pagina che stai cercando non esiste o è stata spostata.
            </p>
            <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
                <a href="index.php" class="btn btn-primary btn-lg px-4 gap-3">
                    <i class="bi bi-house-door"></i> Torna alla dashboard
                </a>
                <a href="javascript:history.back()" class="btn btn-outline-secondary btn-lg px-4">
                    <i class="bi bi-arrow-left"></i> Indietro
                </a>
            </div>
            
            <div class="mt-5">
                <h5>Pagine disponibili:</h5>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                            <li><a href="index.php?page=soci" class="text-decoration-none">Gestione Soci</a></li>
                            <li><a href="index.php?page=eventi" class="text-decoration-none">Eventi</a></li>
                            <li><a href="index.php?page=quote" class="text-decoration-none">Quote e Pagamenti</a></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li><a href="index.php?page=comunicazioni" class="text-decoration-none">Comunicazioni</a></li>
                            <li><a href="index.php?page=documenti" class="text-decoration-none">Documenti</a></li>
                            <li><a href="index.php?page=configurazioni" class="text-decoration-none">Configurazioni</a></li>
                            <li><a href="index.php?page=amministratori" class="text-decoration-none">Amministratori</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>