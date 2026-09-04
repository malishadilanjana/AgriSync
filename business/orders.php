<?php
/**
 * AgriSync — Commercial Buyer Order Tracking (TASK-046 / Issue #33)
 * Full lifecycle tracking for wholesale orders, match statuses, scheduled deliveries, and reviews.
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Business Access Control
requireRole('business');

$page_title = 'Order Tracking & Reviews';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$status_filter = sanitize($_GET['status'] ?? 'all');

$orders = [];

try {
    $db = getDbConnection();

    $sql = "
        SELECT 
            o.id, o.crop_type, o.quantity_kg, o.max_price, o.delivery_date, o.urgency, o.status, o.notes, o.created_at,
            m.id as match_id, m.matched_price, m.confidence_score, m.status as match_status,
            u.id as farmer_id, u.name as farmer_name, u.district as farmer_district, u.average_rating as farmer_rating,
            r.id as review_id, r.rating as review_rating, r.comment as review_comment
        FROM order_requests o
        LEFT JOIN order_matches m ON o.id = m.order_id
        LEFT JOIN users u ON m.farmer_id = u.id
        LEFT JOIN user_reviews r ON m.id = r.order_match_id AND r.reviewer_id = :business_id
        WHERE o.business_id = :business_id
    ";
    $params = [':business_id' => $user_id];

    if ($status_filter !== 'all' && in_array($status_filter, ['pending', 'matching', 'matched', 'in_transit', 'fulfilled', 'cancelled'])) {
        $sql .= " AND o.status = :status";
        $params[':status'] = $status_filter;
    }

    $sql .= " ORDER BY o.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log("Orders Page Error: " . $e->getMessage());
    $error_message = "Unable to fetch orders.";
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
                        <i class="bi bi-card-checklist text-success me-2"></i> Order Tracking & Reviews
                    </h1>
                    <p class="text-muted small mb-0">
                        Monitor active procurement orders, AI match status, and submit smallholder producer reviews.
                    </p>
                </div>
                <div class="d-flex gap-2 mt-3 mt-md-0">
                    <a href="place_order.php" class="btn btn-primary rounded-3 d-flex align-items-center shadow-sm">
                        <i class="bi bi-cart-plus-fill me-1"></i> Place New Order
                    </a>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="card border-0 shadow-sm rounded-4 p-2 mb-4 bg-white">
                <ul class="nav nav-pills nav-fill small fw-semibold">
                    <li class="nav-item">
                        <a class="nav-link <?= $status_filter === 'all' ? 'active bg-primary' : 'text-dark' ?>" href="orders.php?status=all">All Orders</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $status_filter === 'pending' ? 'active bg-primary' : 'text-dark' ?>" href="orders.php?status=pending">Pending</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $status_filter === 'matched' ? 'active bg-primary' : 'text-dark' ?>" href="orders.php?status=matched">AI Matched</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $status_filter === 'in_transit' ? 'active bg-primary' : 'text-dark' ?>" href="orders.php?status=in_transit">In Transit</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $status_filter === 'fulfilled' ? 'active bg-primary' : 'text-dark' ?>" href="orders.php?status=fulfilled">Fulfilled</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $status_filter === 'cancelled' ? 'active bg-primary' : 'text-dark' ?>" href="orders.php?status=cancelled">Cancelled</a>
                    </li>
                </ul>
            </div>

            <!-- Orders Table -->
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th>Order ID</th>
                                <th>Produce</th>
                                <th>Volume (kg)</th>
                                <th>Price Cap / Matched</th>
                                <th>Delivery Target</th>
                                <th>Status</th>
                                <th>AI Matched Producer</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orders)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="bi bi-box2 fs-1 text-muted d-block mb-2"></i>
                                        No commercial orders found for the selected filter.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($orders as $o): ?>
                                    <?php
                                        $st = $o['status'];
                                        $badge = 'bg-secondary-subtle text-secondary';
                                        if ($st === 'matching') $badge = 'bg-warning-subtle text-warning';
                                        if ($st === 'matched') $badge = 'bg-success-subtle text-success';
                                        if ($st === 'in_transit') $badge = 'bg-info-subtle text-info fw-bold';
                                        if ($st === 'fulfilled') $badge = 'bg-success-subtle text-success';
                                        if ($st === 'cancelled') $badge = 'bg-danger-subtle text-danger';
                                    ?>
                                    <tr>
                                        <td class="fw-semibold text-muted">#ORD-<?= (int)$o['id'] ?></td>
                                        <td>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($o['crop_type'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if (!empty($o['notes'])): ?>
                                                <small class="text-muted d-block" style="font-size: 0.75rem;"><?= htmlspecialchars($o['notes'], ENT_QUOTES, 'UTF-8') ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?= number_format($o['quantity_kg'], 1) ?></strong> kg</td>
                                        <td>
                                            <?php if (!empty($o['matched_price'])): ?>
                                                <span class="text-success fw-bold">Rs. <?= number_format($o['matched_price'], 2) ?></span>
                                                <small class="text-muted d-block">(Cap: <?= number_format($o['max_price'], 2) ?>)</small>
                                            <?php else: ?>
                                                <span>Rs. <?= number_format($o['max_price'], 2) ?> cap</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted">
                                            <i class="bi bi-calendar3 me-1"></i> <?= htmlspecialchars($o['delivery_date'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td>
                                            <span class="badge rounded-pill <?= $badge ?> px-2 py-1 text-capitalize">
                                                <?= htmlspecialchars(str_replace('_', ' ', $st), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($o['farmer_name'])): ?>
                                                <span class="fw-semibold text-dark small d-block"><?= htmlspecialchars($o['farmer_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="text-muted small">
                                                    <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($o['farmer_district'], ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if (isset($o['farmer_rating']) && (float)$o['farmer_rating'] > 0): ?>
                                                        <span class="badge bg-warning-subtle text-dark ms-1">★ <?= number_format((float)$o['farmer_rating'], 1) ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small fst-italic">Awaiting match</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-1">
                                                <?php if (!empty($o['match_id'])): ?>
                                                    <a href="matches.php?order_id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-success rounded-3 px-2 py-1">
                                                        <i class="bi bi-robot"></i> Review
                                                    </a>
                                                    <?php if ($o['match_status'] === 'in_transit' || $o['status'] === 'in_transit'): ?>
                                                        <button type="button" class="btn btn-sm btn-success rounded-3 px-2 py-1 fw-semibold btn-confirm-pod" data-match-id="<?= (int)$o['match_id'] ?>">
                                                            <i class="bi bi-box-seam-fill me-1"></i> Confirm Receipt (POD)
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (!empty($o['review_id'])): ?>
                                                        <span class="btn btn-sm btn-light border text-success rounded-3 px-2 py-1 disabled">
                                                            <i class="bi bi-star-fill text-warning me-1"></i> Reviewed (<?= (int)$o['review_rating'] ?>★)
                                                        </span>
                                                    <?php elseif ($o['status'] === 'fulfilled' || $o['match_status'] === 'delivered'): ?>
                                                        <button type="button" class="btn btn-sm btn-warning text-dark rounded-3 px-2 py-1 fw-semibold" onclick="openReviewModal(<?= (int)$o['match_id'] ?>, '<?= htmlspecialchars($o['farmer_name'] ?? 'Producer', ENT_QUOTES, 'UTF-8') ?>')">
                                                            <i class="bi bi-star-fill me-1"></i> Leave Review
                                                        </button>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted border">In Queue</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Leave a Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark" id="reviewModalLabel">
                    <i class="bi bi-star-fill text-warning me-2"></i> Leave a Producer Review
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-3">
                    Rate your trade experience with <strong id="producerName" class="text-dark">Producer</strong> to help build platform trust and power our AI Broker.
                </p>

                <div id="modalAlert" class="alert d-none rounded-3 py-2 px-3 small mb-3"></div>

                <form id="reviewForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="order_match_id" id="modalMatchId" value="">
                    <input type="hidden" name="rating" id="modalRating" value="5">

                    <div class="mb-4 text-center">
                        <label class="form-label small fw-semibold text-muted d-block mb-2">Overall Rating (1 to 5 Stars)</label>
                        <div class="d-inline-flex gap-2" id="starContainer" style="cursor: pointer;">
                            <i class="bi bi-star-fill fs-2 text-warning star-btn" data-val="1"></i>
                            <i class="bi bi-star-fill fs-2 text-warning star-btn" data-val="2"></i>
                            <i class="bi bi-star-fill fs-2 text-warning star-btn" data-val="3"></i>
                            <i class="bi bi-star-fill fs-2 text-warning star-btn" data-val="4"></i>
                            <i class="bi bi-star-fill fs-2 text-warning star-btn" data-val="5"></i>
                        </div>
                        <div class="mt-1"><span id="starRatingLabel" class="badge bg-warning-subtle text-dark fw-bold px-3 py-1">5 Stars - Excellent</span></div>
                    </div>

                    <div class="mb-4">
                        <label for="modalComment" class="form-label small fw-semibold text-muted">Feedback / Comments (Optional)</label>
                        <textarea name="comment" id="modalComment" class="form-control rounded-3" rows="3" placeholder="Share details about produce quality, timely delivery, and communication..."></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light rounded-3 px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="submitReviewBtn" class="btn btn-primary rounded-3 px-4 py-2 fw-semibold" style="min-height: 44px; border-radius: 8px;">
                            <i class="bi bi-send me-1"></i> Submit Review
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let reviewModalObj = null;

function openReviewModal(matchId, producerName) {
    document.getElementById('modalMatchId').value = matchId;
    document.getElementById('producerName').textContent = producerName;
    document.getElementById('modalAlert').classList.add('d-none');
    document.getElementById('modalComment').value = '';
    setRating(5);

    if (!reviewModalObj) {
        reviewModalObj = new bootstrap.Modal(document.getElementById('reviewModal'));
    }
    reviewModalObj.show();
}

function setRating(val) {
    document.getElementById('modalRating').value = val;
    const stars = document.querySelectorAll('#starContainer .star-btn');
    const labels = {
        1: '1 Star - Poor',
        2: '2 Stars - Fair',
        3: '3 Stars - Good',
        4: '4 Stars - Very Good',
        5: '5 Stars - Excellent'
    };

    stars.forEach((s, idx) => {
        if (idx < val) {
            s.classList.remove('bi-star', 'text-muted');
            s.classList.add('bi-star-fill', 'text-warning');
        } else {
            s.classList.remove('bi-star-fill', 'text-warning');
            s.classList.add('bi-star', 'text-muted');
        }
    });

    document.getElementById('starRatingLabel').textContent = labels[val] || `${val} Stars`;
}

document.querySelectorAll('#starContainer .star-btn').forEach(star => {
    star.addEventListener('click', function() {
        const val = parseInt(this.getAttribute('data-val'));
        setRating(val);
    });
});

document.querySelectorAll('.btn-confirm-pod').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Are you sure you want to confirm Proof of Delivery (POD)? Escrow funds will be released to the producer.')) {
            return;
        }

        const matchId = parseInt(this.getAttribute('data-match-id'));
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Confirming...';

        try {
            const res = await fetch('../api/update_delivery_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= generateCSRFToken() ?>'
                },
                body: JSON.stringify({
                    match_id: matchId,
                    status: 'delivered',
                    csrf_token: '<?= generateCSRFToken() ?>'
                })
            });

            const data = await res.json();
            if (data.success) {
                alert(data.data.message || 'Delivery confirmed successfully!');
                window.location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to confirm delivery.'));
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-box-seam-fill me-1"></i> Confirm Receipt (POD)';
            }
        } catch (err) {
            alert('Network error: ' + err.message);
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-box-seam-fill me-1"></i> Confirm Receipt (POD)';
        }
    });
});

document.getElementById('reviewForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const form = e.target;
    const alertBox = document.getElementById('modalAlert');
    const submitBtn = document.getElementById('submitReviewBtn');

    alertBox.classList.add('d-none');
    submitBtn.disabled = true;

    const payload = {
        order_match_id: parseInt(form.order_match_id.value),
        rating: parseInt(form.rating.value),
        comment: form.comment.value,
        csrf_token: form.csrf_token.value
    };

    try {
        const res = await fetch('../api/submit_rating.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        submitBtn.disabled = false;

        if (data.success) {
            alertBox.className = 'alert alert-success rounded-3 py-2 px-3 small mb-3';
            alertBox.textContent = data.data.message || 'Review submitted successfully!';
            alertBox.classList.remove('d-none');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            alertBox.className = 'alert alert-danger rounded-3 py-2 px-3 small mb-3';
            alertBox.textContent = data.error || 'Failed to submit review.';
            alertBox.classList.remove('d-none');
        }
    } catch (err) {
        submitBtn.disabled = false;
        alertBox.className = 'alert alert-danger rounded-3 py-2 px-3 small mb-3';
        alertBox.textContent = 'Network error: ' + err.message;
        alertBox.classList.remove('d-none');
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

