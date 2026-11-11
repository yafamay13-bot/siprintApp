require('dotenv').config();

const bcrypt = require('bcryptjs');
const { pool } = require('./db');
const { isValidNewPassword } = require('./security');

async function createAdmin() {
  const name = String(process.env.ADMIN_NAME || '').trim();
  const email = String(process.env.ADMIN_EMAIL || '').trim().toLowerCase();
  const password = process.env.ADMIN_PASSWORD || '';
  if (name.length < 2 || name.length > 120 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
      || email.length > 190 || !isValidNewPassword(password, 16)) {
    throw new Error('Isi ADMIN_NAME, ADMIN_EMAIL, dan ADMIN_PASSWORD (minimal 16 karakter, maksimal 72 byte) di environment.');
  }

  const passwordHash = await bcrypt.hash(password, 12);
  await pool.execute(
    'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
    [name, email, passwordHash, 'admin']
  );
  console.log(`Akun admin ${email} berhasil dibuat.`);
}

createAdmin()
  .catch((error) => {
    console.error('Gagal membuat akun admin:', error.code === 'ER_DUP_ENTRY' ? 'Email sudah terdaftar.' : error.message);
    process.exitCode = 1;
  })
  .finally(() => pool.end());
