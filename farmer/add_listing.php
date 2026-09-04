<?php
/**
 * AgriSync — Farmer Add Harvest Listing (TASK-033)
 * Form allowing farmers to list produce with quality grading, certification tracking, and secure photo upload.
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Farmer Access Control
requireRole('farmer');

$page_title = 'List New Harvest Produce';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$error = '';
$success = '';

$crops = AGRISYNC_CROPS;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $crop_type = trim($_POST['crop_type'] ?? '');
    $quantity_kg = (float) ($_POST['quantity_kg'] ?? 0);
    $price_per_kg = (float) ($_POST['price_per_kg'] ?? 0);
    $harvest_date = trim($_POST['harvest_date'] ?? '');
    $quality_grade = strtoupper(trim($_POST['quality_grade'] ?? 'B'));
    $certifications = trim($_POST['certifications'] ?? '');
    $csrf = $_POST['csrf_token'] ?? '';

    if (!in_array($quality_grade, ['A', 'B', 'C'], true)) {
        $quality_grade = 'B';
    }

    $image_path = null;

    if (!validateCSRFToken($csrf)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } elseif (empty($crop_type) || !in_array($crop_type, $crops, true)) {
        $error = 'Please select a valid crop from the catalog.';
    } elseif ($quantity_kg <= 0) {
        $error = 'Harvest quantity must be greater than 0 kg.';
    } elseif ($price_per_kg <= 0) {
        $error = 'Price per kg must be a positive value.';
    } elseif (empty($harvest_date)) {
        $error = 'Please select a projected harvest date.';
    } else {
        // Secure Image Upload Handling (MIME & extension validation + cryptographically secure renaming)
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $tmp_path = $_FILES['image']['tmp_name'];
            $file_size = (int) $_FILES['image']['size'];

            if ($file_size > 5 * 1024 * 1024) {
                $error = 'Harvest image size must be under 5MB.';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $tmp_path);
                finfo_close($finfo);

                $allowed_mimes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($allowed_mimes[$mime_type])) {
                    $error = 'Invalid image file format. Only JPG, PNG, and WebP images are allowed.';
                } else {
                    $ext = $allowed_mimes[$mime_type];
                    $new_filename = bin2hex(random_bytes(16)) . '.' . $ext;
                    $upload_dir = __DIR__ . '/../uploads/crops/';

                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $dest_path = $upload_dir . $new_filename;
                    if (move_uploaded_file($tmp_path, $dest_path)) {
                        $image_path = 'uploads/crops/' . $new_filename;
                    } else {
                        $error = 'Failed to save uploaded harvest image.';
                    }
                }
            }
        }

        if (empty($error)) {
            try {
                $db = getDbConnection();
                $stmt = $db->prepare("
                    INSERT INTO harvest_listings 
                        (farmer_id, crop_type, quantity_kg, price_per_kg, harvest_date, quality_grade, certifications, image_path, status, created_at, updated_at)
                    VALUES 
                        (:farmer_id, :crop_type, :quantity_kg, :price_per_kg, :harvest_date, :quality_grade, :certifications, :image_path, 'available', NOW(), NOW())
                ");
                $stmt->execute([
                    ':farmer_id'     => $user_id,
                    ':crop_type'     => $crop_type,
                    ':quantity_kg'   => $quantity_kg,
                    ':price_per_kg'  => $price_per_kg,
                    ':harvest_date'  => $harvest_date,
                    ':quality_grade' => $quality_grade,
                    ':certifications'=> !empty($certifications) ? $certifications : null,
                    ':image_path'    => $image_path
                ]);

                $app_url = defined('APP_URL') ? APP_URL : '';
                redirect($app_url . '/farmer/listings.php?added=1');
            } catch (Throwable $e) {
                error_log("Add Harvest Error: " . $e->getMessage());
                $error = 'Unable to save harvest listing. Please try again.';
            }
        }
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
                    <li class="breadcrumb-item"><a href="listings.php" class="text-success text-decoration-none">My Harvests</a></li>
                    <li class="breadcrumb-item active" aria-current="page">List Produce</li>
                </ol>
            </nav>

            <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 bg-white">
                <div class="d-flex align-items-center mb-4 pb-2 border-bottom">
                    <div class="p-3 rounded-3 bg-success-subtle text-success me-3">
                        <i class="bi bi-plus-circle-fill fs-3"></i>
                    </div>
                    <div>
                        <h3 class="fw-bold text-dark mb-1">List New Harvest Produce</h3>
                        <p class="text-muted small mb-0">Publish your upcoming or ready harvest with quality grading & certifications to connect with commercial buyers.</p>
                    </div>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger rounded-3 d-flex align-items-center py-2 px-3 small mb-4">
                        <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
                        <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="add_listing.php" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

                    <div class="row g-4 mb-4">
                        <div class="col-12 col-md-6">
                            <label for="cropSelect" class="form-label small fw-semibold text-muted">Crop / Produce Type</label>
                            <select name="crop_type" id="cropSelect" class="form-select rounded-3" required onchange="checkPriceGuide(this.value)">
                                <option value="" disabled selected>Select crop...</option>
                                <?php foreach ($crops as $c): ?>
                                    <option value="<?= $c ?>"><?= $c ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="harvestDateInput" class="form-label small fw-semibold text-muted">Harvest / Ready Date</label>
                            <input type="date" name="harvest_date" id="harvestDateInput" class="form-control rounded-3" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="qtyInput" class="form-label small fw-semibold text-muted">Quantity Available (kg)</label>
                            <div class="input-group">
                                <input type="number" step="10" min="10" name="quantity_kg" id="qtyInput" class="form-control rounded-start-3" placeholder="e.g. 500" required>
                                <span class="input-group-text bg-light rounded-end-3">kg</span>
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="priceInput" class="form-label small fw-semibold text-muted">Asking Price per kg (LKR)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light rounded-start-3">Rs.</span>
                                <input type="number" step="5" min="10" name="price_per_kg" id="priceInput" class="form-control rounded-end-3" placeholder="e.g. 180" required>
                            </div>
                            <small class="text-muted d-block mt-1" id="priceGuideText">
                                <i class="bi bi-info-circle me-1"></i> Suggested wholesale range: Rs. 150 - 220/kg
                            </small>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="gradeSelect" class="form-label small fw-semibold text-muted">Quality Grade</label>
                            <select name="quality_grade" id="gradeSelect" class="form-select rounded-3">
                                <option value="A">Grade A (Premium / Export Quality)</option>
                                <option value="B" selected>Grade B (Standard Retail)</option>
                                <option value="C">Grade C (Industrial / Processing)</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="certInput" class="form-label small fw-semibold text-muted">Certifications (Optional)</label>
                            <input type="text" name="certifications" id="certInput" class="form-control rounded-3" placeholder="e.g. GAP Certified, Organic">
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="imageInput" class="form-label small fw-semibold text-muted">Harvest Photo (Optional)</label>
                            <input type="file" name="image" id="imageInput" class="form-control rounded-3" accept="image/jpeg,image/png,image/webp">
                        </div>
                    </div>

                    <!-- AI Broker Fair Price Protection Callout -->
                    <div class="p-3 rounded-3 bg-light border mb-4">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-shield-check text-success fs-3 me-3"></i>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">AI Broker Fair-Trade Matching</h6>
                                <p class="small text-muted mb-0">
                                    Once published, our AI Broker matches your harvest directly against incoming commercial supermarket and exporter orders, protecting your price margins with zero middleman deductions.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <a href="listings.php" class="btn btn-light rounded-3 px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary rounded-3 px-4 py-2 fw-semibold shadow-sm">
                            <i class="bi bi-check2-circle me-1"></i> Publish Harvest Listing
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<script>
function checkPriceGuide(crop) {
    const guides = {
        'Tomato': 'Suggested wholesale range: Rs. 160 - 240/kg (High demand in Dambulla/Colombo)',
        'Carrot': 'Suggested wholesale range: Rs. 220 - 300/kg (Strong supermarket demand)',
        'Big Onion': 'Suggested wholesale range: Rs. 190 - 250/kg (Stable national demand)',
        'Bell Pepper': 'Suggested wholesale range: Rs. 350 - 480/kg (Premium retail grade)',
        'Potato': 'Suggested wholesale range: Rs. 200 - 280/kg (Consistent commercial order volume)'
    };
    const guideText = document.getElementById('priceGuideText');
    if (guides[crop]) {
        guideText.innerHTML = `<i class="bi bi-stars text-success me-1"></i> <strong>AI Guide:</strong> ${guides[crop]}`;
    } else {
        guideText.innerHTML = `<i class="bi bi-info-circle me-1"></i> Price according to quality grade and regional wholesale spot rates.`;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
