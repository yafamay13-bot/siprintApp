<?php page_start($title, 'error-page'); ?>
<main class="error-card"><a class="brand" href="<?= e(url($currentUser ? '/' : '/login')) ?>"><span class="brand-mark"><img src="<?= e(url('/images/logo-cirebon-transparent.png')) ?>" alt="" width="31" height="37"></span><span class="brand-copy"><strong>SIPRINT</strong><small>LAYANAN INTERNAL</small></span></a><span class="error-code"><?= $title === 'Akses ditolak' ? '403' : (($title === 'Halaman tidak ditemukan' || $title === 'Dokumen tidak ditemukan') ? '404' : '—') ?></span><h1><?= e($title) ?></h1><p><?= e($message) ?></p><a class="button button-primary" href="<?= e(url($currentUser ? '/' : '/login')) ?>"><span aria-hidden="true">←</span> Kembali</a></main>
</body>
</html>
