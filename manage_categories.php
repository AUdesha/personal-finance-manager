<?php
include 'config.php';
$user_id = require_login();

$message = '';
$messageType = '';

// ==========================================
// LIST OF BOOTSTRAP ICONS FOR DROPDOWN
// ==========================================
$iconList = [
    'bi-wallet2' => '💳 Wallet',
    'bi-egg-fried' => '🍳 Food',
    'bi-bus-front' => '🚌 Transport',
    'bi-book' => '📚 Academics',
    'bi-film' => '🎬 Entertainment',
    'bi-phone' => '📱 Bills',
    'bi-briefcase' => '💼 Job',
    'bi-house' => '🏠 Housing',
    'bi-cart' => '🛒 Shopping',
    'bi-gift' => '🎁 Gift',
    'bi-heart' => '❤️ Health',
    'bi-cash' => '💰 Cash',
    'bi-credit-card' => '💳 Card',
    'bi-laptop' => '💻 Tech',
    'bi-tag' => '🏷️ Tag',
    'bi-stars' => '⭐ Other',
    'bi-coffee' => '☕ Coffee',
    'bi-bag' => '👜 Shopping',
    'bi-bicycle' => '🚲 Bike',
    'bi-trophy' => '🏆 Achievement'
];

// ==========================================
// HANDLE ADD CATEGORY
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_category'])) {
    $category_name = htmlspecialchars(trim($_POST['category_name']));
    $type = $_POST['type'];
    $icon = htmlspecialchars(trim($_POST['icon']));
    $color = htmlspecialchars(trim($_POST['color']));
    $description = htmlspecialchars(trim($_POST['description']));
    
    if (empty($category_name)) {
        $message = "⚠️ Category name is required.";
        $messageType = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO categories (user_id, category_name, type, icon, color, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssss", $user_id, $category_name, $type, $icon, $color, $description);
        
        if ($stmt->execute()) {
            $message = "✅ Category '{$category_name}' added successfully!";
            $messageType = "success";
        } else {
            $message = "❌ Error: " . $stmt->error;
            $messageType = "danger";
        }
        $stmt->close();
    }
}

// ==========================================
// HANDLE EDIT CATEGORY
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_category'])) {
    $category_id = (int)$_POST['category_id'];
    $category_name = htmlspecialchars(trim($_POST['category_name']));
    $type = $_POST['type'];
    $icon = htmlspecialchars(trim($_POST['icon']));
    $color = htmlspecialchars(trim($_POST['color']));
    $description = htmlspecialchars(trim($_POST['description']));
    
    if (empty($category_name)) {
        $message = "⚠️ Category name is required.";
        $messageType = "danger";
    } else {
        $stmt = $conn->prepare("UPDATE categories SET category_name = ?, type = ?, icon = ?, color = ?, description = ? WHERE category_id = ? AND user_id = ?");
        $stmt->bind_param("sssssii", $category_name, $type, $icon, $color, $description, $category_id, $user_id);
        
        if ($stmt->execute()) {
            $message = "✅ Category updated successfully!";
            $messageType = "success";
        } else {
            $message = "❌ Error: " . $stmt->error;
            $messageType = "danger";
        }
        $stmt->close();
    }
}

// ==========================================
// HANDLE DELETE CATEGORY
// ==========================================
if (isset($_GET['delete'])) {
    $category_id = (int)$_GET['delete'];
    
    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM transactions WHERE category_id = ?");
    $checkStmt->bind_param("i", $category_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $transCount = $checkResult->fetch_assoc()['count'];
    $checkStmt->close();
    
    $budgetStmt = $conn->prepare("SELECT COUNT(*) as count FROM budgets WHERE category_id = ?");
    $budgetStmt->bind_param("i", $category_id);
    $budgetStmt->execute();
    $budgetResult = $budgetStmt->get_result();
    $budgetCount = $budgetResult->fetch_assoc()['count'];
    $budgetStmt->close();
    
    $totalLinked = $transCount + $budgetCount;
    
    if ($totalLinked > 0) {
        $message = "❌ Cannot delete this category! It has {$transCount} transaction(s) and {$budgetCount} budget(s) linked to it.";
        $messageType = "danger";
    } else {
        $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $category_id, $user_id);
        
        if ($stmt->execute()) {
            $message = "✅ Category deleted successfully!";
            $messageType = "success";
        } else {
            $message = "❌ Error: " . $stmt->error;
            $messageType = "danger";
        }
        $stmt->close();
    }
}

// ==========================================
// FETCH EDIT DATA
// ==========================================
$editData = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM categories WHERE category_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $edit_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $editData = $result->fetch_assoc();
    }
    $stmt->close();
}

// ==========================================
// FETCH ALL CATEGORIES FOR DISPLAY
// ==========================================
$catStmt = $conn->prepare("SELECT c.*,
                           (SELECT COUNT(*) FROM transactions WHERE category_id = c.category_id) as transaction_count,
                           (SELECT COUNT(*) FROM budgets WHERE category_id = c.category_id) as budget_count
                           FROM categories c
                           WHERE c.user_id = ?
                           ORDER BY c.type, c.category_name");
$catStmt->bind_param("i", $user_id);
$catStmt->execute();
$catResult = $catStmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- EXTERNAL CSS (MUST HAVE for styling) -->
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        body { background: #f4f6f9; padding-bottom: 70px; }
        .form-label { font-weight: 600; }
        .card { border-radius: 15px; border: none; }
        .back-btn { border-radius: 50px; padding: 8px 20px; }
        .btn-success { border-radius: 50px; padding: 12px; font-weight: 700; }
        .btn-primary { border-radius: 50px; padding: 12px; font-weight: 700; }
        .btn-sm { border-radius: 30px; }
        .icon-preview { font-size: 24px; display: inline-block; width: 40px; text-align: center; }
        .color-preview { display: inline-block; width: 30px; height: 30px; border-radius: 50%; border: 2px solid #ddd; }
        .table th { font-weight: 600; color: #495057; }
        .category-row:hover { background-color: #f8f9fa; }
        .badge-type { padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .badge-income { background: #d4edda; color: #155724; }
        .badge-expense { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container mt-3">
    <!-- Header -->
    <div class="d-flex align-items-center mb-4">
        <a href="index.php" class="btn btn-outline-secondary back-btn me-3">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <h5 class="fw-bold mb-0"><i class="bi bi-tags"></i> Manage Categories</h5>
    </div>

    <!-- Alert Messages -->
    <?php if($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- ADD / EDIT CATEGORY FORM -->
    <!-- ========================================== -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-bold">
            <?php echo ($editData) ? '✏️ Edit Category' : '➕ Add New Category'; ?>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php if($editData): ?>
                    <input type="hidden" name="category_id" value="<?php echo $editData['category_id']; ?>">
                <?php endif; ?>
                
                <div class="row g-3">
                    <!-- Category Name -->
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Category Name</label>
                        <input type="text" name="category_name" class="form-control" 
                               placeholder="e.g., Food, Transport" 
                               value="<?php echo ($editData) ? htmlspecialchars($editData['category_name']) : ''; ?>" required>
                    </div>
                    
                    <!-- Type -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Type</label>
                        <select name="type" class="form-select" required>
                            <option value="INCOME" <?php echo ($editData && $editData['type'] == 'INCOME') ? 'selected' : ''; ?>>💰 INCOME</option>
                            <option value="EXPENSE" <?php echo ($editData && $editData['type'] == 'EXPENSE') ? 'selected' : ''; ?>>💸 EXPENSE</option>
                        </select>
                    </div>
                    
                    <!-- Icon -->
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Icon</label>
                        <div class="input-group">
                            <span class="input-group-text icon-preview" id="iconPreview">
                                <i class="bi <?php echo ($editData) ? htmlspecialchars($editData['icon']) : 'bi-tag'; ?>"></i>
                            </span>
                            <select name="icon" class="form-select" id="iconSelect" required>
                                <?php foreach($iconList as $iconClass => $iconLabel): ?>
                                    <option value="<?php echo $iconClass; ?>" <?php echo ($editData && $editData['icon'] == $iconClass) ? 'selected' : ''; ?>>
                                        <?php echo $iconLabel; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Color -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Color</label>
                        <div class="input-group">
                            <span class="input-group-text" id="colorPreview">
                                <span class="color-preview" style="background-color: <?php echo ($editData) ? htmlspecialchars($editData['color']) : '#6c757d'; ?>;"></span>
                            </span>
                            <input type="color" name="color" class="form-control form-control-color" 
                                   value="<?php echo ($editData) ? htmlspecialchars($editData['color']) : '#6c757d'; ?>" 
                                   style="padding: 2px; height: 38px; width: 60px;" id="colorPicker">
                        </div>
                    </div>
                    
                    <!-- Description -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Description</label>
                        <input type="text" name="description" class="form-control" 
                               placeholder="Optional note" 
                               value="<?php echo ($editData) ? htmlspecialchars($editData['description']) : ''; ?>">
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-12">
                        <?php if($editData): ?>
                            <button type="submit" name="edit_category" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Update Category
                            </button>
                            <a href="manage_categories.php" class="btn btn-outline-secondary ms-2">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        <?php else: ?>
                            <button type="submit" name="add_category" class="btn btn-success">
                                <i class="bi bi-plus-circle"></i> Add Category
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- CATEGORY LIST -->
    <!-- ========================================== -->
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul"></i> Your Categories</span>
            <span class="badge bg-secondary rounded-pill"><?php echo $catResult->num_rows; ?></span>
        </div>
        <div class="card-body p-0">
            <?php if ($catResult->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Icon</th>
                                <th>Category</th>
                                <th>Type</th>
                                <th>Color</th>
                                <th>Description</th>
                                <th>Linked</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $count = 1;
                            while($cat = $catResult->fetch_assoc()): 
                                $totalLinked = $cat['transaction_count'] + $cat['budget_count'];
                            ?>
                                <tr class="category-row">
                                    <td><?php echo $count++; ?></td>
                                    <td><i class="bi <?php echo htmlspecialchars($cat['icon']); ?> fs-4" style="color: <?php echo htmlspecialchars($cat['color']); ?>;"></i></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                    <td>
                                        <span class="badge-type <?php echo ($cat['type'] == 'INCOME') ? 'badge-income' : 'badge-expense'; ?>">
                                            <?php echo $cat['type']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="color-preview" style="background-color: <?php echo htmlspecialchars($cat['color']); ?>;"></span>
                                        <small class="text-muted"><?php echo htmlspecialchars($cat['color']); ?></small>
                                    </td>
                                    <td>
                                        <?php if($cat['description']): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($cat['description']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo ($totalLinked > 0) ? 'bg-primary' : 'bg-secondary'; ?>">
                                            <?php echo $totalLinked; ?>
                                        </span>
                                        <?php if($totalLinked > 0): ?>
                                            <small class="text-muted d-block">(T:<?php echo $cat['transaction_count']; ?> B:<?php echo $cat['budget_count']; ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <!-- Edit Button -->
                                        <a href="manage_categories.php?edit=<?php echo $cat['category_id']; ?>" class="btn btn-sm btn-outline-primary me-1" title="Edit Category">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <!-- Delete Button -->
                                        <?php if($totalLinked == 0): ?>
                                            <a href="manage_categories.php?delete=<?php echo $cat['category_id']; ?>" class="btn btn-sm btn-outline-danger" 
                                               data-confirm="Are you sure you want to delete this category? This action cannot be undone." title="Delete Category">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled title="Cannot delete - has <?php echo $totalLinked; ?> linked item(s)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-tags fs-1 d-block"></i>
                    <small>No categories yet. Add your first category above!</small>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Info -->
    <div class="alert alert-info mt-3" role="alert">
        <i class="bi bi-info-circle"></i> 
        <strong>Note:</strong> You cannot delete a category if it has any <strong>Transactions</strong> (T) or <strong>Budgets</strong> (B) linked to it. 
        The <strong>"Linked"</strong> column shows the total count.
    </div>
</div>

<!-- ========================================== -->
<!-- BOTTOM NAVIGATION (FIXED - MUST BE IN ALL PAGES) -->
<!-- ========================================== -->
<div class="bottom-nav">
    <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
    <div class="nav-container">
        <a href="index.php" class="nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>"><i class="bi bi-house-fill"></i><span>Home</span></a>
        <a href="add_transaction.php" class="nav-item <?php echo in_array($currentPage, ['add_transaction.php', 'edit_transaction.php'], true) ? 'active' : ''; ?>"><i class="bi bi-plus-circle-fill"></i><span>Add</span></a>
        <a href="transfer.php" class="nav-item <?php echo $currentPage === 'transfer.php' ? 'active' : ''; ?>"><i class="bi bi-arrow-left-right"></i><span>Transfer</span></a>
        <a href="manage_accounts.php" class="nav-item <?php echo $currentPage === 'manage_accounts.php' ? 'active' : ''; ?>"><i class="bi bi-wallet-fill"></i><span>Accounts</span></a>
        <a href="set_budget.php" class="nav-item <?php echo $currentPage === 'set_budget.php' ? 'active' : ''; ?>"><i class="bi bi-wallet2"></i><span>Budget</span></a>
        <a href="savings_goals.php" class="nav-item <?php echo $currentPage === 'savings_goals.php' ? 'active' : ''; ?>"><i class="bi bi-graph-up-arrow"></i><span>Goals</span></a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script src="app.js"></script>
</body>
</html>