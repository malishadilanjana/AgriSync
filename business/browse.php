<?php
/**
 * AgriSync — Browse Farm Produce Catalog (TASK-043)
 * Real-time agricultural produce marketplace connecting commercial buyers directly to producers.
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Business Access Control
requireRole('business');

$page_title = 'Browse Farm Produce';
$crop_filter = sanitize($_GET['crop'] ?? '');
$district_filter = sanitize($_GET['district'] ?? '');
$sort_by = sanitize($_GET['sort'] ?? 'newest');

$listings = [];
$districts = ['Dambulla', 'Nuwara Eliya', 'Matale', 'Kandy', 'Colombo', 'Jaffna', 'Anuradhapura', 'Badulla', 'Kurunegala', 'Hambantota', 'Ratnapura', 'Gampaha'];
$crops = ['Tomato', 'Carrot', 'Big Onion', 'Bell Pepper', 'Potato', 'Cabbage', 'Leeks', 'Green Beans', 'Green Chili', 'Banana', 'Papaya', 'Pumpkin', 'Brinjal'];

try {
    $db = getDbConnection();

    $sql = "
        SELECT 
            h.id, h.crop_type, h.quantity_kg, h.price_per_kg, h.harvest_date, h.status, h.created_at,
            u.id as farmer_id, u.name as farmer_name, u.district as farmer_district, u.phone as farmer_phone
        FROM harvest_listings h
        JOIN users u ON h.farmer_id = u.id
        WHERE h.status = 'available'
    ";
    $params = [];

    if (!empty($crop_filter)) {
        $sql .= " AND h.crop_type = :crop";
        $params[':crop'] = $crop_filter;
    }

    if (!empty($district_filter)) {
        $sql .= " AND u.district = :district";
        $params[':district'] = $district_filter;
    }

    if ($sort_by === 'price_asc') {
        $sql .= " ORDER BY h.price_per_kg ASC";
    } elseif ($sort_by === 'price_desc') {
        $sql .= " ORDER BY h.price_per_kg DESC";
    } elseif ($sort_by === 'quantity_desc') {
        $sql .= " ORDER BY h.quantity_kg DESC";
    } else {
        $sql .= " ORDER BY h.id DESC";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log("Browse Catalog Error: " . $e->getMessage());
    $error_message = "Unable to load produce listings.";
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex" style="min-height: 100vh;">
    <!-- Role-based Sidebar Navigation -->
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="flex-grow-1 bg-light p-4 overflow-auto">
        <div class="container-fluid max-w-7xl">
            
            <!-- Header -->
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 pb-2 border-bottom">
                <div>
                    <h1 class="h3 fw-bold text-dark mb-1">
                        <i class="bi bi-shop text-success me-2"></i> Produce Marketplace Catalog
                    </h1>
                    <p class="text-muted small mb-0">
                        Discover fresh harvests direct from verified Sri Lankan smallholder producers with zero intermediary markups.
                    </p>
                </div>
                <div class="d-flex gap-2 mt-3 mt-md-0">
                    <a href="place_order.php" class="btn btn-primary rounded-3 d-flex align-items-center shadow-sm">
                        <i class="bi bi-cart-plus-fill me-1"></i> Custom Order Request
                    </a>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
                <form method="GET" action="browse.php" class="row g-3 align-items-end">
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label small text-muted mb-1 fw-semibold">Filter by Crop</label>
                        <select name="crop" class="form-select rounded-3">
                            <option value="">All Crops</option>
                            <?php foreach ($crops as $c): ?>
                                <option value="<?= $c ?>" <?= $crop_filter === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label small text-muted mb-1 fw-semibold">District Hub</label>
                        <select name="district" class="form-select rounded-3">
                            <option value="">All Districts</option>
                            <?php foreach ($districts as $d): ?>
                                <option value="<?= $d ?>" <?= $district_filter === $d ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label small text-muted mb-1 fw-semibold">Sort By</label>
                        <select name="sort" class="form-select rounded-3">
                            <option value="newest" <?= $sort_by === 'newest' ? 'selected' : '' ?>>Newest First</option>
                            <option value="price_asc" <?= $sort_by === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                            <option value="price_desc" <?= $sort_by === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                            <option value="quantity_desc" <?= $sort_by === 'quantity_desc' ? 'selected' : '' ?>>Volume Available</option>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-md-1">
                        <button type="submit" class="btn btn-success w-100 rounded-3"><i class="bi bi-filter"></i></button>
                    </div>
                </form>
            </div>

            <!-- Produce Cards Grid -->
            <div class="row g-4">
                <?php if (empty($listings)): ?>
                    <div class="col-12 text-center py-5">
                        <div class="card border-0 shadow-sm rounded-4 p-5 bg-white">
                            <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                            <h5 class="fw-bold text-dark">No Available Produce Matches</h5>
                            <p class="text-muted small mb-3">There are no farm listings matching your selected crop or district filters right now.</p>
                            <div>
                                <a href="browse.php" class="btn btn-outline-success rounded-3 me-2">Clear Filters</a>
                                <a href="place_order.php" class="btn btn-primary rounded-3">Place Custom AI Order</a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($listings as $item): ?>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white hover-shadow transition">
                                <div class="card-body p-4 d-flex flex-column">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1 small fw-semibold">
                                                Direct Farm Produce
                                            </span>
                                            <h4 class="fw-bold text-dark mb-0 mt-1"><?= htmlspecialchars($item['crop_type'], ENT_QUOTES, 'UTF-8') ?></h4>
                                        </div>
                                        <div class="text-end">
                                            <div class="fs-4 fw-bold text-primary">Rs. <?= number_format($item['price_per_kg'], 2) ?></div>
                                            <small class="text-muted">per kg</small>
                                        </div>
                                    </div>

                                    <div class="bg-light p-3 rounded-3 mb-3 small">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-muted"><i class="bi bi-person-fill text-success me-1"></i> Producer:</span>
                                            <strong class="text-dark"><?= htmlspecialchars($item['farmer_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-muted"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Location:</span>
                                            <span class="text-dark fw-semibold"><?= htmlspecialchars($item['farmer_district'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-muted"><i class="bi bi-box-seam text-primary me-1"></i> Available Stock:</span>
                                            <span class="text-dark fw-bold"><?= number_format($item['quantity_kg'], 1) ?> kg</span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted"><i class="bi bi-calendar-event text-muted me-1"></i> Harvest Date:</span>
                                            <span class="text-dark"><?= htmlspecialchars($item['harvest_date'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>

                                    <div class="mt-auto pt-2">
                                        <a href="place_order.php?crop_type=<?= urlencode($item['crop_type']) ?>&quantity_preset=<?= (float)$item['quantity_kg'] ?>&farmer_id=<?= (int)$item['farmer_id'] ?>&max_price=<?= (float)$item['price_per_kg'] ?>&listing_id=<?= (int)$item['id'] ?>" class="btn btn-primary w-100 rounded-3 py-2 fw-semibold shadow-sm">
                                            <i class="bi bi-cart-plus-fill me-1"></i> Buy Now
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
