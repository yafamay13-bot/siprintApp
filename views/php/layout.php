<?php

function page_start(string $title, string $bodyClass = 'app-page'): void
{
    ?>
    <!doctype html>
    <html lang="id">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="theme-color" content="#101e38">
      <title><?= e($title) ?> | SIPRINT Kabupaten Cirebon</title>
      <link rel="stylesheet" href="<?= e(url('/css/app.css')) ?>">
      <script src="<?= e(url('/js/app.js')) ?>" defer></script>
    </head>
    <body class="<?= e($bodyClass) ?>">
    <?php
}

function page_flash(): void
{
    $messages = $GLOBALS['siprintContext']['flashMessages'] ?? [];
    foreach (($messages['success'] ?? []) as $message) { ?>
      <div class="alert alert-success" role="status"><span class="alert-mark">✓</span><?= e($message) ?><button class="alert-close" type="button" aria-label="Tutup notifikasi">×</button></div>
    <?php }
    foreach (($messages['error'] ?? []) as $message) { ?>
      <div class="alert alert-error" role="alert"><span class="alert-mark">!</span><?= e($message) ?><button class="alert-close" type="button" aria-label="Tutup notifikasi">×</button></div>
    <?php }
}

function page_sidebar(string $title): void
{
    $context = $GLOBALS['siprintContext'];
    $user = $context['currentUser'];
    $admin = $user['role'] === 'admin';
    $links = $admin
        ? [['Dashboard', '/', '▦'], ['Data Pegawai', '/admin/pegawai', '♙'], ['Data Dokumen', '/dokumen', '▤'], ['Antrean Cetak', '/admin/antrean', '▣'], ['Audit Log', '/admin/audit', '◷']]
        : [['Dashboard', '/', '▦'], ['Kirim Dokumen', '/dokumen/baru', '↥'], ['Dokumen Saya', '/dokumen', '▤']];
    ?>
    <aside class="sidebar" id="sidebar">
      <a class="brand" href="<?= e(url('/')) ?>" aria-label="SIPRINT beranda"><span class="brand-mark"><img src="<?= e(url('/images/logo-cirebon-transparent.png')) ?>" alt="" width="31" height="37"></span><span class="brand-copy"><strong>SIPRINT</strong><small>LAYANAN INTERNAL</small></span></a>
      <div class="sidebar-section-label">MENU UTAMA</div>
      <nav class="side-nav" aria-label="Navigasi utama">
        <?php foreach ($links as [$label, $href, $icon]) { ?>
          <a class="nav-link <?= $title === $label ? 'active' : '' ?>" href="<?= e(url($href)) ?>"><span aria-hidden="true"><?= e($icon) ?></span><span><?= e($label) ?></span>
            <?php if ($label === 'Antrean Cetak' && (int)($context['queueCount'] ?? 0) > 0) { ?><span class="nav-count"><?= (int)$context['queueCount'] ?></span><?php } ?>
          </a>
        <?php } ?>
      </nav>
      <div class="sidebar-section-label sidebar-account-label">AKUN</div>
      <nav class="side-nav" aria-label="Pengaturan akun">
        <a class="nav-link <?= $title === 'Profil Saya' ? 'active' : '' ?>" href="<?= e(url('/profil')) ?>"><span>♙</span><span>Profil Saya</span></a>
        <a class="nav-link <?= $title === 'Notifikasi' ? 'active' : '' ?>" href="<?= e(url('/notifikasi')) ?>"><span>♧</span><span>Notifikasi</span><?php if ($context['unreadNotificationCount'] > 0) { ?><span class="nav-count"><?= $context['unreadNotificationCount'] > 9 ? '9+' : (int)$context['unreadNotificationCount'] ?></span><?php } ?></a>
      </nav>
      <div class="sidebar-bottom">
        <div class="sidebar-help"><span class="help-icon">i</span><strong>Butuh bantuan?</strong><span>Hubungi administrator SIPRINT.</span></div>
        <div class="sidebar-user">
          <span class="avatar"><?php if ($user['profilePhoto']) { ?><img src="<?= e(url('/foto-profil/' . $user['id'])) ?>" alt=""><?php } else { ?><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?><?php } ?></span>
          <span class="sidebar-user-copy"><strong><?= e($user['name']) ?></strong><small><?= $admin ? 'Administrator' : 'Pegawai' ?></small></span>
          <a class="icon-button logout-button" href="<?= e(url('/profil')) ?>" aria-label="Edit profil" title="Edit profil">✎</a>
        </div>
      </div>
    </aside>
    <?php
}

function page_topbar(string $title): void
{
    $context = $GLOBALS['siprintContext'];
    $user = $context['currentUser'];
    ?>
    <header class="topbar">
      <button class="mobile-menu icon-button" type="button" aria-label="Buka navigasi" aria-expanded="false">☰</button>
      <div class="breadcrumb"><span>Beranda</span><span class="breadcrumb-sep">/</span><strong><?= e($title) ?></strong></div>
      <div class="topbar-right">
        <div class="notification-control">
          <button class="topbar-icon-button notification-trigger" type="button" aria-label="Notifikasi" aria-expanded="false" aria-controls="notification-menu">♧<?php if ($context['unreadNotificationCount'] > 0) { ?><span class="notification-dot"><?= $context['unreadNotificationCount'] > 9 ? '9+' : (int)$context['unreadNotificationCount'] ?></span><?php } ?></button>
          <section class="notification-menu" id="notification-menu" hidden aria-label="Notifikasi terbaru">
            <div class="notification-menu-heading"><span><strong>Notifikasi</strong><small><?= (int)$context['unreadNotificationCount'] ?> belum dibaca</small></span><?php if ($context['unreadNotificationCount'] > 0) { ?><form action="<?= e(url('/notifikasi/baca-semua')) ?>" method="post"><input type="hidden" name="_csrf" value="<?= e($context['csrfToken']) ?>"><button class="notification-mark-all" type="submit">Baca semua</button></form><?php } ?></div>
            <?php if ($context['recentNotifications']) { ?><div class="notification-items">
              <?php foreach ($context['recentNotifications'] as $notification) { ?>
                <form action="<?= e(url('/notifikasi/' . $notification['id'] . '/baca')) ?>" method="post" class="notification-form"><input type="hidden" name="_csrf" value="<?= e($context['csrfToken']) ?>"><button class="notification-item <?= $notification['is_read'] ? '' : 'notification-unread' ?>" type="submit"><span class="notification-item-icon">▤</span><span class="notification-item-copy"><strong><?= e($notification['title']) ?></strong><span><?= e($notification['message']) ?></span><small><?= e(format_date($notification['created_at'])) ?></small></span><?php if (!$notification['is_read']) { ?><span class="notification-item-dot"></span><?php } ?></button></form>
              <?php } ?>
            </div><?php } else { ?><div class="notification-empty"><span class="notification-empty-icon">✓</span><strong>Semua tenang</strong><span>Notifikasi baru akan muncul di sini.</span></div><?php } ?>
            <a class="notification-view-all" href="<?= e(url('/notifikasi')) ?>">Lihat semua notifikasi <span aria-hidden="true">→</span></a>
          </section>
        </div>
        <div class="profile-control">
          <button class="topbar-profile-trigger" type="button" aria-expanded="false" aria-controls="profile-menu"><span class="topbar-avatar"><?php if ($user['profilePhoto']) { ?><img src="<?= e(url('/foto-profil/' . $user['id'])) ?>" alt=""><?php } else { ?><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?><?php } ?></span><span class="topbar-profile-copy"><strong><?= e($user['name']) ?></strong><small><?= $user['role'] === 'admin' ? 'Administrator' : 'Pegawai' ?></small></span><span class="profile-chevron">⌄</span></button>
          <div class="profile-menu" id="profile-menu" hidden><div class="profile-menu-identity"><span class="topbar-avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span><span><strong><?= e($user['name']) ?></strong><small><?= e($user['email']) ?></small></span></div><a href="<?= e(url('/profil')) ?>">Profil saya</a><form action="<?= e(url('/logout')) ?>" method="post"><input type="hidden" name="_csrf" value="<?= e($context['csrfToken']) ?>"><button type="submit">Keluar</button></form></div>
        </div>
      </div>
    </header>
    <?php
}

function page_end(): void
{
    ?>
      </div>
    </main>
    <div class="mobile-overlay"></div>
    </body>
    </html>
    <?php
}
