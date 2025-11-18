<?php
/**
 * Analytics Dashboard - Complete Analytics System
 * Comprehensive analytics with charts, filters, and detailed reports
 */

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}

// Only admin/super_admin can access analytics
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
    redirect('index.php?page=dashboard');
}

// Include Analytics Manager
require_once APP_ROOT . '/lib/AnalyticsManager.php';

$analytics = new AnalyticsManager($pdo, $_SESSION['associazione_id'] ?? null);

// Handle filters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$view = $_GET['view'] ?? 'overview'; // overview, members, cards, financial, geographic, events

// Handle export
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $export_data = [];

    switch ($export_type) {
        case 'members':
            $stmt = $pdo->prepare("
                SELECT
                    CONCAT(nome, ' ', cognome) as nome_completo,
                    email,
                    telefono,
                    stato,
                    data_iscrizione,
                    citta,
                    provincia
                FROM soci
                WHERE associazione_id = ?
                ORDER BY data_iscrizione DESC
            ");
            $stmt->execute([$_SESSION['associazione_id']]);
            $export_data = $stmt->fetchAll();
            $analytics->exportToCSV($export_data, 'soci_' . date('Y-m-d') . '.csv');
            break;

        case 'cards':
            $stmt = $pdo->prepare("
                SELECT
                    t.numero_tessera,
                    CONCAT(s.nome, ' ', s.cognome) as socio,
                    t.data_emissione,
                    t.data_scadenza,
                    t.stato,
                    t.importo,
                    t.metodo_pagamento
                FROM tessere t
                JOIN soci s ON t.socio_id = s.id
                WHERE t.associazione_id = ?
                ORDER BY t.data_emissione DESC
            ");
            $stmt->execute([$_SESSION['associazione_id']]);
            $export_data = $stmt->fetchAll();
            $analytics->exportToCSV($export_data, 'tessere_' . date('Y-m-d') . '.csv');
            break;

        case 'payments':
            $stmt = $pdo->prepare("
                SELECT
                    CONCAT(s.nome, ' ', s.cognome) as socio,
                    q.importo,
                    q.stato,
                    q.data_scadenza,
                    q.data_pagamento,
                    q.metodo_pagamento
                FROM quote q
                JOIN soci s ON q.socio_id = s.id
                WHERE q.associazione_id = ?
                ORDER BY q.data_pagamento DESC
            ");
            $stmt->execute([$_SESSION['associazione_id']]);
            $export_data = $stmt->fetchAll();
            $analytics->exportToCSV($export_data, 'pagamenti_' . date('Y-m-d') . '.csv');
            break;
    }
}

// Get KPIs
$kpis = $analytics->getKPIDashboard($date_from, $date_to);

// Get chart data based on view
$members_growth = $analytics->getMembersGrowthChart(12);
$members_by_category = $analytics->getMembersByCategory();
$members_by_status = $analytics->getMembersByStatus();
$cards_emissions = $analytics->getCardsEmissionsChart(12);
$revenue_chart = $analytics->getRevenueChart(12);
$age_distribution = $analytics->getAgeDistribution();
$engagement_distribution = $analytics->getEngagementDistribution();
$geographic_data = $analytics->getGeographicDistribution('provincia');
$expiring_cards = $analytics->getExpiringCards(30);

?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Dashboard Avanzata</title>
    <style>
        .analytics-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }

        .kpi-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0.5rem 0;
        }

        .kpi-label {
            color: #6c757d;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kpi-trend {
            font-size: 0.875rem;
            font-weight: 600;
        }

        .kpi-trend.positive {
            color: #28a745;
        }

        .kpi-trend.negative {
            color: #dc3545;
        }

        .chart-container {
            position: relative;
            height: 400px;
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .chart-container canvas {
            max-height: 100%;
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #333;
        }

        .view-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .view-tab {
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            background: white;
            border: 2px solid #e9ecef;
            color: #495057;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }

        .view-tab:hover {
            border-color: #667eea;
            color: #667eea;
        }

        .view-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        .filter-panel {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .table-analytics {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .table-analytics table {
            margin-bottom: 0;
        }

        .stat-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        @media print {
            .no-print {
                display: none;
            }

            .chart-container {
                page-break-inside: avoid;
            }
        }

        .geographic-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .geo-card {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            transition: all 0.3s;
        }

        .geo-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }

        .geo-card-title {
            font-weight: 600;
            font-size: 1.125rem;
            margin-bottom: 0.5rem;
            color: #667eea;
        }

        .geo-card-value {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
        }

        .geo-card-label {
            font-size: 0.875rem;
            color: #6c757d;
        }
    </style>
</head>
<body>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="analytics-header no-print">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="mb-1">
                    <i class="bi bi-graph-up"></i> Analytics Dashboard
                </h1>
                <p class="mb-0 opacity-75">
                    Dashboard avanzata con metriche dettagliate e report esportabili
                </p>
            </div>
            <div class="col-md-4 text-md-end">
                <button onclick="window.print()" class="btn btn-light">
                    <i class="bi bi-printer"></i> Stampa
                </button>
                <button onclick="clearCache()" class="btn btn-light">
                    <i class="bi bi-arrow-clockwise"></i> Aggiorna
                </button>
            </div>
        </div>
    </div>

    <!-- Filter Panel -->
    <div class="filter-panel no-print">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="analytics">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">

            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-calendar"></i> Data Inizio</label>
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-calendar"></i> Data Fine</label>
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>

            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-funnel"></i> Applica Filtri
                </button>
            </div>

            <div class="col-md-4 text-end">
                <div class="btn-group">
                    <a href="?page=analytics&export=members" class="btn btn-success btn-sm">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Export Soci
                    </a>
                    <a href="?page=analytics&export=cards" class="btn btn-success btn-sm">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Export Tessere
                    </a>
                    <a href="?page=analytics&export=payments" class="btn btn-success btn-sm">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Export Pagamenti
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- View Tabs -->
    <div class="view-tabs no-print">
        <a href="?page=analytics&view=overview&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
           class="view-tab <?php echo $view === 'overview' ? 'active' : ''; ?>">
            <i class="bi bi-speedometer2"></i> Panoramica
        </a>
        <a href="?page=analytics&view=members&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
           class="view-tab <?php echo $view === 'members' ? 'active' : ''; ?>">
            <i class="bi bi-people"></i> Soci
        </a>
        <a href="?page=analytics&view=cards&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
           class="view-tab <?php echo $view === 'cards' ? 'active' : ''; ?>">
            <i class="bi bi-credit-card"></i> Tessere
        </a>
        <a href="?page=analytics&view=financial&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
           class="view-tab <?php echo $view === 'financial' ? 'active' : ''; ?>">
            <i class="bi bi-cash-coin"></i> Finanziario
        </a>
        <a href="?page=analytics&view=geographic&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
           class="view-tab <?php echo $view === 'geographic' ? 'active' : ''; ?>">
            <i class="bi bi-geo-alt"></i> Geografico
        </a>
        <a href="?page=analytics&view=events&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
           class="view-tab <?php echo $view === 'events' ? 'active' : ''; ?>">
            <i class="bi bi-calendar-event"></i> Eventi
        </a>
    </div>

    <!-- KPI Cards -->
    <div class="row g-4 mb-4">
        <!-- Total Members -->
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">Soci Totali</div>
                        <div class="kpi-value text-primary"><?php echo number_format($kpis['total_members']); ?></div>
                        <div class="kpi-trend <?php echo $kpis['new_members_trend'] >= 0 ? 'positive' : 'negative'; ?>">
                            <i class="bi bi-<?php echo $kpis['new_members_trend'] >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            <?php echo abs($kpis['new_members_trend']); ?>% vs periodo precedente
                        </div>
                    </div>
                    <div class="text-primary">
                        <i class="bi bi-people fs-1"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Cards -->
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">Tessere Attive</div>
                        <div class="kpi-value text-success"><?php echo number_format($kpis['active_cards']); ?></div>
                        <div class="kpi-trend <?php echo $kpis['cards_trend'] >= 0 ? 'positive' : 'negative'; ?>">
                            <i class="bi bi-<?php echo $kpis['cards_trend'] >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            <?php echo abs($kpis['cards_trend']); ?>% vs periodo precedente
                        </div>
                    </div>
                    <div class="text-success">
                        <i class="bi bi-credit-card fs-1"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue -->
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">Incassi Periodo</div>
                        <div class="kpi-value text-warning">€<?php echo number_format($kpis['period_revenue'], 2); ?></div>
                        <div class="kpi-trend <?php echo $kpis['revenue_trend'] >= 0 ? 'positive' : 'negative'; ?>">
                            <i class="bi bi-<?php echo $kpis['revenue_trend'] >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            <?php echo abs($kpis['revenue_trend']); ?>% vs periodo precedente
                        </div>
                    </div>
                    <div class="text-warning">
                        <i class="bi bi-cash-coin fs-1"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Retention Rate -->
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">Tasso Retention</div>
                        <div class="kpi-value text-info"><?php echo $kpis['retention_rate']; ?>%</div>
                        <div class="text-muted small">
                            Soci attivi: <?php echo number_format($kpis['active_members']); ?>
                        </div>
                    </div>
                    <div class="text-info">
                        <i class="bi bi-graph-up-arrow fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($view === 'overview'): ?>
        <!-- Overview Charts -->
        <div class="row">
            <!-- Members Growth -->
            <div class="col-md-6">
                <div class="chart-container">
                    <h3 class="chart-title">Crescita Soci (Ultimi 12 Mesi)</h3>
                    <canvas id="membersGrowthChart"></canvas>
                </div>
            </div>

            <!-- Revenue Chart -->
            <div class="col-md-6">
                <div class="chart-container">
                    <h3 class="chart-title">Incassi Mensili (€)</h3>
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <!-- Members by Category -->
            <div class="col-md-6">
                <div class="chart-container">
                    <h3 class="chart-title">Soci per Categoria</h3>
                    <canvas id="membersByCategoryChart"></canvas>
                </div>
            </div>

            <!-- Members by Status -->
            <div class="col-md-6">
                <div class="chart-container">
                    <h3 class="chart-title">Stato Soci</h3>
                    <canvas id="membersByStatusChart"></canvas>
                </div>
            </div>
        </div>

    <?php elseif ($view === 'members'): ?>
        <!-- Members Detailed Analytics -->
        <div class="row">
            <div class="col-md-4">
                <div class="chart-container">
                    <h3 class="chart-title">Distribuzione per Età</h3>
                    <canvas id="ageDistributionChart"></canvas>
                </div>
            </div>

            <div class="col-md-4">
                <div class="chart-container">
                    <h3 class="chart-title">Livello Engagement</h3>
                    <canvas id="engagementChart"></canvas>
                </div>
            </div>

            <div class="col-md-4">
                <div class="chart-container">
                    <h3 class="chart-title">Soci per Categoria</h3>
                    <canvas id="membersCategoryChart"></canvas>
                </div>
            </div>

            <div class="col-12">
                <div class="chart-container">
                    <h3 class="chart-title">Trend Crescita (12 Mesi)</h3>
                    <canvas id="membersGrowthDetailChart"></canvas>
                </div>
            </div>
        </div>

    <?php elseif ($view === 'cards'): ?>
        <!-- Cards Analytics -->
        <div class="row">
            <div class="col-md-12">
                <div class="chart-container">
                    <h3 class="chart-title">Emissioni Tessere nel Tempo</h3>
                    <canvas id="cardsEmissionsChart"></canvas>
                </div>
            </div>

            <div class="col-12">
                <div class="table-analytics">
                    <table class="table">
                        <thead class="table-light">
                            <tr>
                                <th colspan="6">
                                    <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                                    Tessere in Scadenza (Prossimi 30 Giorni)
                                </th>
                            </tr>
                            <tr>
                                <th>Tessera</th>
                                <th>Socio</th>
                                <th>Email</th>
                                <th>Telefono</th>
                                <th>Scadenza</th>
                                <th>Giorni Rimanenti</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($expiring_cards)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Nessuna tessera in scadenza nei prossimi 30 giorni</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expiring_cards as $card): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($card['numero_tessera']); ?></code></td>
                                        <td><?php echo htmlspecialchars($card['socio_nome']); ?></td>
                                        <td><small><?php echo htmlspecialchars($card['email']); ?></small></td>
                                        <td><small><?php echo htmlspecialchars($card['telefono'] ?: '-'); ?></small></td>
                                        <td><?php echo date('d/m/Y', strtotime($card['data_scadenza'])); ?></td>
                                        <td>
                                            <span class="stat-badge bg-<?php echo $card['days_to_expire'] <= 7 ? 'danger' : 'warning'; ?>">
                                                <?php echo $card['days_to_expire']; ?> giorni
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php elseif ($view === 'financial'): ?>
        <!-- Financial Analytics -->
        <div class="row">
            <div class="col-md-8">
                <div class="chart-container">
                    <h3 class="chart-title">Andamento Incassi (12 Mesi)</h3>
                    <canvas id="revenueDetailChart"></canvas>
                </div>
            </div>

            <div class="col-md-4">
                <div class="kpi-card mb-4">
                    <div class="kpi-label">Incasso Totale</div>
                    <div class="kpi-value text-success">€<?php echo number_format($kpis['total_revenue'], 2); ?></div>
                </div>

                <div class="kpi-card mb-4">
                    <div class="kpi-label">In Attesa</div>
                    <div class="kpi-value text-warning">€<?php echo number_format($kpis['pending_revenue'], 2); ?></div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Media per Socio</div>
                    <div class="kpi-value text-info">€<?php echo number_format($kpis['avg_revenue_per_member'], 2); ?></div>
                </div>
            </div>
        </div>

    <?php elseif ($view === 'geographic'): ?>
        <!-- Geographic Analytics -->
        <div class="row">
            <div class="col-12">
                <h3 class="mb-4"><i class="bi bi-geo-alt-fill"></i> Distribuzione Geografica per Provincia</h3>
                <div class="geographic-grid">
                    <?php foreach ($geographic_data as $geo): ?>
                        <div class="geo-card">
                            <div class="geo-card-title"><?php echo htmlspecialchars($geo['location']); ?></div>
                            <div class="geo-card-value"><?php echo number_format($geo['count']); ?></div>
                            <div class="geo-card-label">soci (<?php echo $geo['active_count']; ?> attivi)</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    <?php elseif ($view === 'events'): ?>
        <!-- Events Analytics -->
        <?php $events_data = $analytics->getEventsAnalytics(); ?>
        <div class="row">
            <div class="col-md-4">
                <div class="kpi-card">
                    <div class="kpi-label">Eventi Totali</div>
                    <div class="kpi-value text-primary"><?php echo $events_data['total_events']; ?></div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="kpi-card">
                    <div class="kpi-label">Prossimi Eventi</div>
                    <div class="kpi-value text-success"><?php echo $events_data['upcoming_events']; ?></div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="kpi-card">
                    <div class="kpi-label">Eventi Passati</div>
                    <div class="kpi-value text-secondary"><?php echo $events_data['past_events']; ?></div>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- Chart.js -->
<script src="../assets/js/chart.min.js"></script>
<script>
// Chart.js configuration
Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
Chart.defaults.color = '#666';

// Members Growth Chart
<?php if ($view === 'overview' || $view === 'members'): ?>
    const membersGrowthData = <?php echo json_encode($members_growth); ?>;
    new Chart(document.getElementById('<?php echo $view === 'members' ? 'membersGrowthDetailChart' : 'membersGrowthChart'; ?>'), {
        type: 'line',
        data: {
            labels: membersGrowthData.labels,
            datasets: [
                {
                    label: membersGrowthData.datasets[0].label,
                    data: membersGrowthData.datasets[0].data,
                    backgroundColor: 'rgba(102, 126, 234, 0.2)',
                    borderColor: 'rgba(102, 126, 234, 1)',
                    borderWidth: 2,
                    fill: true
                },
                {
                    label: membersGrowthData.datasets[1].label,
                    data: membersGrowthData.datasets[1].data,
                    backgroundColor: 'rgba(118, 75, 162, 0.2)',
                    borderColor: 'rgba(118, 75, 162, 1)',
                    borderWidth: 2,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
<?php endif; ?>

// Revenue Chart
<?php if ($view === 'overview' || $view === 'financial'): ?>
    const revenueData = <?php echo json_encode($revenue_chart); ?>;
    new Chart(document.getElementById('<?php echo $view === 'financial' ? 'revenueDetailChart' : 'revenueChart'; ?>'), {
        type: 'bar',
        data: {
            labels: revenueData.labels,
            datasets: [
                {
                    label: revenueData.datasets[0].label,
                    data: revenueData.datasets[0].data,
                    backgroundColor: 'rgba(40, 167, 69, 0.8)',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 1
                },
                {
                    label: revenueData.datasets[1].label,
                    data: revenueData.datasets[1].data,
                    type: 'line',
                    borderColor: 'rgba(255, 193, 7, 1)',
                    borderWidth: 2,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': €' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '€' + value;
                        }
                    }
                }
            }
        }
    });
<?php endif; ?>

// Members by Category
<?php if ($view === 'overview' || $view === 'members'): ?>
    const categoryData = <?php echo json_encode($members_by_category); ?>;
    new Chart(document.getElementById('<?php echo $view === 'members' ? 'membersCategoryChart' : 'membersByCategoryChart'; ?>'), {
        type: 'bar',
        data: {
            labels: categoryData.labels,
            datasets: [
                {
                    label: categoryData.datasets[0].label,
                    data: categoryData.datasets[0].data,
                    backgroundColor: 'rgba(102, 126, 234, 0.8)'
                },
                {
                    label: categoryData.datasets[1].label,
                    data: categoryData.datasets[1].data,
                    backgroundColor: 'rgba(40, 167, 69, 0.8)'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
<?php endif; ?>

// Members by Status
<?php if ($view === 'overview'): ?>
    const statusData = <?php echo json_encode($members_by_status); ?>;
    new Chart(document.getElementById('membersByStatusChart'), {
        type: 'doughnut',
        data: {
            labels: statusData.labels,
            datasets: [{
                data: statusData.datasets[0].data,
                backgroundColor: statusData.datasets[0].backgroundColor
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
<?php endif; ?>

// Age Distribution
<?php if ($view === 'members'): ?>
    const ageData = <?php echo json_encode($age_distribution); ?>;
    new Chart(document.getElementById('ageDistributionChart'), {
        type: 'bar',
        data: {
            labels: ageData.labels,
            datasets: [{
                label: ageData.datasets[0].label,
                data: ageData.datasets[0].data,
                backgroundColor: 'rgba(23, 162, 184, 0.8)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
<?php endif; ?>

// Engagement Distribution
<?php if ($view === 'members'): ?>
    const engagementData = <?php echo json_encode($engagement_distribution); ?>;
    new Chart(document.getElementById('engagementChart'), {
        type: 'pie',
        data: {
            labels: engagementData.labels,
            datasets: [{
                data: engagementData.datasets[0].data,
                backgroundColor: [
                    'rgba(220, 53, 69, 0.8)',
                    'rgba(255, 193, 7, 0.8)',
                    'rgba(23, 162, 184, 0.8)',
                    'rgba(40, 167, 69, 0.8)',
                    'rgba(102, 126, 234, 0.8)'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
<?php endif; ?>

// Cards Emissions
<?php if ($view === 'cards'): ?>
    const cardsData = <?php echo json_encode($cards_emissions); ?>;
    new Chart(document.getElementById('cardsEmissionsChart'), {
        type: 'line',
        data: {
            labels: cardsData.labels,
            datasets: [
                {
                    label: cardsData.datasets[0].label,
                    data: cardsData.datasets[0].data,
                    backgroundColor: 'rgba(102, 126, 234, 0.2)',
                    borderColor: 'rgba(102, 126, 234, 1)',
                    borderWidth: 2,
                    fill: true,
                    yAxisID: 'y'
                },
                {
                    label: cardsData.datasets[1].label,
                    data: cardsData.datasets[1].data,
                    backgroundColor: 'rgba(40, 167, 69, 0.2)',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 2,
                    fill: true,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Emissioni'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: {
                        drawOnChartArea: false
                    },
                    title: {
                        display: true,
                        text: 'Importo Medio (€)'
                    }
                }
            }
        }
    });
<?php endif; ?>

// Clear cache function
function clearCache() {
    if (confirm('Vuoi aggiornare tutti i dati analytics? Questo potrebbe richiedere qualche secondo.')) {
        fetch('?page=analytics&clear_cache=1')
            .then(() => location.reload());
    }
}
</script>

</body>
</html>
