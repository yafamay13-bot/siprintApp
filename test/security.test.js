const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ejs = require('ejs');
const { isSafeInternalLink } = require('../src/notifications');
const {
  createCsrfToken,
  isValidCsrfToken,
  isPdfFile,
  getProfilePhotoExtension,
  isValidDocumentStatus,
  isValidNewPassword,
  canAccessDocument,
  isSessionActive,
  MAX_FILE_SIZE
} = require('../src/security');

test('CSRF token is random and only accepts the matching value', () => {
  const token = createCsrfToken();
  assert.equal(token.length, 64);
  assert.notEqual(token, createCsrfToken());
  assert.equal(isValidCsrfToken(token, token), true);
  assert.equal(isValidCsrfToken(token, `${token}0`), false);
  assert.equal(isValidCsrfToken(token, undefined), false);
});

test('PDF validation requires the extension, MIME type, and PDF signature', () => {
  const signature = Buffer.from('%PDF-1.7\n');
  assert.equal(isPdfFile(signature, 'berkas.pdf', 'application/pdf'), true);
  assert.equal(isPdfFile(signature, 'berkas.txt', 'application/pdf'), false);
  assert.equal(isPdfFile(signature, 'berkas.pdf', 'text/plain'), false);
  assert.equal(isPdfFile(Buffer.from('not a pdf'), 'berkas.pdf', 'application/pdf'), false);
  assert.equal(MAX_FILE_SIZE, 15 * 1024 * 1024);
});

test('profile photos require a matching supported image signature and MIME type', () => {
  assert.equal(getProfilePhotoExtension(Buffer.from([0xff, 0xd8, 0xff, 0x00]), 'image/jpeg'), '.jpg');
  assert.equal(getProfilePhotoExtension(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), 'image/png'), '.png');
  assert.equal(getProfilePhotoExtension(Buffer.from('RIFF0000WEBP'), 'image/webp'), '.webp');
  assert.equal(getProfilePhotoExtension(Buffer.from('not an image'), 'image/png'), null);
  assert.equal(getProfilePhotoExtension(Buffer.from([0xff, 0xd8, 0xff]), 'image/png'), null);
});

test('only known document statuses are accepted', () => {
  for (const status of ['menunggu', 'diproses', 'selesai', 'ditolak']) {
    assert.equal(isValidDocumentStatus(status), true);
  }
  assert.equal(isValidDocumentStatus('admin'), false);
  assert.equal(isValidDocumentStatus(''), false);
});

test('new passwords respect minimum length and bcrypt byte limits', () => {
  assert.equal(isValidNewPassword('a'.repeat(16), 16), true);
  assert.equal(isValidNewPassword('a'.repeat(15), 16), false);
  assert.equal(isValidNewPassword('a'.repeat(73), 16), false);
  assert.equal(isValidNewPassword('é'.repeat(36), 16), true);
  assert.equal(isValidNewPassword('é'.repeat(37), 16), false);
});

test('document access is limited to its owner or an administrator', () => {
  const document = { user_id: '18' };
  assert.equal(canAccessDocument({ id: 18, role: 'pegawai' }, document), true);
  assert.equal(canAccessDocument({ id: 19, role: 'pegawai' }, document), false);
  assert.equal(canAccessDocument({ id: 19, role: 'admin' }, document), true);
  assert.equal(canAccessDocument(null, document), false);
  assert.equal(canAccessDocument({ id: 18, role: 'pegawai' }, null), false);
});

test('deactivation and account reactivation both invalidate previous sessions', () => {
  const sessionUser = { id: 18, role: 'pegawai', authVersion: 4 };
  assert.equal(isSessionActive(sessionUser, { is_active: 1, auth_version: 4 }), true);
  assert.equal(isSessionActive(sessionUser, { is_active: 0, auth_version: 4 }), false);
  assert.equal(isSessionActive(sessionUser, { is_active: 1, auth_version: 5 }), false);
  assert.equal(isSessionActive(sessionUser, null), false);
});

test('notification redirects are restricted to safe internal paths', () => {
  assert.equal(isSafeInternalLink('/dokumen'), true);
  assert.equal(isSafeInternalLink('/admin/antrean'), true);
  assert.equal(isSafeInternalLink('//example.invalid'), false);
  assert.equal(isSafeInternalLink('https://example.invalid'), false);
  assert.equal(isSafeInternalLink('/\\example.invalid'), false);
  assert.equal(isSafeInternalLink('/dokumen\nLocation: https://example.invalid'), false);
});

test('all EJS pages compile', () => {
  const root = path.join(__dirname, '..', 'views');
  const files = [];
  function collect(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const target = path.join(directory, entry.name);
      if (entry.isDirectory()) collect(target);
      else if (entry.name.endsWith('.ejs')) files.push(target);
    }
  }
  collect(root);
  assert.ok(files.length > 0);
  for (const file of files) {
    assert.doesNotThrow(() => ejs.compile(fs.readFileSync(file, 'utf8'), { filename: file }), file);
  }
});

test('all pages render with their expected data', async () => {
  const root = path.join(__dirname, '..', 'views');
  const date = new Date('2026-11-11T00:00:00Z');
  const document = {
    id: 1,
    title: 'Surat Undangan',
    copies: 2,
    status: 'menunggu',
    created_at: date,
    original_filename: 'undangan.pdf',
    employee_name: 'Pegawai SIPRINT',
    employee_email: 'pegawai@example.invalid',
    notes: '',
    rejection_reason: null
  };
  const locals = {
    title: 'SIPRINT',
    bodyClass: '',
    csrfToken: 'test-token',
    currentUser: { id: 1, name: 'Pegawai SIPRINT', email: 'pegawai@example.invalid', role: 'pegawai', profilePhoto: null },
    flashMessages: { success: [], error: [] },
    formatDate: () => '11 Nov 2026 07.00',
    greeting: 'pagi',
    unreadNotificationCount: 1,
    recentNotifications: [{
      id: 1,
      title: 'Status dokumen diperbarui',
      message: 'Dokumen selesai dicetak.',
      link: '/dokumen',
      is_read: false,
      created_at: date
    }],
    notifications: [{
      id: 1,
      title: 'Status dokumen diperbarui',
      message: 'Dokumen selesai dicetak.',
      link: '/dokumen',
      is_read: false,
      created_at: date
    }],
    counts: { total: 1, menunggu: 1, diproses: 0, selesai: 0 },
    recentDocuments: [document],
    queueCount: 1,
    documents: [document],
    employees: [{ id: 1, name: 'Pegawai SIPRINT', email: 'pegawai@example.invalid', is_active: true, created_at: date }],
    logs: [{
      id: 1,
      action: 'login',
      entity_type: 'session',
      entity_id: null,
      details: {},
      ip_address: '127.0.0.1',
      created_at: date,
      user_name: 'Pegawai SIPRINT',
      user_email: 'pegawai@example.invalid'
    }],
    message: 'Akses tidak tersedia.'
  };
  locals.document = document;
  locals.backUrl = '/admin/antrean';
  locals.backLabel = 'Kembali ke antrean';
  locals.autoPrint = false;
  for (const page of ['login', 'dashboard', 'documents', 'document-preview', 'upload', 'queue', 'employees', 'audit', 'profile', 'notifications', 'error']) {
    const html = await ejs.renderFile(path.join(root, `${page}.ejs`), locals);
    assert.match(html, /SIPRINT/, `${page} should render the brand`);
    assert.match(html, /<\/html>/, `${page} should render a complete document`);
    if (page === 'login') {
      assert.match(html, /class="login-account-help" type="button"/);
      assert.match(html, /id="login-account-detail" hidden/);
      assert.match(html, /class="login-access-note" href="#email"/);
    }
    if (page === 'documents') {
      assert.match(html, /Buka dokumen/);
      assert.match(html, /Cetak dokumen/);
      assert.match(html, /href="\/dokumen\/1\?print=1" aria-label="Cetak Surat Undangan"/);
      assert.match(html, /aria-label="Unduh Surat Undangan"/);
    }
    if (page === 'document-preview') {
      assert.match(html, /Buka dokumen/);
      assert.match(html, /Cetak dokumen/);
      assert.match(html, /\/dokumen\/1\/preview-file/);
      assert.match(html, /Kembali ke antrean/);
    }
    if (page === 'profile') {
      assert.match(html, /action="\/profil\/foto"/);
      assert.match(html, /accept="image\/jpeg,image\/png,image\/webp"/);
      assert.match(html, /Simpan foto/);
    }
  }
  locals.currentUser.profilePhoto = 'profile-photo.jpg';
  const topbar = await ejs.renderFile(path.join(root, 'partials', 'topbar.ejs'), locals);
  assert.match(topbar, /src="\/foto-profil\/1"/);
});
