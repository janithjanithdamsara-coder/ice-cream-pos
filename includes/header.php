<?php
// includes/header.php - Pure Inventory & Distribution Navigation
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$user = currentUser();
$flash = getFlash();

// Fetch branches for branch switcher (if super_admin)
$allBranches = [];
if ($user['role'] === 'super_admin') {
    global $pdo;
    $allBranches = $pdo->query("SELECT id, name, code FROM branches ORDER BY id ASC")->fetchAll();
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' | ' : '' ?>FrostyFlow Ice Cream Distribution & Inventory</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Font (Inter) -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; -webkit-tap-highlight-color: transparent; }
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            .print-full-width { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
        }
        .print-only { display: none; }
        .sidebar-scroll::-webkit-scrollbar { width: 4px; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        @media (max-width: 768px) {
            body { padding-bottom: 65px; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen flex flex-col antialiased">

    <!-- Mobile Sidebar Backdrop -->
    <div id="sidebarBackdrop" onclick="toggleSidebar()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-40 hidden md:hidden transition-opacity duration-300"></div>

    <!-- ==================== LEFT SIDEBAR NAVIGATION ==================== -->
    <aside id="mainSidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 text-slate-200 flex flex-col justify-between transition-transform duration-300 transform -translate-x-full md:translate-x-0 shadow-2xl border-r border-slate-800 no-print">
        
        <!-- Top Section of Sidebar -->
        <div class="flex flex-col flex-1 overflow-hidden">
            
            <!-- Brand Logo & Header -->
            <div class="h-16 px-5 flex items-center justify-between border-b border-slate-800/80 bg-slate-950/40">
                <a href="dashboard.php" class="flex items-center space-x-3 group">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white shadow-lg shadow-cyan-950/50 group-hover:scale-105 transition-transform">
                        <i class="fa-solid fa-boxes-packing text-lg"></i>
                    </div>
                    <div>
                        <span class="text-lg font-black tracking-tight text-white flex items-center">
                            FrostyFlow
                            <span class="w-2 h-2 rounded-full bg-cyan-400 ml-1.5 animate-pulse"></span>
                        </span>
                        <div class="text-[10px] text-slate-400 font-medium -mt-1 tracking-wider uppercase">Stock & Distribution</div>
                    </div>
                </a>

                <!-- Mobile Close Button -->
                <button type="button" onclick="toggleSidebar()" class="md:hidden p-1.5 text-slate-400 hover:text-white rounded-lg focus:outline-none">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <!-- Active Branch Indicator -->
            <div class="p-3.5 border-b border-slate-800/60 bg-slate-950/20">
                <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5 flex items-center justify-between">
                    <span class="flex items-center">
                        <i class="fa-solid fa-warehouse text-cyan-400 mr-1.5"></i> Active Warehouse
                    </span>
                </div>

                <?php if ($user['role'] === 'super_admin' && count($allBranches) > 1): ?>
                    <select onchange="window.location.href='?switch_branch=' + this.value" 
                            class="w-full bg-slate-800 hover:bg-slate-750 text-slate-200 text-xs font-bold rounded-xl px-3 py-2 border border-slate-700 focus:outline-none focus:ring-1 focus:ring-cyan-500 cursor-pointer">
                        <?php foreach ($allBranches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= $b['id'] == $user['branch_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <div class="text-xs font-bold text-slate-200 bg-slate-800/80 px-3 py-2 rounded-xl border border-slate-700/60 truncate">
                        <?= htmlspecialchars($user['branch_name']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Navigation Links Scroll Area -->
            <nav class="flex-1 px-3 py-4 space-y-5 overflow-y-auto sidebar-scroll">
                
                <!-- Group 1: Overview -->
                <div>
                    <div class="px-3 mb-1.5 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Inventory Operations</div>
                    <div class="space-y-1">
                        <a href="dashboard.php" class="flex items-center px-3 py-2.5 rounded-xl text-xs font-semibold transition-all <?= $currentPage === 'dashboard.php' ? 'bg-gradient-to-r from-cyan-600 to-blue-600 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/70' ?>">
                            <i class="fa-solid fa-gauge-high w-5 text-sm"></i>
                            <span class="ml-2.5">Dashboard Overview</span>
                        </a>

                        <a href="stock.php" class="flex items-center px-3 py-2.5 rounded-xl text-xs font-semibold transition-all <?= $currentPage === 'stock.php' ? 'bg-gradient-to-r from-cyan-600 to-blue-600 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/70' ?>">
                            <i class="fa-solid fa-boxes-stacked w-5 text-sm"></i>
                            <span class="ml-2.5">Stock & Warehouse</span>
                        </a>
                    </div>
                </div>

                <!-- Group 2: Distribution & Outflow -->
                <div>
                    <div class="px-3 mb-1.5 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Dispatches & Returns</div>
                        <a href="pos.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-semibold transition-all <?= $currentPage === 'pos.php' ? 'bg-gradient-to-r from-cyan-600 to-blue-600 text-white font-bold shadow-md' : 'text-slate-300 hover:text-white hover:bg-slate-800/70' ?>">
                            <div class="flex items-center">
                                <i class="fa-solid fa-cash-register w-5 text-sm text-cyan-400"></i>
                                <span class="ml-2.5">Counter POS (Issue Slip)</span>
                            </div>
                            <span class="px-1.5 py-0.5 text-[9px] font-black rounded uppercase tracking-wider bg-cyan-500/20 text-cyan-300">Fast</span>
                        </a>

                        <a href="direct_issue.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-semibold transition-all <?= $currentPage === 'direct_issue.php' ? 'bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/70' ?>">
                            <div class="flex items-center">
                                <i class="fa-solid fa-arrow-up-from-bracket w-5 text-sm"></i>
                                <span class="ml-2.5">Formal Issue Note (GDN)</span>
                            </div>
                            <span class="px-1.5 py-0.5 text-[9px] font-black rounded uppercase tracking-wider bg-emerald-500/20 text-emerald-300">Out</span>
                        </a>

                        <a href="lorry.php" class="flex items-center px-3 py-2.5 rounded-xl text-xs font-semibold transition-all <?= $currentPage === 'lorry.php' ? 'bg-gradient-to-r from-cyan-600 to-blue-600 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/70' ?>">
                            <i class="fa-solid fa-truck-moving w-5 text-sm"></i>
                            <span class="ml-2.5">Lorry Dispatch & 3PM Returns</span>
                        </a>
                    </div>
                </div>

                <!-- Group 3: Reports -->
                <div>
                    <div class="px-3 mb-1.5 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Audit & Stock Movement</div>
                    <div class="space-y-1">
                        <a href="reports.php" class="flex items-center px-3 py-2.5 rounded-xl text-xs font-semibold transition-all <?= $currentPage === 'reports.php' ? 'bg-gradient-to-r from-cyan-600 to-blue-600 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/70' ?>">
                            <i class="fa-solid fa-clipboard-list w-5 text-sm"></i>
                            <span class="ml-2.5">Daily Stock Movement Sheet</span>
                        </a>
                    </div>
                </div>

                <!-- Group 4: Administration -->
                <?php if (hasRole(['super_admin', 'admin'])): ?>
                <div>
                    <div class="px-3 mb-1.5 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">System Admin</div>
                    <div class="space-y-1">
                        <a href="branches.php" class="flex items-center px-3 py-2.5 rounded-xl text-xs font-semibold transition-all <?= $currentPage === 'branches.php' ? 'bg-gradient-to-r from-cyan-600 to-blue-600 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/70' ?>">
                            <i class="fa-solid fa-building-flag w-5 text-sm"></i>
                            <span class="ml-2.5">Branches Management</span>
                        </a>

                        <a href="users.php" class="flex items-center px-3 py-2.5 rounded-xl text-xs font-semibold transition-all <?= $currentPage === 'users.php' ? 'bg-gradient-to-r from-cyan-600 to-blue-600 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/70' ?>">
                            <i class="fa-solid fa-users w-5 text-sm"></i>
                            <span class="ml-2.5">User Accounts</span>
                        </a>

                        <a href="settings.php" class="flex items-center px-3 py-2.5 rounded-xl text-xs font-semibold transition-all <?= $currentPage === 'settings.php' ? 'bg-gradient-to-r from-rose-600 to-rose-700 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/70' ?>">
                            <i class="fa-solid fa-gear w-5 text-sm"></i>
                            <span class="ml-2.5">Settings & Clear All</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

            </nav>
        </div>

        <!-- Sidebar User Footer -->
        <div class="p-3 border-t border-slate-800 bg-slate-950/60">
            <div class="flex items-center justify-between p-2 rounded-xl bg-slate-900/80 border border-slate-800">
                <div class="flex items-center space-x-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-cyan-500 to-blue-600 text-white font-extrabold flex items-center justify-center text-xs flex-shrink-0">
                        <?= strtoupper(substr($user['name'], 0, 1)) ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-bold text-white truncate"><?= htmlspecialchars($user['name']) ?></div>
                        <div class="text-[10px] text-slate-400 capitalize truncate"><?= str_replace('_', ' ', $user['role']) ?></div>
                    </div>
                </div>
                <a href="logout.php" title="Sign Out" class="p-1.5 text-slate-400 hover:text-rose-400 rounded-lg transition-colors flex-shrink-0">
                    <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>
                </a>
            </div>
        </div>

    </aside>

    <!-- ==================== RIGHT SIDE WRAPPER ==================== -->
    <div class="md:pl-64 flex flex-col flex-1 min-h-screen print-full-width">
        
        <!-- Top Horizontal Header -->
        <header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-xs no-print">
            <div class="px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                
                <!-- Left: Hamburger (Mobile) + Page Title -->
                <div class="flex items-center space-x-3">
                    <button type="button" onclick="toggleSidebar()" class="md:hidden p-2 rounded-xl text-slate-600 hover:bg-slate-100 focus:outline-none transition-colors">
                        <i class="fa-solid fa-bars text-lg"></i>
                    </button>

                    <div>
                        <h2 class="text-base sm:text-lg font-bold text-slate-800 leading-tight">
                            <?= isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Overview' ?>
                        </h2>
                        <div class="hidden sm:flex items-center space-x-1.5 text-[11px] text-slate-400">
                            <span>Warehouse Inventory</span>
                            <span>&rsaquo;</span>
                            <span class="text-slate-600 font-medium"><?= htmlspecialchars($user['branch_name']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Right: Quick Direct Issue Action & User -->
                <div class="flex items-center space-x-2 sm:space-x-3">
                    <!-- Quick Direct Store Issue Button -->
                    <a href="direct_issue.php" class="inline-flex items-center px-3.5 py-2 text-xs font-bold rounded-xl text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 shadow-sm transition-all transform hover:-translate-y-0.5">
                        <i class="fa-solid fa-arrow-up-from-bracket mr-1.5"></i>
                        <span>Direct Issue (Store Out)</span>
                    </a>

                    <!-- User Pill -->
                    <div class="hidden sm:flex items-center pl-3 border-l border-slate-200 space-x-2">
                        <span class="inline-block w-2 h-2 rounded-full bg-cyan-500 animate-pulse"></span>
                        <span class="text-xs text-slate-600 font-semibold"><?= htmlspecialchars($user['name']) ?></span>
                    </div>
                </div>

            </div>
        </header>

        <!-- Main Content Body -->
        <main class="flex-1 p-4 sm:p-6 lg:p-8 w-full">
            
            <!-- Flash Alert -->
            <?php if ($flash): ?>
                <div id="flashAlert" class="mb-5 flex items-center p-4 rounded-xl text-xs sm:text-sm font-medium shadow-sm transition-all duration-300 <?= $flash['type'] === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : ($flash['type'] === 'danger' ? 'bg-rose-50 text-rose-800 border border-rose-200' : 'bg-amber-50 text-amber-800 border border-amber-200') ?>">
                    <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check text-emerald-500' : 'fa-circle-exclamation text-rose-500' ?> text-base sm:text-lg mr-3 flex-shrink-0"></i>
                    <div class="flex-1"><?= htmlspecialchars($flash['message']) ?></div>
                    <button type="button" onclick="document.getElementById('flashAlert').remove()" class="text-slate-400 hover:text-slate-600 p-1">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            <?php endif; ?>
