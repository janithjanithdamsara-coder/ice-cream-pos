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

    <!-- Global Scripts -->
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
    </script>
</body>
</html>
