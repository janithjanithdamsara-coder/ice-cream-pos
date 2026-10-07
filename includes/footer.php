        </main>

        <!-- Footer -->
        <footer class="bg-white border-t border-slate-200 mt-auto py-4 text-center text-xs text-slate-400 no-print">
            <div class="px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row justify-between items-center gap-2">
                <div>
                    <span class="font-bold text-slate-600">FrostyFlow System</span> &copy; <?= date('Y') ?> &bull; Ice Cream Distribution & POS
                </div>
                <div class="flex items-center space-x-3 text-[11px]">
                    <span class="flex items-center text-emerald-600">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-1.5 animate-pulse"></span> System Online
                    </span>
                    <span>Branch: <strong><?= htmlspecialchars($user['branch_name'] ?? 'Main') ?></strong></span>
                    <span>User: <strong><?= htmlspecialchars($user['name'] ?? 'Admin') ?></strong></span>
                </div>
            </div>
        </footer>

    </div> <!-- End Right Side Wrapper (md:pl-64) -->

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
