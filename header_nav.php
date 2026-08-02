<?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
<div class="header-menu">
    <div class="header-actions">
        <div class="notification-menu">
            <button type="button" class="header-menu-toggle notification-menu-toggle" aria-label="Open notifications" aria-expanded="false" data-notification-toggle>
                <i class="bi bi-bell"></i>
                <?php
                $notificationStmt = $conn->prepare("SELECT title, message, type, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                $notificationStmt->bind_param("i", $_SESSION['user_id']);
                $notificationStmt->execute();
                $notificationResult = $notificationStmt->get_result();
                $unreadCountStmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0");
                $unreadCountStmt->bind_param("i", $_SESSION['user_id']);
                $unreadCountStmt->execute();
                $notificationCount = (int)$unreadCountStmt->get_result()->fetch_assoc()['total'];
                $unreadCountStmt->close();
                ?>
                <?php if ($notificationCount > 0): ?><span class="notification-badge"><?php echo $notificationCount; ?></span><?php endif; ?>
            </button>
            <div class="header-menu-dropdown notification-dropdown" data-notification-dropdown>
                <div class="notification-heading"><strong>Notifications</strong><a href="set_budget.php">Update Budgets</a></div>
                <?php if ($notificationCount > 0): ?>
                    <?php while ($notification = $notificationResult->fetch_assoc()): ?>
                        <div class="notification-item">
                            <strong><?php echo htmlspecialchars($notification['title']); ?></strong>
                            <small><?php echo htmlspecialchars($notification['message']); ?></small>
                            <time><?php echo date('d M Y H:i', strtotime($notification['created_at'])); ?></time>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="notification-empty"><i class="bi bi-check-circle"></i> No alerts right now.</div>
                <?php endif; ?>
                <?php $notificationStmt->close(); ?>
            </div>
        </div>
        <div class="profile-menu">
            <button type="button" class="header-menu-toggle profile-menu-toggle" aria-label="Open profile menu" aria-expanded="false" data-menu-toggle>
                <?php if (!empty($_SESSION['profile_picture'])): ?><img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile picture"><?php else: ?><i class="bi bi-person-circle"></i><?php endif; ?>
            </button>
            <div class="header-menu-dropdown" data-menu-dropdown>
        <div class="profile-menu-summary">
            <?php if (!empty($_SESSION['profile_picture'])): ?><img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" class="profile-menu-avatar" alt="Profile picture"><?php else: ?><i class="bi bi-person-circle profile-menu-avatar-placeholder"></i><?php endif; ?>
            <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></strong>
            <small><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></small>
        </div>
        <a href="profile.php" class="profile-menu-edit <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>"><i class="bi bi-person-gear"></i> Profile & Edit</a>
        <div class="profile-menu-divider"></div>
        <a href="index.php" class="<?php echo $currentPage === 'index.php' ? 'active' : ''; ?>"><i class="bi bi-house-fill"></i> Home</a>
        <a href="add_transaction.php" class="<?php echo in_array($currentPage, ['add_transaction.php', 'edit_transaction.php'], true) ? 'active' : ''; ?>"><i class="bi bi-plus-circle-fill"></i> Add Transaction</a>
        <a href="transfer.php" class="<?php echo $currentPage === 'transfer.php' ? 'active' : ''; ?>"><i class="bi bi-arrow-left-right"></i> Transfer</a>
        <a href="manage_accounts.php" class="<?php echo $currentPage === 'manage_accounts.php' ? 'active' : ''; ?>"><i class="bi bi-wallet-fill"></i> Accounts</a>
        <a href="manage_categories.php" class="<?php echo $currentPage === 'manage_categories.php' ? 'active' : ''; ?>"><i class="bi bi-tags-fill"></i> Categories</a>
        <a href="set_budget.php" class="<?php echo $currentPage === 'set_budget.php' ? 'active' : ''; ?>"><i class="bi bi-wallet2"></i> Update Budgets</a>
        <a href="savings_goals.php" class="<?php echo $currentPage === 'savings_goals.php' ? 'active' : ''; ?>"><i class="bi bi-graph-up-arrow"></i> Goals</a>
        <button type="button" class="theme-menu-item" data-theme-toggle>
            <i class="bi bi-moon-stars"></i><span data-theme-label>Dark mode</span><span class="theme-switch" aria-hidden="true"><span></span></span>
        </button>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Log out</a>
            </div>
        </div>
    </div>
</div>
