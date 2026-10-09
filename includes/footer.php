        </main>

        <!-- Footer -->
        <footer class="bg-white border-t border-slate-200 mt-auto py-3.5 text-xs text-slate-500 no-print">
            <div class="px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row justify-between items-center gap-3">
                <div class="text-center md:text-left">
                    <div>
                        <span class="font-black text-slate-800">Dhanesha Distributors</span> &copy; <?= date('Y') ?> &bull; Ice Cream Distribution & Cold Room Logistics
                    </div>
                    <div class="text-[11px] text-slate-400 mt-0.5">
                        Designed & Developed by <strong class="text-indigo-600 font-bold">Mr.Link Technology</strong>
                    </div>
                </div>

                <!-- Emergency Contact Quick Hotlines -->
                <div class="flex flex-wrap items-center justify-center gap-2 text-[11px]">
                    <button type="button" onclick="toggleSupportModal()" class="inline-flex items-center px-2.5 py-1 rounded-xl bg-gradient-to-r from-slate-900 to-indigo-950 text-white font-bold transition hover:scale-105 shadow-xs cursor-pointer">
                        <i class="fa-solid fa-headset mr-1.5 text-cyan-400"></i> Mr.Link Tech Support
                    </button>
                    <a href="tel:0773093941" class="inline-flex items-center px-2.5 py-1 rounded-xl bg-slate-100 hover:bg-cyan-50 hover:text-cyan-700 text-slate-700 font-bold transition border border-slate-200">
                        <i class="fa-solid fa-code mr-1.5 text-cyan-600"></i> Dev: 077-3093941
                    </a>
                    <a href="tel:0762529906" class="inline-flex items-center px-2.5 py-1 rounded-xl bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 text-slate-700 font-bold transition border border-slate-200">
                        <i class="fa-solid fa-user-gear mr-1.5 text-indigo-600"></i> Tech Lead: 076-2529906
                    </a>
                </div>
            </div>
        </footer>

    </div> <!-- End Right Side Wrapper (md:pl-64) -->

    <!-- ==================== MOBILE BOTTOM APP NAVIGATION BAR ==================== -->
    <nav class="md:hidden fixed bottom-0 inset-x-0 bg-white/95 backdrop-blur-md border-t border-slate-200/90 z-40 shadow-lg px-2 py-1.5 flex items-center justify-around no-print">
        <!-- Dashboard -->
        <a href="dashboard.php" class="flex flex-col items-center justify-center flex-1 py-1 px-1 rounded-xl transition <?= $currentPage === 'dashboard.php' ? 'text-cyan-600 font-extrabold' : 'text-slate-500 hover:text-slate-800 font-medium' ?>">
            <i class="fa-solid fa-gauge-high text-base mb-0.5 <?= $currentPage === 'dashboard.php' ? 'scale-110' : '' ?>"></i>
            <span class="text-[10px] tracking-tight">Overview</span>
        </a>

        <!-- Stock -->
        <a href="stock.php" class="flex flex-col items-center justify-center flex-1 py-1 px-1 rounded-xl transition <?= $currentPage === 'stock.php' ? 'text-cyan-600 font-extrabold' : 'text-slate-500 hover:text-slate-800 font-medium' ?>">
            <i class="fa-solid fa-boxes-stacked text-base mb-0.5 <?= $currentPage === 'stock.php' ? 'scale-110' : '' ?>"></i>
            <span class="text-[10px] tracking-tight">Stock</span>
        </a>

        <!-- POS Counter (Quick Issue) -->
        <a href="pos.php" class="flex flex-col items-center justify-center flex-1 py-1 px-1 rounded-xl transition <?= $currentPage === 'pos.php' ? 'text-cyan-600 font-extrabold' : 'text-slate-500 hover:text-slate-800 font-medium' ?>">
            <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 text-white flex items-center justify-center shadow-md shadow-cyan-600/30 -mt-3.5 border-2 border-white">
                <i class="fa-solid fa-cash-register text-xs"></i>
            </div>
            <span class="text-[10px] tracking-tight mt-0.5">Bill</span>
        </a>

        <!-- Lorries -->
        <a href="lorry.php" class="flex flex-col items-center justify-center flex-1 py-1 px-1 rounded-xl transition <?= $currentPage === 'lorry.php' ? 'text-cyan-600 font-extrabold' : 'text-slate-500 hover:text-slate-800 font-medium' ?>">
            <i class="fa-solid fa-truck-moving text-base mb-0.5 <?= $currentPage === 'lorry.php' ? 'scale-110' : '' ?>"></i>
            <span class="text-[10px] tracking-tight">Lorries</span>
        </a>

        <!-- Menu / More (Toggles Sidebar) -->
        <button type="button" onclick="toggleSidebar()" class="flex flex-col items-center justify-center flex-1 py-1 px-1 rounded-xl text-slate-500 hover:text-slate-800 font-medium transition focus:outline-none">
            <i class="fa-solid fa-bars text-base mb-0.5"></i>
            <span class="text-[10px] tracking-tight">Menu</span>
        </button>
    </nav>

    <!-- Global Scripts & Modern Toast System -->
    <script>
        // Toggle mobile slide-in sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('mainSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (sidebar && backdrop) {
                const isClosed = sidebar.classList.contains('-translate-x-full');
                if (isClosed) {
                    sidebar.classList.remove('-translate-x-full');
                    backdrop.classList.remove('hidden');
                } else {
                    sidebar.classList.add('-translate-x-full');
                    backdrop.classList.add('hidden');
                }
            }
        }

        // ================= MODERN TOAST NOTIFICATION SYSTEM =================
        function showToast(message, type = 'warning', title = '') {
            const container = document.getElementById('toastContainer');
            if (!container) return;

            const toastId = 'toast_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
            const toast = document.createElement('div');
            toast.id = toastId;
            toast.className = 'toast-animate-in pointer-events-auto flex items-start p-4 bg-slate-900/95 text-white backdrop-blur-md rounded-2xl shadow-2xl border border-slate-700/80 transition-all';

            let iconBg = 'bg-cyan-500/20 text-cyan-400';
            let iconClass = 'fa-solid fa-circle-info';
            let defaultTitle = 'Notice';

            if (type === 'warning') {
                iconBg = 'bg-amber-500/20 text-amber-400';
                iconClass = 'fa-solid fa-triangle-exclamation';
                defaultTitle = 'Stock Limit Warning';
            } else if (type === 'error' || type === 'danger') {
                iconBg = 'bg-rose-500/20 text-rose-400';
                iconClass = 'fa-solid fa-circle-xmark';
                defaultTitle = 'Action Failed';
            } else if (type === 'success') {
                iconBg = 'bg-emerald-500/20 text-emerald-400';
                iconClass = 'fa-solid fa-circle-check';
                defaultTitle = 'Success';
            }

            const heading = title || defaultTitle;

            toast.innerHTML = `
                <div class="w-8 h-8 rounded-xl flex items-center justify-center mr-3 shrink-0 ${iconBg}">
                    <i class="${iconClass} text-sm"></i>
                </div>
                <div class="flex-1 pr-2">
                    <div class="text-xs font-black tracking-tight text-white mb-0.5">${heading}</div>
                    <div class="text-xs text-slate-300 leading-snug font-medium">${message}</div>
                </div>
                <button type="button" onclick="dismissToast('${toastId}')" class="text-slate-400 hover:text-white p-1 text-xs shrink-0 transition">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            `;

            container.appendChild(toast);

            setTimeout(() => {
                dismissToast(toastId);
            }, 3500);
        }

        function dismissToast(id) {
            const el = document.getElementById(id);
            if (el) {
                el.classList.remove('toast-animate-in');
                el.classList.add('toast-animate-out');
                setTimeout(() => el.remove(), 220);
            }
        }

        // Global Alert Override: NEVER show ugly browser "localhost says" popup again!
        window.alert = function(msg) {
            showToast(msg, 'warning', 'Stock Notice');
        };
    </script>

    <!-- Floating Emergency Tech Support Circular Button (Scroll-To-Show) -->
    <div id="floatingSupportBtn" class="fixed bottom-20 md:bottom-6 right-4 md:right-6 z-40 opacity-0 translate-y-6 pointer-events-none transition-all duration-300 no-print">
        <button type="button" onclick="toggleSupportModal()" title="Mr.Link Tech Hotline & Support"
                class="relative w-12 h-12 rounded-full bg-gradient-to-tr from-cyan-600 via-indigo-600 to-slate-900 text-white shadow-xl shadow-cyan-950/40 hover:shadow-cyan-500/40 border border-white/20 hover:scale-110 active:scale-95 flex items-center justify-center transition-all cursor-pointer group">
            <i class="fa-solid fa-headset text-base text-cyan-200 group-hover:text-white transition"></i>
            <!-- Pulse Status Indicator -->
            <span class="absolute -top-0.5 -right-0.5 flex h-3 w-3">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-cyan-500 border border-slate-950"></span>
            </span>
        </button>
    </div>

    <!-- ==================== MR.LINK TECHNOLOGY SUPPORT MODAL ==================== -->
    <div id="mrLinkSupportModal" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-xs hidden flex items-center justify-center p-4 animate-in fade-in duration-200 no-print">
        <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full overflow-hidden border border-slate-100 text-slate-800">
            
            <!-- Modal Header -->
            <div class="p-5 bg-gradient-to-r from-slate-950 via-slate-900 to-indigo-950 text-white flex items-center justify-between border-b border-white/10 relative overflow-hidden">
                <div class="absolute -right-10 -bottom-10 w-28 h-28 bg-cyan-500/20 rounded-full blur-2xl pointer-events-none"></div>

                <div class="flex items-center space-x-3 relative z-10">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white text-lg font-black shadow-md shadow-cyan-500/30">
                        <i class="fa-solid fa-microchip"></i>
                    </div>
                    <div>
                        <div class="flex items-center space-x-2">
                            <h3 class="text-base font-black tracking-tight text-white">Mr.Link Technology</h3>
                            <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-cyan-400 text-slate-950">Official Support</span>
                        </div>
                        <p class="text-xs text-slate-300">Software Engineering & Technical Maintenance</p>
                    </div>
                </div>
                <button type="button" onclick="toggleSupportModal()" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition cursor-pointer relative z-10">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="p-5 sm:p-6 space-y-4">
                <div class="text-xs text-slate-600 font-medium leading-relaxed bg-slate-50 p-3.5 rounded-2xl border border-slate-200/80">
                    <i class="fa-solid fa-info-circle text-cyan-600 mr-1.5"></i>
                    පද්ධතියේ කිසියම් හදිසි තාක්ෂණික දෝෂයක් හෝ සහායක් සඳහා අපගේ මෘදුකාංග ඉංජිනේරු කණ්ඩායම පහත දුරකථන අංක ඔස්සේ වහාම අමතන්න.
                </div>

                <!-- Contact Card 1: Developer -->
                <div class="p-4 rounded-2xl bg-gradient-to-br from-cyan-50/70 to-blue-50/50 border border-cyan-200/80 space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2.5">
                            <div class="w-8 h-8 rounded-xl bg-cyan-600 text-white flex items-center justify-center text-xs font-bold shadow-xs">
                                <i class="fa-solid fa-code"></i>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Lead System Developer</span>
                                <strong class="text-sm font-black text-slate-900">077-3093941</strong>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-100 text-cyan-800">Developer</span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <a href="tel:0773093941" 
                           class="py-2 px-3 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white text-xs font-bold transition flex items-center justify-center space-x-1.5 shadow-xs">
                            <i class="fa-solid fa-phone text-[11px]"></i>
                            <span>Call Dev</span>
                        </a>
                        <a href="https://wa.me/94773093941?text=Hello%20Mr.Link%20Technology,%20I%20need%20support%20for%20Dhanesha%20Distributors%20POS%20System." 
                           target="_blank"
                           class="py-2 px-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition flex items-center justify-center space-x-1.5 shadow-xs">
                            <i class="fa-brands fa-whatsapp text-sm"></i>
                            <span>WhatsApp</span>
                        </a>
                    </div>
                </div>

                <!-- Contact Card 2: Tech Lead -->
                <div class="p-4 rounded-2xl bg-gradient-to-br from-indigo-50/70 to-purple-50/50 border border-indigo-200/80 space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2.5">
                            <div class="w-8 h-8 rounded-xl bg-indigo-600 text-white flex items-center justify-center text-xs font-bold shadow-xs">
                                <i class="fa-solid fa-user-gear"></i>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Technical Lead</span>
                                <strong class="text-sm font-black text-slate-900">076-2529906</strong>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800">Tech Lead</span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <a href="tel:0762529906" 
                           class="py-2 px-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition flex items-center justify-center space-x-1.5 shadow-xs">
                            <i class="fa-solid fa-phone text-[11px]"></i>
                            <span>Call Lead</span>
                        </a>
                        <a href="https://wa.me/94762529906?text=Hello%20Mr.Link%20Technology,%20I%20need%20technical%20assistance%20for%20Dhanesha%20Distributors%20POS%20System." 
                           target="_blank"
                           class="py-2 px-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition flex items-center justify-center space-x-1.5 shadow-xs">
                            <i class="fa-brands fa-whatsapp text-sm"></i>
                            <span>WhatsApp</span>
                        </a>
                    </div>
                </div>

                <!-- Footer info in modal -->
                <div class="pt-2 text-center text-[11px] text-slate-400 border-t border-slate-100 flex items-center justify-between">
                    <span>Engineered for <strong>Dhanesha Distributors</strong></span>
                    <span class="font-bold text-slate-500">Mr.Link Tech</span>
                </div>
            </div>

        </div>
    </div>

    <!-- ==================== AUTO-LOCK INACTIVITY PRIVACY SCREEN ==================== -->
    <style>
        @keyframes lockShake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-8px); }
            40%, 80% { transform: translateX(8px); }
        }
        .animate-lock-shake {
            animation: lockShake 0.35s ease-in-out;
        }
    </style>

    <div id="autoLockScreenModal" class="fixed inset-0 z-[9999] bg-slate-950/85 backdrop-blur-xl hidden flex items-center justify-center p-4 select-none no-print">
        <div id="autoLockCard" class="relative w-full max-w-sm bg-gradient-to-b from-slate-900 to-slate-950 border border-slate-700/80 rounded-3xl shadow-2xl overflow-hidden p-6 text-center text-white space-y-5">
            <!-- Glow background -->
            <div class="absolute -top-12 left-1/2 -translate-x-1/2 w-44 h-44 bg-cyan-500/20 rounded-full blur-3xl pointer-events-none"></div>

            <!-- Top Security Badge -->
            <div class="flex items-center justify-center space-x-2 text-[10px] font-black text-cyan-300 uppercase tracking-widest bg-cyan-950/70 border border-cyan-800/60 py-1 px-3.5 rounded-full w-fit mx-auto shadow-inner">
                <i class="fa-solid fa-lock text-xs text-cyan-400"></i>
                <span>Privacy Auto-Lock</span>
            </div>

            <!-- User Avatar & Details -->
            <div class="space-y-2">
                <div class="relative mx-auto w-16 h-16 rounded-2xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-2xl font-black text-white shadow-lg shadow-cyan-500/30 border-2 border-white/20">
                    <?= isset($user['name']) ? strtoupper(substr($user['name'], 0, 1)) : 'U' ?>
                    <span class="absolute -bottom-1 -right-1 w-5 h-5 rounded-full bg-emerald-500 border-2 border-slate-950 flex items-center justify-center text-[9px] text-white">
                        <i class="fa-solid fa-shield"></i>
                    </span>
                </div>
                <div>
                    <h3 class="text-base font-black text-white tracking-tight"><?= htmlspecialchars($user['name'] ?? 'User') ?></h3>
                    <p class="text-xs text-slate-400 capitalize"><?= htmlspecialchars(str_replace('_', ' ', $user['role'] ?? 'Cashier')) ?> &bull; <?= htmlspecialchars($user['branch_name'] ?? 'Cold Room Hub') ?></p>
                </div>
            </div>

            <p class="text-xs text-slate-300 leading-relaxed bg-slate-800/60 p-3 rounded-2xl border border-slate-700/60 text-left">
                <i class="fa-solid fa-user-shield text-cyan-400 mr-1.5"></i>
                ආරක්ෂාව සඳහා තිරය Lock කර ඇත. නැවත වැඩ කිරීමට ඔබගේ <strong>Password</strong> එක ඇතුළත් කරන්න.
            </p>

            <!-- Unlock Form -->
            <form onsubmit="handleScreenUnlock(event)" class="space-y-3.5">
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400 text-xs pointer-events-none">
                        <i class="fa-solid fa-key text-cyan-400"></i>
                    </span>
                    <input type="password" id="autoLockPasswordInput" required placeholder="Enter password to unlock..." autocomplete="current-password"
                           class="w-full pl-9 pr-10 py-3 bg-slate-800/90 border border-slate-700 rounded-2xl text-xs font-bold text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition">
                    <button type="button" onclick="toggleLockPasswordVisibility()" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-200 text-xs cursor-pointer">
                        <i id="lockPasswordEyeIcon" class="fa-solid fa-eye"></i>
                    </button>
                </div>

                <div id="autoLockErrorMsg" class="hidden text-xs text-rose-300 font-bold bg-rose-950/60 border border-rose-800/60 p-2.5 rounded-xl text-left flex items-start space-x-2">
                    <i class="fa-solid fa-triangle-exclamation text-rose-400 mt-0.5 shrink-0"></i>
                    <span id="autoLockErrorText"></span>
                </div>

                <button type="submit" id="autoLockSubmitBtn" class="w-full py-3 bg-gradient-to-r from-cyan-600 via-blue-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-extrabold text-xs rounded-2xl shadow-lg shadow-cyan-600/30 transition-all flex items-center justify-center space-x-2 cursor-pointer">
                    <i class="fa-solid fa-lock-open text-xs"></i>
                    <span>Unlock & Continue</span>
                </button>
            </form>

            <!-- Sign Out Option -->
            <div class="pt-2 border-t border-slate-800 text-center text-xs">
                <a href="logout.php" class="text-slate-400 hover:text-rose-400 font-bold transition inline-flex items-center space-x-1.5">
                    <i class="fa-solid fa-arrow-right-from-bracket text-[11px]"></i>
                    <span>Sign Out / Switch User</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Global Scripts & Modern Toast System -->
    <script>
        function toggleSupportModal() {
            const modal = document.getElementById('mrLinkSupportModal');
            if (modal) {
                modal.classList.toggle('hidden');
            }
        }

        // SweetAlert2 Toast Helper (If available)
        if (typeof Swal !== 'undefined') {
            window.SwalToast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3500,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.onmouseenter = Swal.stopTimer;
                    toast.onmouseleave = Swal.resumeTimer;
                }
            });
        }

        // Floating Support Button Scroll-To-Show logic (Appears after 100px scroll)
        window.addEventListener('scroll', function() {
            const btn = document.getElementById('floatingSupportBtn');
            if (!btn) return;
            if (window.scrollY > 100) {
                btn.classList.remove('opacity-0', 'translate-y-6', 'pointer-events-none');
                btn.classList.add('opacity-100', 'translate-y-0', 'pointer-events-auto');
            } else {
                btn.classList.add('opacity-0', 'translate-y-6', 'pointer-events-none');
                btn.classList.remove('opacity-100', 'translate-y-0', 'pointer-events-auto');
            }
        }, { passive: true });

        // ================= AUTO-LOCK INACTIVITY PRIVACY PROTECTION =================
        const INACTIVITY_LIMIT_MS = 5 * 60 * 1000; // 5 Minutes
        let inactivityTimer = null;

        function resetInactivityTimer() {
            if (sessionStorage.getItem('screen_locked') === 'true') return;
            if (inactivityTimer) clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(lockScreen, INACTIVITY_LIMIT_MS);
        }

        function lockScreen() {
            sessionStorage.setItem('screen_locked', 'true');
            const modal = document.getElementById('autoLockScreenModal');
            if (modal) {
                modal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                const passInput = document.getElementById('autoLockPasswordInput');
                if (passInput) {
                    passInput.value = '';
                    setTimeout(() => passInput.focus(), 150);
                }
            }
        }

        async function handleScreenUnlock(e) {
            e.preventDefault();
            const passInput = document.getElementById('autoLockPasswordInput');
            const errorMsg = document.getElementById('autoLockErrorMsg');
            const errorText = document.getElementById('autoLockErrorText');
            const submitBtn = document.getElementById('autoLockSubmitBtn');
            const card = document.getElementById('autoLockCard');
            const password = passInput.value.trim();

            if (!password) return;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Verifying...';
            errorMsg.classList.add('hidden');

            try {
                const formData = new FormData();
                formData.append('password', password);

                const res = await fetch('ajax_unlock.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await res.json();

                if (data.success) {
                    sessionStorage.removeItem('screen_locked');
                    const modal = document.getElementById('autoLockScreenModal');
                    if (modal) modal.classList.add('hidden');
                    document.body.classList.remove('overflow-hidden');
                    passInput.value = '';
                    resetInactivityTimer();
                    if (window.showToast) {
                        showToast('Welcome back! Screen unlocked.', 'success', 'Unlocked');
                    }
                } else {
                    errorText.innerText = data.message || 'Incorrect password.';
                    errorMsg.classList.remove('hidden');
                    if (card) {
                        card.classList.add('animate-lock-shake');
                        setTimeout(() => card.classList.remove('animate-lock-shake'), 400);
                    }
                    passInput.value = '';
                    passInput.focus();

                    if (data.redirect) {
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 1200);
                    }
                }
            } catch (err) {
                errorText.innerText = 'Network error. Please try again.';
                errorMsg.classList.remove('hidden');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-lock-open mr-1.5"></i> Unlock & Continue';
            }
        }

        function toggleLockPasswordVisibility() {
            const input = document.getElementById('autoLockPasswordInput');
            const icon = document.getElementById('lockPasswordEyeIcon');
            if (!input || !icon) return;
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Activity listeners to reset inactivity timer
        ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach(evt => {
            window.addEventListener(evt, resetInactivityTimer, { passive: true });
        });

        // Initialize state on DOM ready
        if (sessionStorage.getItem('screen_locked') === 'true') {
            lockScreen();
        } else {
            resetInactivityTimer();
        }
    </script>
</body>
</html>
