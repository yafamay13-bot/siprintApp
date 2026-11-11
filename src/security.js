const crypto = require('node:crypto');
const path = require('node:path');

const MAX_FILE_SIZE = 15 * 1024 * 1024;
const MAX_PROFILE_PHOTO_SIZE = 2 * 1024 * 1024;
const allowedDocumentStatuses = new Set(['menunggu', 'diproses', 'selesai', 'ditolak']);

function createCsrfToken() {
  return crypto.randomBytes(32).toString('hex');
}

function isValidCsrfToken(expected, supplied) {
  if (typeof expected !== 'string' || typeof supplied !== 'string') return false;
  const expectedBuffer = Buffer.from(expected);
  const suppliedBuffer = Buffer.from(supplied);
  return expectedBuffer.length === suppliedBuffer.length
    && crypto.timingSafeEqual(expectedBuffer, suppliedBuffer);
}

function isPdfFile(buffer, originalName, mimetype) {
  return path.extname(originalName).toLowerCase() === '.pdf'
    && mimetype === 'application/pdf'
    && Buffer.isBuffer(buffer)
    && buffer.subarray(0, 5).toString('ascii') === '%PDF-';
}

function getProfilePhotoExtension(buffer, mimetype) {
  if (!Buffer.isBuffer(buffer)) return null;
  if (mimetype === 'image/jpeg'
      && buffer.length >= 3
      && buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff) return '.jpg';
  if (mimetype === 'image/png'
      && buffer.length >= 8
      && buffer.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) return '.png';
  if (mimetype === 'image/webp'
      && buffer.length >= 12
      && buffer.subarray(0, 4).toString('ascii') === 'RIFF'
      && buffer.subarray(8, 12).toString('ascii') === 'WEBP') return '.webp';
  return null;
}

function isValidDocumentStatus(status) {
  return allowedDocumentStatuses.has(status);
}

function isValidNewPassword(password, minimumLength = 12) {
  return typeof password === 'string'
    && Array.from(password).length >= minimumLength
    && Buffer.byteLength(password, 'utf8') <= 72;
}

function canAccessDocument(user, document) {
  if (!user || !document) return false;
  return user.role === 'admin'
    || (user.role === 'pegawai' && String(user.id) === String(document.user_id));
}

function isSessionActive(sessionUser, account) {
  return Boolean(sessionUser && account && Number(account.is_active) === 1
    && Number(sessionUser.authVersion) === Number(account.auth_version));
}

module.exports = {
  MAX_FILE_SIZE,
  MAX_PROFILE_PHOTO_SIZE,
  createCsrfToken,
  isValidCsrfToken,
  isPdfFile,
  getProfilePhotoExtension,
  isValidDocumentStatus,
  isValidNewPassword,
  canAccessDocument,
  isSessionActive
};
