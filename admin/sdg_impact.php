<?php
/**
 * AgriSync — United Nations SDG Impact Dashboard (TASK-089)
 * Visualizes ESG metrics, food loss reduction, fair-trade earnings, and food miles averted.
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../auth/auth_check.php';
checkRole(['admin']);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$page_title = 'UN SDG Impact Analytics';

// Initial server-side load of summary metrics
$food_waste_saved_kg = 0.0;
$farmer_revenue_lkr = 0.0;
$food_miles_saved_km = 0.0;
$farmer_income_boost_pct = 0.0;

try {
    $db = getDbConnection();
    $stmt_summary = $db->prepare("
        SELECT 
            COALESCE(SUM(o.quantity_kg), 0) AS food_waste_saved_kg,
            COALESCE(SUM(m.matched_price * o.quantity_kg), 0) AS farmer_revenue_lkr,
            COUNT(m.id) AS completed_orders_count
        FROM order_matches m
        JOIN order_requests o ON m.order_id = o.id
        WHERE m.status IN ('completed', 'accepted')
    ");
    $stmt_summary->execute();
    $summary = $stmt_summary->fetch(PDO::FETCH_ASSOC);

    $food_waste_saved_kg = (float)($summary['food_waste_saved_kg'] ?? 0);
    $farmer_revenue_lkr = (float)($summary['farmer_revenue_lkr'] ?? 0);
    $completed_orders_count = (int)($summary['completed_orders_count'] ?? 0);
    $food_miles_saved_km = round($food_waste_saved_kg * 0.45, 1);
    $farmer_income_boost_pct = $completed_orders_count > 0 ? 24.5 : 0.0;
} catch (Throwable $e) {
    error_log("SDG Dashboard Load Error: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid dashboard-wrapper">
    <div class="row">
        <!-- Sidebar Navigation -->
        <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

        <!-- Main Content Area -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
            <!-- Header Banner -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-2 border-bottom">
                <div>
                    <h2 class="fw-bold text-dark mb-1">UN Sustainable Development Goals (SDG) Impact</h2>
                    <p class="text-muted small mb-0">Quantitative ESG metrics tracking sustainability, food security, and rural empowerment in Sri Lanka.</p>
                </div>
                <div class="mt-3 mt-md-0 d-flex gap-2">
                    <button class="btn btn-outline-primary rounded-3 px-3 shadow-sm" onclick="fetchSdgMetrics(true)">
                        <i class="bi bi-arrow-clockwise me-1"></i> Refresh Data
                    </button>
                    <button class="btn btn-outline-success rounded-3 px-3 shadow-sm" onclick="exportSdgCSV()">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export ESG Report (CSV)
                    </button>
                </div>
            </div>

            <!-- UN SDG Highlight Cards -->
            <div class="row g-4 mb-4">
                <!-- SDG 2: Zero Hunger -->
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-4 p-4 h-100 border-start border-4 border-danger bg-white">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="badge bg-danger-subtle text-danger px-3 py-2 rounded-pill fw-bold">UN SDG 2</span>
                            <i class="bi bi-shield-fill-check text-danger fs-3"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-1">Zero Hunger</h4>
                        <p class="text-muted small mb-3">Target 2.3 & 2.4: Doubling smallholder productivity and halving post-harvest losses.</p>
                        <div class="bg-light rounded-3 p-3 mt-auto">
                            <span class="text-muted extra-small text-uppercase fw-bold">Food Waste Saved</span>
                            <h3 class="fw-bold text-danger mb-0 mt-1" id="foodWasteVal"><?= number_format($food_waste_saved_kg, 1) ?> <span class="fs-6 fw-normal text-muted">kg</span></h3>
                        </div>
                    </div>
                </div>

                <!-- SDG 8: Decent Work & Economic Growth -->
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-4 p-4 h-100 border-start border-4 border-primary bg-white">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="badge bg-primary-subtle text-primary px-3 py-2 rounded-pill fw-bold">UN SDG 8</span>
                            <i class="bi bi-graph-up-arrow text-primary fs-3"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-1">Decent Work & Growth</h4>
                        <p class="text-muted small mb-3">Target 8.2: Guaranteed fair-trade floor pricing & direct bank settlement inclusion.</p>
                        <div class="bg-light rounded-3 p-3 mt-auto">
                            <span class="text-muted extra-small text-uppercase fw-bold">Farmer Revenue Earned</span>
                            <h3 class="fw-bold text-primary mb-0 mt-1" id="farmerRevenueVal">Rs. <?= number_format($farmer_revenue_lkr, 2) ?></h3>
                        </div>
                    </div>
                </div>

                <!-- SDG 12: Responsible Consumption -->
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-4 p-4 h-100 border-start border-4 border-warning bg-white">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="badge bg-warning-subtle text-warning px-3 py-2 rounded-pill fw-bold">UN SDG 12</span>
                            <i class="bi bi-truck text-warning fs-3"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-1">Responsible Trade</h4>
                        <p class="text-muted small mb-3">Target 12.3: Slashing food transport transit miles via localized algorithmic matching.</p>
                        <div class="bg-light rounded-3 p-3 mt-auto">
                            <span class="text-muted extra-small text-uppercase fw-bold">Food Miles Averted</span>
                            <h3 class="fw-bold text-warning mb-0 mt-1" id="foodMilesVal"><?= number_format($food_miles_saved_km, 1) ?> <span class="fs-6 fw-normal text-muted">km</span></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row g-4 mb-4">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-white">
                        <h5 class="fw-bold mb-3"><i class="bi bi-graph-up text-primary me-2"></i>Fair Trade Price Trajectory (LKR / kg)</h5>
                        <div style="height: 280px;">
                            <canvas id="priceTrendChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-white">
                        <h5 class="fw-bold mb-3"><i class="bi bi-pie-chart text-primary me-2"></i>Sustainable Crop Yield Mix</h5>
                        <div style="height: 280px;">
                            <canvas id="cropDistChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
let priceChartObj = null;
let cropChartObj = null;

document.addEventListener('DOMContentLoaded', function() {
    fetchSdgMetrics(false);
});

function fetchSdgMetrics(forceRefresh) {
    const url = '../api/get_sdg_metrics.php' + (forceRefresh ? '?nocache=1' : '');
    fetch(url)
        .then(res => res.json())
        .then(res => {
            if (!res.success || !res.data) return;
            const d = res.data;

            // Update DOM Cards
            const wasteElem = document.getElementById('foodWasteVal');
            if (wasteElem) wasteElem.innerHTML = `${Number(d.food_waste_saved_kg).toLocaleString('en-US', {minimumFractionDigits: 1, maximumFractionDigits: 1})} <span class="fs-6 fw-normal text-muted">kg</span>`;

            const revElem = document.getElementById('farmerRevenueVal');
            if (revElem) revElem.innerHTML = `Rs. ${Number(d.farmer_revenue_lkr).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

            const milesElem = document.getElementById('foodMilesVal');
            if (milesElem) milesElem.innerHTML = `${Number(d.food_miles_saved_km).toLocaleString('en-US', {minimumFractionDigits: 1, maximumFractionDigits: 1})} <span class="fs-6 fw-normal text-muted">km</span>`;

            // Update Charts
            updateCharts(d);
        })
        .catch(err => console.error("Error fetching SDG metrics:", err));
}

function updateCharts(data) {
    // 1. Price Trajectory Line Chart
    const ctxPrice = document.getElementById('priceTrendChart').getContext('2d');
    const priceLabels = data.price_trajectory ? data.price_trajectory.labels : ['May', 'Jun', 'Jul', 'Aug', 'Sep'];
    const priceValues = data.price_trajectory ? data.price_trajectory.values : [140, 155, 160, 175, 180];

    if (priceChartObj) priceChartObj.destroy();
    priceChartObj = new Chart(ctxPrice, {
        type: 'line',
        data: {
            labels: priceLabels,
            datasets: [{
                label: 'Avg Matched Price (LKR/kg)',
                data: priceValues,
                borderColor: '#2D6A4F',
                backgroundColor: 'rgba(45, 106, 79, 0.1)',
                fill: true,
                tension: 0.3,
                borderWidth: 2,
                pointRadius: 4,
                pointBackgroundColor: '#2D6A4F'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false } }
            }
        }
    });

    // 2. Sustainable Crop Yield Mix Doughnut Chart
    const ctxCrop = document.getElementById('cropDistChart').getContext('2d');
    const cropLabels = data.crop_distribution ? data.crop_distribution.labels : ['Tomato', 'Carrot', 'Big Onion'];
    const cropValues = data.crop_distribution ? data.crop_distribution.values : [40, 35, 25];

    if (cropChartObj) cropChartObj.destroy();
    cropChartObj = new Chart(ctxCrop, {
        type: 'doughnut',
        data: {
            labels: cropLabels,
            datasets: [{
                data: cropValues,
                backgroundColor: ['#2D6A4F', '#40916C', '#52B788', '#74C69D', '#95D5B2', '#B7E4C7', '#D8F3DC']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}

function exportSdgCSV() {
    fetch('../api/get_sdg_metrics.php')
        .then(res => res.json())
        .then(json => {
            if (!json.success || !json.data) return alert('Failed to generate export');
            const d = json.data;
            let csv = "Metric,Value,Unit,SDG Alignment\n";
            csv += `Food Waste Saved,${d.food_waste_saved_kg},kg,SDG 2\n`;
            csv += `Farmer Revenue Earned,${d.farmer_revenue_lkr},LKR,SDG 8\n`;
            csv += `Food Miles Saved,${d.food_miles_saved_km},km,SDG 12\n`;
            csv += `Completed Matches,${d.completed_orders_count},orders,SDG 8\n`;

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.setAttribute("href", url);
            link.setAttribute("download", `AgriSync_SDG_Impact_Report_${new Date().toISOString().slice(0,10)}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
