    <header class="site-header">
        <a href="/dashboard" class="site-header-brand">
            <img src="/imgs/logo.svg" alt="Lernmonitor">
            <span class="site-header-brand-text text-lg text-bold">Lernmonitor</span>
            <span class="site-header-brand-text text-lg text-light">Administration</span>
        </a>
        <div class="site-header-actions">
            <?php if (!empty($showBackBtn)): ?>
            <button class="btn" id="backBtn">
                <img src="/imgs/back.svg" alt="">
                Zurück
            </button>
            <?php endif; ?>

            <?php
            $__hdrUser = Auth::user();
            if (!empty($__hdrUser['linked_teacher_id']) && ($__hdrUser['active_role'] ?? 'admin') === 'admin'):
            ?>
            <form method="post" action="/switch-role" style="display:contents">
                <button type="submit" class="btn">
                    <img src="/imgs/lehrer.svg" alt=""><span class="btn-label">Lehrer Dashboard</span>
                </button>
            </form>
            <?php endif; ?>

            <button class="btn btn-red" id="logoutBtn">
                <img src="/imgs/logout.svg" alt=""><span class="btn-label">Abmelden</span>
            </button>
        </div>
    </header>
    <?php include __DIR__ . '/change-password-modal.php'; ?>
    <script>
    <?php if (!empty($showBackBtn)): ?>
    document.getElementById('backBtn').addEventListener('click', () => history.back());
    <?php endif; ?>
    </script>
