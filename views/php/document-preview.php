<?php page_start($title, 'app-page document-preview-app'); page_sidebar($title); ?>
<main class="main-content"><?php page_topbar($title); ?><div class="page-content document-preview-content document-preview-page" data-auto-print="<?= $autoPrint ? 'true' : 'false' ?>">
  <?php page_flash(); ?>
  <section class="document-preview-heading"><div class="document-preview-title"><span class="file-icon">PDF</span><div><span class="eyebrow">PRATINJAU DOKUMEN</span><h1><?= e($document['title']) ?></h1><p><?= e($document['original_filename']) ?> · <?= (int)$document['copies'] ?> salinan diminta · <?= e($document['status']) ?></p></div></div><div class="document-preview-actions"><a class="button button-primary button-small" href="#document-viewer">Buka dokumen</a><button class="button button-outline button-small document-print-button" type="button" <?= $autoPrint ? 'disabled' : '' ?>>Cetak dokumen</button><a class="button button-outline button-small" href="<?= e(url($backUrl)) ?>"><?= e($backLabel) ?></a></div></section>
  <?php if ($document['notes']) { ?><p class="document-preview-notes"><strong>Keterangan:</strong> <?= e($document['notes']) ?></p><?php } ?>
  <section class="document-preview-frame-wrap" id="document-viewer" aria-label="Pratinjau berkas PDF"><iframe class="document-preview-frame" src="<?= e(url('/dokumen/' . $document['id'] . '/preview-file')) ?>" title="Pratinjau PDF: <?= e($document['title']) ?>"></iframe></section>
  <p class="document-print-hint">Jumlah salinan: <?= (int)$document['copies'] ?>. Jika diminta, sesuaikan jumlah salinan pada dialog cetak.</p>
  <footer class="page-footer"><span>© <?= date('Y') ?> SIPRINT · Kabupaten Cirebon</span><span>LAYANAN INTERNAL</span></footer>
<?php page_end(); ?>
