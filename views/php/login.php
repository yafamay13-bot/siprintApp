<?php page_start($title, 'login-page'); ?>
<main class="login-layout">
  <section class="login-story">
    <a class="brand brand-light" href="<?= e(url('/login')) ?>"><span class="brand-mark brand-mark-light">▣</span><span class="brand-copy"><strong>SIPRINT</strong><small>SISTEM INFORMASI PENGIRIMAN<br>DAN PENCETAKAN DOKUMEN</small></span></a>
    <div class="story-content">
      <span class="eyebrow eyebrow-light"><span class="eyebrow-dot"></span> DINAS PERPUSTAKAAN DAN KEARSIPAN</span>
      <h1>Selamat datang di<br><em>SIPRINT</em></h1>
      <p>Sistem informasi pengiriman dan pencetakan dokumen berbasis web dengan penerapan <strong>Role-Based Access Control (RBAC)</strong> di Dinas Perpustakaan dan Kearsipan Kabupaten Cirebon.</p>
      <div class="story-note"><span class="story-note-icon">✓</span><span><strong>Akses aman, dokumen lebih teratur.</strong><small>Pengiriman dan pencetakan dalam satu alur yang efisien.</small></span></div>
      <div class="print-illustration" aria-hidden="true"><span class="floating-sheet sheet-one"><i></i><i></i><i></i></span><span class="floating-sheet sheet-two"><i></i><i></i><i></i></span><span class="illustration-folder"><i></i></span><svg class="printer-drawing" viewBox="0 0 300 170"><path class="printer-paper" d="M96 8h108v77H96z"/><path class="printer-paper-lines" d="M119 28h63m-63 13h63m-63 13h45"/><path class="printer-body" d="M73 69h155a20 20 0 0 1 20 20v47a13 13 0 0 1-13 13H66a13 13 0 0 1-13-13V89a20 20 0 0 1 20-20z"/><path class="printer-top" d="M73 73h155a18 18 0 0 1 18 18v12H55V91a18 18 0 0 1 18-18z"/><path class="printer-output" d="M86 112h129v42a9 9 0 0 1-9 9H95a9 9 0 0 1-9-9z"/><path class="printer-output-lines" d="M110 128h81m-81 12h81"/></svg></div>
    </div>
    <div class="login-footer"><img src="<?= e(url('/images/logo-cirebon-transparent.png')) ?>" alt="" width="34" height="42"><span>DINAS PERPUSTAKAAN DAN KEARSIPAN<br>KABUPATEN CIREBON · SIPRINT</span></div>
  </section>
  <section class="login-panel"><div class="login-form-wrap"><div class="login-card">
    <div class="login-emblem"><img src="<?= e(url('/images/logo-cirebon-transparent.png')) ?>" alt="Lambang Kabupaten Cirebon" width="72" height="86"></div>
    <div class="login-identity"><h2>SIPRINT</h2><p>Dinas Perpustakaan dan Kearsipan<br>Kabupaten Cirebon</p></div>
    <div class="login-divider"><span></span><i></i><span></span></div>
    <div class="login-heading"><span class="login-user-icon">♙</span><span><strong>Masuk ke akun Anda</strong><small>Silakan login untuk melanjutkan ke sistem</small></span></div>
    <?php page_flash(); ?>
    <form action="<?= e(url('/login')) ?>" method="post" class="form-stack">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
      <div class="input-with-icon"><span aria-hidden="true">✉</span><label class="sr-only" for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" placeholder="Email terdaftar" maxlength="190" required autofocus></div>
      <div class="input-with-icon"><span aria-hidden="true">▣</span><label class="sr-only" for="password">Kata sandi</label><input id="password" name="password" type="password" autocomplete="current-password" placeholder="Kata Sandi" maxlength="128" required><button class="password-toggle" type="button" aria-label="Tampilkan kata sandi" title="Tampilkan kata sandi">◉</button></div>
      <div class="login-help-row"><button class="login-account-help" type="button" aria-expanded="false" aria-controls="login-account-detail"><span class="help-dot" aria-hidden="true">i</span>Akun dikelola administrator</button><a class="login-access-note" href="#email">Akses pegawai terdaftar</a></div>
      <p class="login-account-detail" id="login-account-detail" hidden>Akun SIPRINT dibuat oleh administrator. Pegawai yang sudah terdaftar dapat masuk menggunakan email dan kata sandi akun.</p>
      <button class="button button-primary button-wide login-submit" type="submit">Login <span aria-hidden="true">→</span></button>
    </form>
    <div class="login-security">✓ Akses aman · Role-Based Access Control</div>
  </div><div class="login-panel-footer">© <?= date('Y') ?> SIPRINT <span>·</span> Layanan internal Pemerintah Kabupaten Cirebon</div></div></section>
</main>
</body>
</html>
