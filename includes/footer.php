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

    <!-- Floating Emergency Tech Support Button -->
    <div class="fixed bottom-20 md:bottom-6 right-4 md:right-6 z-40 no-print">
        <button type="button" onclick="toggleSupportModal()" 
                class="group flex items-center space-x-2.5 px-3.5 py-2.5 rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white shadow-xl shadow-slate-950/30 hover:shadow-cyan-500/20 border border-cyan-500/30 hover:scale-105 active:scale-95 transition-all cursor-pointer ring-2 ring-white/10">
            <div class="w-7 h-7 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white text-xs shadow-inner">
                <i class="fa-solid fa-headset"></i>
            </div>
            <div class="text-left pr-1">
                <span class="text-[9px] uppercase font-bold text-cyan-400 block leading-tight tracking-wider">Mr.Link Tech</span>
                <span class="text-xs font-black text-white block leading-tight">Help & Hotline</span>
            </div>
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
    </script>
</body>
</html>
