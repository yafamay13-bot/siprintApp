<?php
page_start($title, 'app-page dashboard-page');
page_sidebar($title);
?>
<main class="main-content">
  <?php page_topbar($title); ?>
  <div class="page-content dashboard-content">
    <?php page_flash(); ?>
    <section class="dashboard-hero">
      <div class="dashboard-hero-copy"><span class="hero-kicker"><span></span> DINAS PERPUSTAKAAN DAN KEARSIPAN KABUPATEN CIREBON</span><h1>Selamat <?= e($greeting) ?>,<br><em><?= e(explode(' ', $currentUser['name'])[0]) ?>.</em></h1><p>Semoga aktivitas Anda hari ini berjalan lancar.</p><span class="hero-caption"><img src="<?= e(url('/images/logo-cirebon-transparent.png')) ?>" alt="" width="24" height="28">Sistem Informasi Pengiriman dan Pencetakan Dokumen</span></div>
      <a class="button hero-button" href="<?= e(url($currentUser['role'] === 'admin' ? '/admin/antrean' : '/dokumen/baru')) ?>"><?= $currentUser['role'] === 'admin' ? 'Kelola antrean' : 'Ajukan kiriman' ?><span aria-hidden="true">→</span></a><span class="hero-slide-indicator" aria-hidden="true"><i></i><i></i><i></i></span>
    </section>
    <section class="stats-grid dashboard-stats" aria-label="Ringkasan dokumen">
      <?php
      $stats = [
          ['Semua Dokumen', $counts['total'] ?? 0, $currentUser['role'] === 'admin' ? 'Dokumen yang tercatat' : 'Dokumen yang Anda kirim', 'stat-icon-navy', '/dokumen'],
          ['Menunggu Diproses', $counts['menunggu'] ?? 0, 'Menunggu verifikasi', 'stat-icon-violet', $currentUser['role'] === 'admin' ? '/admin/antrean' : '/dokumen'],
          ['Diproses', $counts['diproses'] ?? 0, 'Sedang dalam proses', 'stat-icon-green', $currentUser['role'] === 'admin' ? '/admin/antrean' : '/dokumen'],
          ['Selesai', $counts['selesai'] ?? 0, 'Berhasil dicetak', 'stat-icon-cyan', '/dokumen'],
      ];
      foreach ($stats as [$label, $count, $hint, $icon, $href]) { ?>
        <a class="stat-card dashboard-stat-card" href="<?= e(url($href)) ?>"><span class="dashboard-stat-icon <?= e($icon) ?>">▤</span><span class="dashboard-stat-copy"><strong><?= e($label) ?></strong><b><?= (int)$count ?></b><small><?= e($hint) ?></small></span><span class="dashboard-stat-arrow">→</span></a>
      <?php } ?>
    </section>
    <section class="dashboard-lower-grid">
      <article class="content-card dashboard-activity-card">
        <div class="dashboard-card-heading"><h2><span></span>Aktivitas Terbaru</h2><a class="text-link" href="<?= e(url('/dokumen')) ?>">Lihat semua <span aria-hidden="true">→</span></a></div>
        <?php if ($recentDocuments) { ?><div class="dashboard-activity-list">
          <?php foreach ($recentDocuments as $doc) { ?>
            <a class="dashboard-activity-item" href="<?= e(url('/dokumen/' . $doc['id'])) ?>"><span class="activity-document-icon">▤</span><span class="activity-item-copy"><strong><?= e($doc['title']) ?></strong><small><?= $currentUser['role'] === 'admin' ? e($doc['employee_name'] . ' · ') : '' ?><?= e(format_date($doc['created_at'])) ?></small></span><span class="status-badge status-<?= e($doc['status']) ?>"><span></span><?= e($doc['status']) ?></span><span class="activity-chevron">›</span></a>
          <?php } ?>
        </div><?php } else { ?>
          <div class="dashboard-empty"><div class="empty-folder-art" aria-hidden="true"><span></span><i></i><i></i><i></i></div><strong>Belum ada aktivitas</strong><span>Aktivitas terbaru Anda akan muncul di sini.</span><?php if ($currentUser['role'] === 'pegawai') { ?><a href="<?= e(url('/dokumen/baru')) ?>">Kirim dokumen pertama <span aria-hidden="true">→</span></a><?php } ?></div>
        <?php } ?>
      </article>
      <aside class="content-card dashboard-quick-card"><div class="dashboard-card-heading"><h2><span></span>Akses Cepat</h2></div><nav class="quick-link-list" aria-label="Akses cepat">
        <?php
        $quickLinks = $currentUser['role'] === 'pegawai'
            ? [['Ajukan Pengiriman', '/dokumen/baru'], ['Lihat Dokumen Saya', '/dokumen'], ['Cek Status Dokumen', '/dokumen'], ['Pengaturan Profil', '/profil']]
            : [['Kelola Antrean Cetak', '/admin/antrean'], ['Lihat Seluruh Dokumen', '/dokumen'], ['Kelola Data Pegawai', '/admin/pegawai'], ['Lihat Audit Log', '/admin/audit']];
        foreach ($quickLinks as [$label, $href]) { ?>
          <a class="quick-link" href="<?= e(url($href)) ?>"><span class="quick-link-icon">›</span><span><?= e($label) ?></span><span class="quick-link-arrow">›</span></a>
        <?php } ?>
      </nav><div class="dashboard-service-note"><img src="<?= e(url('/images/logo-cirebon-transparent.png')) ?>" alt="" width="27" height="32"><span><strong>Layanan Internal</strong><small>Untuk pegawai Dinas Kabupaten Cirebon.</small></span></div></aside>
    </section>
    <footer class="page-footer"><span>© <?= date('Y') ?> SIPRINT · Dinas Perpustakaan dan Kearsipan Kabupaten Cirebon</span><span>LAYANAN INTERNAL</span></footer>
<?php page_end(); ?>
