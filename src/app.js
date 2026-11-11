const path = require('node:path');
const fs = require('node:fs/promises');
const crypto = require('node:crypto');
const express = require('express');
const session = require('express-session');
const MySQLStoreFactory = require('express-mysql-session');
const flash = require('connect-flash');
const helmet = require('helmet');
const rateLimit = require('express-rate-limit');
const bcrypt = require('bcryptjs');
const multer = require('multer');
const { pool } = require('./db');
const { writeAudit } = require('./audit');
const { createNotification, isSafeInternalLink } = require('./notifications');
const { requireAuth, requireRole } = require('./middleware/auth');
const {
  MAX_FILE_SIZE,
  MAX_PROFILE_PHOTO_SIZE,
  createCsrfToken,
  isValidCsrfToken,
  isPdfFile,
  getProfilePhotoExtension,
  isValidDocumentStatus,
  isValidNewPassword,
  canAccessDocument
} = require('./security');

const app = express();
const uploadDirectory = path.resolve(__dirname, '..', 'storage', 'uploads');
const profilePhotoDirectory = path.resolve(__dirname, '..', 'storage', 'profile-photos');
if (!process.env.SESSION_SECRET || process.env.SESSION_SECRET.length < 32) {
  throw new Error('SESSION_SECRET wajib diisi dengan minimal 32 karakter.');
}
const dummyPasswordHash = bcrypt.hashSync('siprint-placeholder-password', 10);
const MySQLStore = MySQLStoreFactory(session);
const sessionStore = new MySQLStore({
  host: process.env.DB_HOST,
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USER,
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME,
  clearExpired: true,
  checkExpirationInterval: 15 * 60 * 1000,
  expiration: 8 * 60 * 60 * 1000,
  schema: { tableName: 'sessions' }
});
app.locals.sessionStoreReady = sessionStore.onReady();
app.locals.closeSessionStore = () => sessionStore.close();

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, '..', 'views'));
app.disable('x-powered-by');
app.use(helmet({
  frameguard: { action: 'sameorigin' },
  contentSecurityPolicy: {
    directives: {
      defaultSrc: ["'self'"],
      styleSrc: ["'self'", 'https://fonts.googleapis.com'],
      fontSrc: ["'self'", 'https://fonts.gstatic.com'],
      scriptSrc: ["'self'"],
      imgSrc: ["'self'", 'data:'],
      objectSrc: ["'none'"],
      frameAncestors: ["'self'"]
    }
  }
}));
app.use(express.urlencoded({ extended: false, limit: '20kb' }));
app.use(express.static(path.join(__dirname, '..', 'public'), {
  maxAge: process.env.NODE_ENV === 'production' ? '1 day' : 0
}));
app.use(session({
  name: 'siprint.sid',
  secret: process.env.SESSION_SECRET,
  store: sessionStore,
  resave: false,
  saveUninitialized: false,
  cookie: {
    httpOnly: true,
    sameSite: 'strict',
    secure: process.env.NODE_ENV === 'production',
    maxAge: 8 * 60 * 60 * 1000
  }
}));
app.use(flash());
app.use((req, res, next) => {
  if (!req.session.csrfToken) req.session.csrfToken = createCsrfToken();
  res.locals.csrfToken = req.session.csrfToken;
  res.locals.currentUser = req.session.user || null;
  res.locals.flashMessages = {
    success: req.flash('success'),
    error: req.flash('error')
  };
  res.locals.formatDate = (date) => new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta'
  }).format(new Date(date));
  const hour = Number(new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    hour12: false,
    timeZone: 'Asia/Jakarta'
  }).format(new Date()));
  res.locals.greeting = hour < 11 ? 'pagi' : hour < 15 ? 'siang' : hour < 19 ? 'sore' : 'malam';
  res.locals.unreadNotificationCount = 0;
  res.locals.recentNotifications = [];
  if (!req.session.user) return next();

  Promise.all([
    pool.execute(
      'SELECT COUNT(*) AS count FROM notifications WHERE user_id = ? AND is_read = FALSE',
      [req.session.user.id]
    ),
    pool.execute(
      `SELECT id, title, message, link, is_read, created_at
       FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5`,
      [req.session.user.id]
    )
  ]).then(([[countRows], [notifications]]) => {
    res.locals.unreadNotificationCount = Number(countRows[0].count);
    res.locals.recentNotifications = notifications;
    next();
  }).catch(next);
});
app.use((req, res, next) => {
  if (req.method === 'POST' && ['/dokumen', '/profil/foto'].includes(req.path)) return next();
  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(req.method)
      && !isValidCsrfToken(req.session.csrfToken, req.body._csrf)) {
    return res.status(403).render('error', {
      title: 'Permintaan tidak valid',
      message: 'Sesi formulir tidak valid atau telah kedaluwarsa. Muat ulang halaman, lalu coba lagi.'
    });
  }
  return next();
});

const loginLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  limit: 10,
  standardHeaders: 'draft-7',
  legacyHeaders: false,
  message: 'Terlalu banyak percobaan masuk. Silakan coba kembali beberapa menit lagi.'
});

const storage = multer.diskStorage({
  destination: async (req, file, callback) => {
    try {
      await fs.mkdir(uploadDirectory, { recursive: true });
      callback(null, uploadDirectory);
    } catch (error) {
      callback(error);
    }
  },
  filename: (req, file, callback) => callback(null, `${crypto.randomUUID()}.pdf`)
});
const documentUpload = multer({
  storage,
  limits: { fileSize: MAX_FILE_SIZE, files: 1 },
  fileFilter: (req, file, callback) => {
    if (path.extname(file.originalname).toLowerCase() !== '.pdf' || file.mimetype !== 'application/pdf') {
      const error = new Error('File harus berformat PDF.');
      error.code = 'INVALID_FILE_TYPE';
      return callback(error);
    }
    return callback(null, true);
  }
});
const profilePhotoUpload = multer({
  storage: multer.diskStorage({
    destination: async (req, file, callback) => {
      try {
        await fs.mkdir(profilePhotoDirectory, { recursive: true });
        callback(null, profilePhotoDirectory);
      } catch (error) {
        callback(error);
      }
    },
    filename: (req, file, callback) => callback(null, `${crypto.randomUUID()}${path.extname(file.originalname).toLowerCase()}`)
  }),
  limits: { fileSize: MAX_PROFILE_PHOTO_SIZE, files: 1 },
  fileFilter: (req, file, callback) => {
    const extension = path.extname(file.originalname).toLowerCase();
    const allowedTypes = new Map([
      ['.jpg', 'image/jpeg'],
      ['.jpeg', 'image/jpeg'],
      ['.png', 'image/png'],
      ['.webp', 'image/webp']
    ]);
    if (allowedTypes.get(extension) !== file.mimetype) {
      const error = new Error('Foto harus berformat JPG, PNG, atau WebP.');
      error.code = 'INVALID_PROFILE_PHOTO_TYPE';
      return callback(error);
    }
    return callback(null, true);
  }
});

async function getDocumentCounts(user) {
  const [rows] = user.role === 'admin'
    ? await pool.execute(
      `SELECT COUNT(*) AS total,
        SUM(status = 'menunggu') AS menunggu,
        SUM(status = 'diproses') AS diproses,
        SUM(status = 'selesai') AS selesai
       FROM documents`
    )
    : await pool.execute(
      `SELECT COUNT(*) AS total,
        SUM(status = 'menunggu') AS menunggu,
        SUM(status = 'diproses') AS diproses,
        SUM(status = 'selesai') AS selesai
       FROM documents WHERE user_id = ?`,
      [user.id]
    );
  return rows[0];
}

app.get('/login', (req, res) => {
  if (req.session.user) return res.redirect('/');
  return res.render('login', { title: 'Masuk' });
});

app.post('/login', loginLimiter, async (req, res, next) => {
  try {
    const email = String(req.body.email || '').trim().toLowerCase();
    const password = String(req.body.password || '');
    const [rows] = await pool.execute(
      'SELECT id, name, email, password_hash, role, is_active, auth_version, profile_photo FROM users WHERE email = ? LIMIT 1',
      [email]
    );
    const user = rows[0];
    const passwordHash = user ? user.password_hash : dummyPasswordHash;
    const passwordMatches = await bcrypt.compare(password, passwordHash);
    if (!user || !passwordMatches || !user.is_active) {
      req.flash('error', 'Email atau kata sandi tidak sesuai.');
      return res.redirect('/login');
    }

    await new Promise((resolve, reject) => {
      req.session.regenerate((error) => error ? reject(error) : resolve());
    });
    req.session.user = {
      id: user.id,
      name: user.name,
      email: user.email,
      role: user.role,
      authVersion: user.auth_version,
      profilePhoto: user.profile_photo
    };
    req.session.csrfToken = createCsrfToken();
    const connection = await pool.getConnection();
    try {
      await connection.beginTransaction();
      await writeAudit(connection, {
        userId: user.id, action: 'login', entityType: 'session', ipAddress: req.ip
      });
      await connection.commit();
    } catch (error) {
      await connection.rollback();
      req.session.destroy(() => {});
      return next(error);
    } finally {
      connection.release();
    }
    req.flash('success', `Selamat datang, ${user.name}.`);
    return res.redirect('/');
  } catch (error) {
    return next(error);
  }
});

app.post('/logout', requireAuth, async (req, res, next) => {
  try {
    await pool.execute(
      'INSERT INTO audit_logs (user_id, action, entity_type, ip_address) VALUES (?, ?, ?, ?)',
      [req.session.user.id, 'logout', 'session', req.ip]
    );
    req.session.destroy((error) => {
      if (error) return next(error);
      res.clearCookie('siprint.sid', { httpOnly: true, sameSite: 'strict' });
      return res.redirect('/login');
    });
  } catch (error) {
    return next(error);
  }
});

app.get('/', requireAuth, async (req, res, next) => {
  try {
    const [counts, recentResult] = await Promise.all([
      getDocumentCounts(req.session.user),
      req.session.user.role === 'admin'
        ? pool.execute(
          `SELECT d.id, d.title, d.copies, d.status, d.created_at, u.name AS employee_name
           FROM documents d JOIN users u ON u.id = d.user_id
           ORDER BY d.created_at DESC LIMIT 6`
        )
        : pool.execute(
          `SELECT id, title, copies, status, created_at
           FROM documents WHERE user_id = ? ORDER BY created_at DESC LIMIT 6`,
          [req.session.user.id]
        )
    ]);
    const [recentDocuments] = recentResult;
    const [queueRows] = req.session.user.role === 'admin'
      ? await pool.execute("SELECT COUNT(*) AS count FROM documents WHERE status = 'menunggu'")
      : [[{ count: 0 }]];
    res.render('dashboard', {
      title: 'Dashboard',
      counts,
      recentDocuments,
      queueCount: queueRows[0].count
    });
  } catch (error) {
    next(error);
  }
});

app.get('/profil', requireAuth, (req, res) => {
  res.render('profile', { title: 'Profil Saya' });
});

app.get('/foto-profil/:id', requireAuth, async (req, res, next) => {
  if (req.session.user.role !== 'admin' && String(req.session.user.id) !== String(req.params.id)) {
    return res.sendStatus(404);
  }
  try {
    const [rows] = await pool.execute(
      'SELECT profile_photo FROM users WHERE id = ? LIMIT 1',
      [req.params.id]
    );
    const photo = rows[0]?.profile_photo;
    if (!photo || path.basename(photo) !== photo) return res.sendStatus(404);
    res.set('Cache-Control', 'private, no-store');
    return res.sendFile(path.join(profilePhotoDirectory, photo), (error) => {
      if (error && !res.headersSent) {
        if (error.code === 'ENOENT') return res.sendStatus(404);
        return next(error);
      }
    });
  } catch (error) {
    return next(error);
  }
});

app.post('/profil/foto', requireAuth, (req, res, next) => {
  profilePhotoUpload.single('profile_photo')(req, res, async (uploadError) => {
    if (!isValidCsrfToken(req.session.csrfToken, req.body._csrf)) {
      if (req.file) {
        await fs.unlink(req.file.path).catch((error) => {
          if (error.code !== 'ENOENT') console.error('Gagal menghapus foto dengan token CSRF tidak valid:', error);
        });
      }
      return res.status(403).render('error', {
        title: 'Permintaan tidak valid',
        message: 'Sesi formulir tidak valid atau telah kedaluwarsa. Muat ulang halaman, lalu coba lagi.'
      });
    }
    if (uploadError) {
      if (uploadError.code === 'LIMIT_FILE_SIZE') {
        req.flash('error', 'Ukuran foto maksimal 2 MB.');
      } else if (uploadError.code === 'INVALID_PROFILE_PHOTO_TYPE') {
        req.flash('error', 'Foto harus berformat JPG, PNG, atau WebP.');
      } else {
        console.error('Gagal menerima foto profil:', uploadError);
        req.flash('error', 'Foto tidak dapat diunggah. Silakan coba kembali.');
      }
      return res.redirect('/profil');
    }
    if (!req.file) {
      req.flash('error', 'Pilih foto profil terlebih dahulu.');
      return res.redirect('/profil');
    }

    let connection;
    try {
      const photoBuffer = await fs.readFile(req.file.path);
      const verifiedExtension = getProfilePhotoExtension(photoBuffer, req.file.mimetype);
      if (!verifiedExtension || path.extname(req.file.originalname).toLowerCase() !== verifiedExtension
          && !(verifiedExtension === '.jpg' && path.extname(req.file.originalname).toLowerCase() === '.jpeg')) {
        await fs.unlink(req.file.path);
        req.flash('error', 'Isi file tidak valid sebagai foto JPG, PNG, atau WebP.');
        return res.redirect('/profil');
      }
      if (path.extname(req.file.path) !== verifiedExtension) {
        const verifiedPath = `${req.file.path.slice(0, -path.extname(req.file.path).length)}${verifiedExtension}`;
        await fs.rename(req.file.path, verifiedPath);
        req.file.path = verifiedPath;
        req.file.filename = path.basename(verifiedPath);
      }

      connection = await pool.getConnection();
      await connection.beginTransaction();
      const [users] = await connection.execute(
        'SELECT profile_photo FROM users WHERE id = ? AND is_active = TRUE FOR UPDATE',
        [req.session.user.id]
      );
      if (!users[0]) {
        await connection.rollback();
        await fs.unlink(req.file.path);
        req.flash('error', 'Akun tidak aktif atau tidak ditemukan.');
        return res.redirect('/login');
      }
      const previousPhoto = users[0].profile_photo;
      await connection.execute(
        'UPDATE users SET profile_photo = ? WHERE id = ?',
        [req.file.filename, req.session.user.id]
      );
      await writeAudit(connection, {
        userId: req.session.user.id,
        action: 'profile_photo_updated',
        entityType: 'user',
        entityId: req.session.user.id,
        ipAddress: req.ip
      });
      await connection.commit();
      req.session.user.profilePhoto = req.file.filename;
      if (previousPhoto && path.basename(previousPhoto) === previousPhoto) {
        await fs.unlink(path.join(profilePhotoDirectory, previousPhoto)).catch((error) => {
          if (error.code !== 'ENOENT') console.error('Gagal menghapus foto profil lama:', error);
        });
      }
      return req.session.save((saveError) => {
        if (saveError) return next(saveError);
        req.flash('success', 'Foto profil berhasil diperbarui.');
        return res.redirect('/profil');
      });
    } catch (error) {
      if (connection) await connection.rollback();
      await fs.unlink(req.file.path).catch((cleanupError) => {
        if (cleanupError.code !== 'ENOENT') console.error('Gagal membersihkan foto profil yang tidak tersimpan:', cleanupError);
      });
      return next(error);
    } finally {
      if (connection) connection.release();
    }
  });
});

app.post('/profil', requireAuth, async (req, res, next) => {
  const name = String(req.body.name || '').trim();
  const email = String(req.body.email || '').trim().toLowerCase();
  const currentPassword = String(req.body.current_password || '');
  const newPassword = String(req.body.new_password || '');
  const confirmPassword = String(req.body.confirm_password || '');
  const changingPassword = newPassword.length > 0;

  if (name.length < 2 || name.length > 120 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
      || email.length > 190) {
    req.flash('error', 'Isi nama lengkap dan alamat email yang valid.');
    return res.redirect('/profil');
  }
  if (changingPassword && (!isValidNewPassword(newPassword, 12) || newPassword !== confirmPassword)) {
    req.flash('error', 'Kata sandi baru harus minimal 12 karakter (maksimal 72 byte) dan konfirmasinya harus cocok.');
    return res.redirect('/profil');
  }
  if (!changingPassword && confirmPassword) {
    req.flash('error', 'Isi kata sandi baru terlebih dahulu.');
    return res.redirect('/profil');
  }

  let connection;
  try {
    connection = await pool.getConnection();
    await connection.beginTransaction();
    const [userRows] = await connection.execute(
      'SELECT id, password_hash, auth_version FROM users WHERE id = ? AND is_active = TRUE FOR UPDATE',
      [req.session.user.id]
    );
    const user = userRows[0];
    if (!user) {
      await connection.rollback();
      req.flash('error', 'Akun tidak aktif atau tidak ditemukan.');
      return res.redirect('/login');
    }

    let passwordHash = user.password_hash;
    if (changingPassword) {
      if (!currentPassword || !await bcrypt.compare(currentPassword, user.password_hash)) {
        await connection.rollback();
        req.flash('error', 'Kata sandi saat ini tidak sesuai.');
        return res.redirect('/profil');
      }
      passwordHash = await bcrypt.hash(newPassword, 12);
      await connection.execute(
        'UPDATE users SET name = ?, email = ?, password_hash = ?, auth_version = auth_version + 1 WHERE id = ?',
        [name, email, passwordHash, user.id]
      );
    } else {
      await connection.execute(
        'UPDATE users SET name = ?, email = ? WHERE id = ?',
        [name, email, user.id]
      );
    }

    await writeAudit(connection, {
      userId: req.session.user.id,
      action: changingPassword ? 'profile_and_password_updated' : 'profile_updated',
      entityType: 'user',
      entityId: req.session.user.id,
      details: { email, passwordChanged: changingPassword },
      ipAddress: req.ip
    });
    await connection.commit();
    req.session.user.name = name;
    req.session.user.email = email;
    if (changingPassword) req.session.user.authVersion = Number(user.auth_version) + 1;
    return req.session.save((saveError) => {
      if (saveError) return next(saveError);
      req.flash('success', changingPassword
        ? 'Profil dan kata sandi berhasil diperbarui.'
        : 'Profil berhasil diperbarui.');
      return res.redirect('/profil');
    });
  } catch (error) {
    if (connection) await connection.rollback();
    if (error.code === 'ER_DUP_ENTRY') {
      req.flash('error', 'Email tersebut sudah digunakan oleh akun lain.');
      return res.redirect('/profil');
    }
    return next(error);
  } finally {
    if (connection) connection.release();
  }
});

app.get('/notifikasi', requireAuth, async (req, res, next) => {
  try {
    const [notifications] = await pool.execute(
      `SELECT id, title, message, link, is_read, created_at
       FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100`,
      [req.session.user.id]
    );
    res.render('notifications', { title: 'Notifikasi', notifications });
  } catch (error) {
    next(error);
  }
});

app.post('/notifikasi/baca-semua', requireAuth, async (req, res, next) => {
  try {
    await pool.execute(
      'UPDATE notifications SET is_read = TRUE, read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND is_read = FALSE',
      [req.session.user.id]
    );
    req.flash('success', 'Semua notifikasi ditandai sudah dibaca.');
    return res.redirect('/notifikasi');
  } catch (error) {
    return next(error);
  }
});

app.post('/notifikasi/:id/baca', requireAuth, async (req, res, next) => {
  try {
    const [rows] = await pool.execute(
      'SELECT link FROM notifications WHERE id = ? AND user_id = ? LIMIT 1',
      [req.params.id, req.session.user.id]
    );
    if (!rows.length) {
      return res.status(404).render('error', {
        title: 'Notifikasi tidak ditemukan',
        message: 'Notifikasi tidak ditemukan atau bukan milik akun Anda.'
      });
    }
    await pool.execute(
      'UPDATE notifications SET is_read = TRUE, read_at = COALESCE(read_at, CURRENT_TIMESTAMP) WHERE id = ? AND user_id = ?',
      [req.params.id, req.session.user.id]
    );
    const destination = isSafeInternalLink(rows[0].link) ? rows[0].link : '/notifikasi';
    return res.redirect(destination);
  } catch (error) {
    return next(error);
  }
});

app.get('/dokumen', requireAuth, async (req, res, next) => {
  try {
    const isAdmin = req.session.user.role === 'admin';
    const [documents] = isAdmin
      ? await pool.execute(
        `SELECT d.*, u.name AS employee_name
         FROM documents d JOIN users u ON u.id = d.user_id
         ORDER BY d.created_at DESC LIMIT 200`
      )
      : await pool.execute(
        'SELECT * FROM documents WHERE user_id = ? ORDER BY created_at DESC LIMIT 200',
        [req.session.user.id]
      );
    res.render('documents', { title: isAdmin ? 'Data Dokumen' : 'Dokumen Saya', documents });
  } catch (error) {
    next(error);
  }
});

app.get('/dokumen/baru', requireRole('pegawai'), (req, res) => {
  res.render('upload', { title: 'Kirim Dokumen' });
});

app.post('/dokumen', requireRole('pegawai'), (req, res, next) => {
  documentUpload.single('document')(req, res, async (uploadError) => {
    if (!isValidCsrfToken(req.session.csrfToken, req.body._csrf)) {
      if (req.file) {
        await fs.unlink(req.file.path).catch((error) => {
          if (error.code !== 'ENOENT') console.error('Gagal menghapus unggahan dengan token CSRF tidak valid:', error);
        });
      }
      return res.status(403).render('error', {
        title: 'Permintaan tidak valid',
        message: 'Sesi formulir tidak valid atau telah kedaluwarsa. Muat ulang halaman, lalu coba lagi.'
      });
    }
    if (uploadError) {
      if (uploadError.code === 'LIMIT_FILE_SIZE') {
        req.flash('error', 'Ukuran file maksimal 15 MB.');
      } else if (uploadError.code === 'INVALID_FILE_TYPE') {
        req.flash('error', 'File harus berformat PDF.');
      } else {
        console.error('Gagal menerima unggahan dokumen:', uploadError);
        req.flash('error', 'File tidak dapat diunggah. Silakan coba kembali.');
      }
      return res.redirect('/dokumen/baru');
    }
    if (!req.file) {
      req.flash('error', 'Pilih file PDF untuk dikirim.');
      return res.redirect('/dokumen/baru');
    }

    let connection;
    try {
      const fileBuffer = await fs.readFile(req.file.path);
      if (!isPdfFile(fileBuffer, req.file.originalname, req.file.mimetype)) {
        await fs.unlink(req.file.path);
        req.flash('error', 'Isi file tidak valid sebagai dokumen PDF.');
        return res.redirect('/dokumen/baru');
      }

      const title = String(req.body.title || '').trim();
      const copies = Number(req.body.copies);
      const notes = String(req.body.notes || '').trim();
      if (title.length < 2 || title.length > 160 || !Number.isInteger(copies) || copies < 1 || copies > 500
          || notes.length > 1000) {
        await fs.unlink(req.file.path);
        req.flash('error', 'Periksa nama dokumen, jumlah salinan (1–500), dan keterangan.');
        return res.redirect('/dokumen/baru');
      }

      connection = await pool.getConnection();
      await connection.beginTransaction();
      const [result] = await connection.execute(
        `INSERT INTO documents
         (user_id, title, copies, notes, original_filename, stored_filename, file_size)
         VALUES (?, ?, ?, ?, ?, ?, ?)`,
        [
          req.session.user.id, title, copies, notes || null,
          path.basename(req.file.originalname).slice(0, 255), req.file.filename, req.file.size
        ]
      );
      await writeAudit(connection, {
        userId: req.session.user.id,
        action: 'document_submitted',
        entityType: 'document',
        entityId: result.insertId,
        details: { title, copies },
        ipAddress: req.ip
      });
      const [admins] = await connection.execute(
        "SELECT id FROM users WHERE role = 'admin' AND is_active = TRUE"
      );
      for (const admin of admins) {
        await createNotification(connection, {
          userId: admin.id,
          title: 'Dokumen baru masuk',
          message: `${req.session.user.name} mengirim dokumen “${title}”.`,
          link: '/admin/antrean'
        });
      }
      await connection.commit();
      req.flash('success', 'Dokumen berhasil dikirim dan masuk ke antrean admin.');
      return res.redirect('/dokumen');
    } catch (error) {
      if (connection) await connection.rollback();
      await fs.unlink(req.file.path).catch((unlinkError) => {
        if (unlinkError.code !== 'ENOENT') console.error('Gagal menghapus unggahan yang batal:', unlinkError);
      });
      return next(error);
    } finally {
      if (connection) connection.release();
    }
  });
});

app.get('/dokumen/:id', requireAuth, async (req, res, next) => {
  try {
    const [rows] = await pool.execute(
      `SELECT id, user_id, title, copies, notes, original_filename, stored_filename, status, created_at
       FROM documents WHERE id = ? LIMIT 1`,
      [req.params.id]
    );
    const document = rows[0];
    if (!canAccessDocument(req.session.user, document)) {
      return res.status(404).render('error', {
        title: 'Dokumen tidak ditemukan',
        message: 'Dokumen tidak ditemukan atau Anda tidak memiliki izin untuk membukanya.'
      });
    }
    const backToQueue = req.session.user.role === 'admin'
      && ['menunggu', 'diproses'].includes(document.status);
    return res.render('document-preview', {
      title: 'Pratinjau Dokumen',
      document,
      backUrl: backToQueue ? '/admin/antrean' : '/dokumen',
      backLabel: backToQueue ? 'Kembali ke antrean' : 'Kembali ke dokumen',
      autoPrint: req.query.print === '1'
    });
  } catch (error) {
    return next(error);
  }
});

app.get('/dokumen/:id/preview-file', requireAuth, async (req, res, next) => {
  try {
    const [rows] = await pool.execute(
      'SELECT id, user_id, stored_filename FROM documents WHERE id = ? LIMIT 1',
      [req.params.id]
    );
    const document = rows[0];
    if (!canAccessDocument(req.session.user, document)
        || !document.stored_filename
        || path.basename(document.stored_filename) !== document.stored_filename) {
      return res.status(404).render('error', {
        title: 'Dokumen tidak ditemukan',
        message: 'Dokumen tidak ditemukan atau Anda tidak memiliki izin untuk membukanya.'
      });
    }
    res.set({
      'Cache-Control': 'private, no-store',
      'Content-Type': 'application/pdf',
      'X-Content-Type-Options': 'nosniff'
    });
    return res.sendFile(path.join(uploadDirectory, document.stored_filename), (error) => {
      if (error && !res.headersSent) {
        if (error.code === 'ENOENT') {
          return res.status(404).render('error', {
            title: 'File tidak ditemukan',
            message: 'File dokumen tidak tersedia di penyimpanan.'
          });
        }
        return next(error);
      }
    });
  } catch (error) {
    return next(error);
  }
});

app.get('/dokumen/:id/file', requireAuth, async (req, res, next) => {
  try {
    const [rows] = await pool.execute(
      `SELECT id, user_id, original_filename, stored_filename
       FROM documents WHERE id = ? LIMIT 1`,
      [req.params.id]
    );
    const document = rows[0];
    if (!canAccessDocument(req.session.user, document)) {
      return res.status(404).render('error', {
        title: 'Dokumen tidak ditemukan',
        message: 'Dokumen tidak ditemukan atau Anda tidak memiliki izin untuk membukanya.'
      });
    }
    if (!document.stored_filename || path.basename(document.stored_filename) !== document.stored_filename) {
      return res.status(404).render('error', {
        title: 'File tidak ditemukan',
        message: 'File dokumen tidak tersedia di penyimpanan.'
      });
    }
    const filePath = path.join(uploadDirectory, document.stored_filename);
    res.setHeader('Cache-Control', 'private, no-store');
    return res.download(filePath, document.original_filename, (error) => {
      if (error && !res.headersSent) next(error);
    });
  } catch (error) {
    return next(error);
  }
});

app.post('/admin/dokumen/:id/hapus', requireRole('admin'), async (req, res, next) => {
  let connection;
  let document;
  try {
    connection = await pool.getConnection();
    await connection.beginTransaction();
    const [rows] = await connection.execute(
      'SELECT id, title, stored_filename FROM documents WHERE id = ? FOR UPDATE',
      [req.params.id]
    );
    document = rows[0];
    if (!document) {
      await connection.rollback();
      req.flash('error', 'Dokumen tidak ditemukan.');
      return res.redirect('/dokumen');
    }
    await connection.execute('DELETE FROM documents WHERE id = ?', [document.id]);
    await writeAudit(connection, {
      userId: req.session.user.id,
      action: 'document_deleted',
      entityType: 'document',
      entityId: document.id,
      details: { title: document.title },
      ipAddress: req.ip
    });
    await connection.commit();
  } catch (error) {
    if (connection) await connection.rollback();
    return next(error);
  } finally {
    if (connection) connection.release();
  }

  try {
    await fs.unlink(path.join(uploadDirectory, path.basename(document.stored_filename)));
    req.flash('success', 'Dokumen dan file terkait berhasil dihapus.');
  } catch (error) {
    if (error.code === 'ENOENT') {
      req.flash('success', 'Data dokumen dihapus; file sebelumnya sudah tidak tersedia.');
    } else {
      console.error(`Data dokumen ${document.id} dihapus, tetapi file gagal dibersihkan:`, error);
      req.flash('error', 'Data dokumen sudah dihapus, tetapi file perlu dibersihkan oleh administrator server.');
    }
  }
  return res.redirect('/dokumen');
});

app.get('/admin/antrean', requireRole('admin'), async (req, res, next) => {
  try {
    const [documents] = await pool.execute(
      `SELECT d.*, u.name AS employee_name, u.email AS employee_email
       FROM documents d JOIN users u ON u.id = d.user_id
       WHERE d.status IN ('menunggu', 'diproses')
       ORDER BY CASE d.status WHEN 'menunggu' THEN 0 ELSE 1 END, d.created_at ASC
       LIMIT 200`
    );
    res.render('queue', { title: 'Antrean Cetak', documents });
  } catch (error) {
    next(error);
  }
});

app.post('/admin/dokumen/:id/status', requireRole('admin'), async (req, res, next) => {
  let connection;
  try {
    connection = await pool.getConnection();
    const status = String(req.body.status || '');
    const reason = String(req.body.rejection_reason || '').trim();
    if (!isValidDocumentStatus(status) || (status === 'ditolak' && (reason.length < 3 || reason.length > 500))) {
      req.flash('error', 'Status atau alasan penolakan tidak valid.');
      return res.redirect('/admin/antrean');
    }

    await connection.beginTransaction();
    const [rows] = await connection.execute(
      'SELECT id, user_id, title, status FROM documents WHERE id = ? FOR UPDATE',
      [req.params.id]
    );
    const document = rows[0];
    const transitions = {
      menunggu: new Set(['diproses', 'ditolak']),
      diproses: new Set(['selesai', 'ditolak'])
    };
    if (!document || !transitions[document.status]?.has(status)) {
      await connection.rollback();
      req.flash('error', 'Perubahan status tidak tersedia untuk dokumen tersebut.');
      return res.redirect('/admin/antrean');
    }
    await connection.execute(
      'UPDATE documents SET status = ?, rejection_reason = ? WHERE id = ?',
      [status, status === 'ditolak' ? reason : null, document.id]
    );
    await writeAudit(connection, {
      userId: req.session.user.id,
      action: 'document_status_changed',
      entityType: 'document',
      entityId: document.id,
      details: { from: document.status, to: status, reason: status === 'ditolak' ? reason : null },
      ipAddress: req.ip
    });
    const statusLabels = {
      diproses: 'sedang diproses',
      selesai: 'selesai dicetak',
      ditolak: 'ditolak'
    };
    const notificationMessage = status === 'ditolak'
      ? `Dokumen “${document.title}” ditolak. Alasan: ${reason}`
      : `Dokumen “${document.title}” ${statusLabels[status]}.`;
    await createNotification(connection, {
      userId: document.user_id,
      title: 'Status dokumen diperbarui',
      message: notificationMessage.slice(0, 500),
      link: '/dokumen'
    });
    await connection.commit();
    req.flash('success', 'Status dokumen berhasil diperbarui.');
    return res.redirect('/admin/antrean');
  } catch (error) {
    if (connection) await connection.rollback();
    return next(error);
  } finally {
    if (connection) connection.release();
  }
});

app.get('/admin/pegawai', requireRole('admin'), async (req, res, next) => {
  try {
    const [employees] = await pool.execute(
      `SELECT id, name, email, is_active, created_at
       FROM users WHERE role = 'pegawai' ORDER BY name ASC`
    );
    res.render('employees', { title: 'Data Pegawai', employees });
  } catch (error) {
    next(error);
  }
});

app.post('/admin/pegawai', requireRole('admin'), async (req, res, next) => {
  const name = String(req.body.name || '').trim();
  const email = String(req.body.email || '').trim().toLowerCase();
  const password = String(req.body.password || '');
  if (name.length < 2 || name.length > 120 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
      || email.length > 190 || !isValidNewPassword(password, 12)) {
    req.flash('error', 'Isi nama, email valid, dan kata sandi minimal 12 karakter (maksimal 72 byte).');
    return res.redirect('/admin/pegawai');
  }

  let connection;
  try {
    connection = await pool.getConnection();
    const passwordHash = await bcrypt.hash(password, 12);
    await connection.beginTransaction();
    const [result] = await connection.execute(
      'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
      [name, email, passwordHash, 'pegawai']
    );
    await writeAudit(connection, {
      userId: req.session.user.id,
      action: 'employee_created',
      entityType: 'user',
      entityId: result.insertId,
      details: { email },
      ipAddress: req.ip
    });
    await connection.commit();
    req.flash('success', `Akun pegawai ${name} berhasil dibuat.`);
    return res.redirect('/admin/pegawai');
  } catch (error) {
    if (connection) await connection.rollback();
    if (error.code === 'ER_DUP_ENTRY') {
      req.flash('error', 'Email tersebut sudah digunakan.');
      return res.redirect('/admin/pegawai');
    }
    return next(error);
  } finally {
    if (connection) connection.release();
  }
});

app.post('/admin/pegawai/:id/aktif', requireRole('admin'), async (req, res, next) => {
  let connection;
  try {
    connection = await pool.getConnection();
    await connection.beginTransaction();
    const [result] = await connection.execute(
      `UPDATE users SET is_active = NOT is_active, auth_version = auth_version + 1
       WHERE id = ? AND role = 'pegawai'`,
      [req.params.id]
    );
    if (!result.affectedRows) {
      await connection.rollback();
      req.flash('error', 'Akun pegawai tidak ditemukan.');
      return res.redirect('/admin/pegawai');
    }
    const [rows] = await connection.execute('SELECT is_active FROM users WHERE id = ?', [req.params.id]);
    await writeAudit(connection, {
      userId: req.session.user.id,
      action: rows[0].is_active ? 'employee_activated' : 'employee_deactivated',
      entityType: 'user',
      entityId: Number(req.params.id),
      ipAddress: req.ip
    });
    await connection.commit();
    req.flash('success', 'Status akun pegawai berhasil diperbarui.');
    return res.redirect('/admin/pegawai');
  } catch (error) {
    if (connection) await connection.rollback();
    return next(error);
  } finally {
    if (connection) connection.release();
  }
});

app.get('/admin/audit', requireRole('admin'), async (req, res, next) => {
  try {
    const [logs] = await pool.execute(
      `SELECT a.id, a.action, a.entity_type, a.entity_id, a.details, a.ip_address,
              a.created_at, u.name AS user_name, u.email AS user_email
       FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
       ORDER BY a.created_at DESC LIMIT 150`
    );
    res.render('audit', { title: 'Audit Log', logs });
  } catch (error) {
    next(error);
  }
});

app.use((req, res) => {
  res.status(404).render('error', {
    title: 'Halaman tidak ditemukan',
    message: 'Halaman yang Anda cari tidak tersedia.'
  });
});

app.use((error, req, res, next) => {
  if (res.headersSent) return next(error);
  if (error.code === 'LIMIT_FILE_SIZE') {
    req.flash('error', 'Ukuran file maksimal 15 MB.');
    return res.redirect('/dokumen/baru');
  }
  console.error('Permintaan SIPRINT gagal:', error);
  return res.status(500).render('error', {
    title: 'Terjadi gangguan',
    message: 'Permintaan belum dapat diproses. Silakan coba kembali.'
  });
});

module.exports = app;
