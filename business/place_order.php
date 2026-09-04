<?php
/**
 * AgriSync — Place Commercial Order (TASK-044 / Issue #4)
 * Submits procurement demand and instantly launches the autonomous AI Broker matchmaking agent.
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Business Access Control
requireRole('business');

$page_title = 'Place Commercial Order';
$user_id = (int) ($_SESSION['user_id'] ?? 0);

$prefill_crop = sanitize($_GET['crop_type'] ?? ($_GET['crop'] ?? ''));
$prefill_qty = isset($_GET['quantity_preset']) ? (float)$_GET['quantity_preset'] : (isset($_GET['quantity_kg']) ? (float)$_GET['quantity_kg'] : '');
$prefill_max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : '';
$prefill_farmer_id = isset($_GET['farmer_id']) ? (int)$_GET['farmer_id'] : 0;
$prefill_listing_id = isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : 0;

$crops = defined('AGRISYNC_CROPS') && is_array(AGRISYNC_CROPS) ? AGRISYNC_CROPS : ['Tomato', 'Carrot', 'Big Onion', 'Bell Pepper', 'Potato', 'Cabbage', 'Leeks', 'Green Beans', 'Green Chili', 'Banana', 'Papaya', 'Pumpkin', 'Brinjal'];

// Validate crop_type against AGRISYNC_CROPS constants before pre-filling to prevent XSS or invalid selections
if (!empty($prefill_crop)) {
    if (!in_array($prefill_crop, $crops, true)) {
        $prefill_crop = '';
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex" style="min-height: 100vh;">
    <!-- Role-based Sidebar Navigation -->
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="flex-grow-1 bg-light p-4 overflow-auto">
        <div class="container-fluid max-w-4xl">
            
            <!-- Breadcrumbs -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb small">
                    <li class="breadcrumb-item"><a href="dashboard.php" class="text-success text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="orders.php" class="text-success text-decoration-none">Orders</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Place Commercial Order</li>
                </ol>
            </nav>

            <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 bg-white position-relative">
                <div class="d-flex align-items-center mb-4 pb-2 border-bottom">
                    <div class="p-3 rounded-3 bg-primary-subtle text-primary me-3">
                        <i class="bi bi-cart-plus-fill fs-3"></i>
                    </div>
                    <div>
                        <h3 class="fw-bold text-dark mb-1">Place Commercial Wholesale Order</h3>
                        <p class="text-muted small mb-0">Our AI Broker Agent will match your request with the closest verified farmers in real-time.</p>
                    </div>
                </div>

                <!-- Alert Box -->
                <div id="alertBox" class="alert d-none rounded-3 py-2 px-3 small mb-4"></div>

                <!-- Order Form -->
                <form id="orderForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

                    <div class="row g-4 mb-4">
                        <div class="col-12 col-md-6">
                            <label for="cropSelect" class="form-label small fw-semibold text-muted">Required Crop / Produce</label>
                            <select name="crop_type" id="cropSelect" class="form-select rounded-3" required>
                                <option value="" disabled <?= empty($prefill_crop) ? 'selected' : '' ?>>Select crop...</option>
                                <?php foreach ($crops as $c): ?>
                                    <option value="<?= $c ?>" <?= $prefill_crop === $c ? 'selected' : '' ?>><?= $c ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="qtyInput" class="form-label small fw-semibold text-muted">Required Volume (kg)</label>
                            <div class="input-group">
                                <input type="number" step="1" min="1" name="quantity_kg" id="qtyInput" class="form-control rounded-start-3" placeholder="e.g. 500" value="<?= $prefill_qty !== '' ? (float)$prefill_qty : '' ?>" required>
                                <span class="input-group-text bg-light rounded-end-3">kg</span>
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="priceInput" class="form-label small fw-semibold text-muted">Maximum Price Cap per kg (LKR)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light rounded-start-3">Rs.</span>
                                <input type="number" step="5" min="10" name="max_price" id="priceInput" class="form-control rounded-end-3" placeholder="e.g. 220" value="<?= $prefill_max_price ?>" required>
                            </div>
                            <small class="text-muted d-block mt-1">Our AI broker ensures you never pay above this ceiling.</small>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="deliveryDateInput" class="form-label small fw-semibold text-muted">Target Delivery Date</label>
                            <input type="date" name="delivery_date" id="deliveryDateInput" class="form-control rounded-3" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" min="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="urgencySelect" class="form-label small fw-semibold text-muted">Procurement Urgency</label>
                            <select name="urgency" id="urgencySelect" class="form-select rounded-3">
                                <option value="low">Standard (5-7 days window)</option>
                                <option value="medium" selected>Normal (2-4 days window)</option>
                                <option value="high">Urgent / Express Delivery</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="notesInput" class="form-label small fw-semibold text-muted">Grade / Quality Notes (Optional)</label>
                            <input type="text" name="notes" id="notesInput" class="form-control rounded-3" placeholder="e.g. Grade A ripe, crate packaged">
                        </div>
                    </div>

                    <!-- AI Broker Guarantee Callout -->
                    <div class="p-3 rounded-3 bg-light border mb-4">
                        <div class="d-flex align-items-center">
                            <div class="p-2 rounded-2 bg-success-subtle text-success me-3">
                                <i class="bi bi-robot fs-4"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Autonomous Gemini AI Broker Engine</h6>
                                <p class="small text-muted mb-0">
                                    Upon placing this order, our AI Broker evaluates all active farmer supplies, optimizes transportation food-miles, and negotiates fair wholesale pricing in seconds.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <a href="dashboard.php" class="btn btn-light rounded-3 px-4">Cancel</a>
                        <button type="submit" id="submitBtn" class="btn btn-primary rounded-3 px-4 py-2 fw-semibold shadow-sm" style="min-height: 46px;">
                            <i class="bi bi-stars me-1"></i> Place Order & Run AI Broker
                        </button>
                    </div>
                </form>

                <!-- Processing Overlay Modal -->
                <div id="aiProcessingOverlay" class="position-absolute top-0 start-0 w-100 h-100 bg-white bg-opacity-95 rounded-4 d-none flex-column align-items-center justify-content-center p-4 text-center" style="z-index: 10;">
                    <div class="spinner-border text-success mb-3" style="width: 3.5rem; height: 3.5rem;" role="status"></div>
                    <h4 class="fw-bold text-dark mb-1">AI Broker Matchmaking in Progress...</h4>
                    <p class="text-muted small mb-3" id="aiStepText">Ingesting commercial demand specifications...</p>
                    <div class="progress w-50 mb-2" style="height: 6px;">
                        <div id="aiProgressBar" class="progress-bar bg-success progress-bar-striped progress-bar-animated" style="width: 30%;"></div>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>

<script>
document.getElementById('orderForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const form = e.target;
    const submitBtn = document.getElementById('submitBtn');
    const alertBox = document.getElementById('alertBox');
    const overlay = document.getElementById('aiProcessingOverlay');
    const stepText = document.getElementById('aiStepText');
    const progressBar = document.getElementById('aiProgressBar');

    alertBox.classList.add('d-none');

    const formData = {
        crop_type: form.crop_type.value,
        quantity_kg: parseFloat(form.quantity_kg.value),
        max_price: parseFloat(form.max_price.value),
        delivery_date: form.delivery_date.value,
        urgency: form.urgency.value,
        notes: form.notes.value,
        csrf_token: form.csrf_token.value
    };

    if (!formData.crop_type || !formData.quantity_kg || !formData.max_price || !formData.delivery_date) {
        alertBox.className = 'alert alert-danger rounded-3 py-2 px-3 small mb-4';
        alertBox.textContent = 'Please fill in all required order fields.';
        alertBox.classList.remove('d-none');
        return;
    }

    // Show Submitting Indicator
    overlay.classList.remove('d-none');
    overlay.classList.add('d-flex');
    stepText.textContent = "Submitting commercial order request...";
    progressBar.style.width = "100%";
    submitBtn.disabled = true;

    try {
        const response = await fetch('../api/place_order.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(formData)
        });

        const res = await response.json();

        if (res.success) {
            window.location.href = `orders.php?status=pending`;
        } else {
            overlay.classList.add('d-none');
            overlay.classList.remove('d-flex');
            submitBtn.disabled = false;
            alertBox.className = 'alert alert-danger rounded-3 py-2 px-3 small mb-4';
            alertBox.textContent = res.error || 'Failed to place commercial order.';
            alertBox.classList.remove('d-none');
        }
    } catch (err) {
        overlay.classList.add('d-none');
        overlay.classList.remove('d-flex');
        submitBtn.disabled = false;
        alertBox.className = 'alert alert-danger rounded-3 py-2 px-3 small mb-4';
        alertBox.textContent = 'Network error: ' + err.message;
        alertBox.classList.remove('d-none');
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
