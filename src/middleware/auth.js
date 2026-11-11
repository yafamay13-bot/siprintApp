const { pool } = require('../db');
const { isSessionActive } = require('../security');

function requireAuth(req, res, next) {
  if (!req.session.user) {
    req.flash('error', 'Silakan masuk terlebih dahulu.');
    return res.redirect('/login');
  }
  return pool.execute(
    'SELECT is_active, auth_version, profile_photo FROM users WHERE id = ? LIMIT 1',
    [req.session.user.id]
  )
    .then(([rows]) => {
      if (isSessionActive(req.session.user, rows[0])) {
        req.session.user.profilePhoto = rows[0].profile_photo;
        return next();
      }
      req.session.destroy((error) => {
        if (error) return next(error);
        res.clearCookie('siprint.sid', { httpOnly: true, sameSite: 'strict' });
        return res.redirect('/login');
      });
    })
    .catch(next);
}

function requireRole(...roles) {
  return (req, res, next) => {
    return requireAuth(req, res, () => {
      if (!roles.includes(req.session.user.role)) {
        return res.status(403).render('error', {
          title: 'Akses ditolak',
          message: 'Anda tidak memiliki hak akses untuk membuka halaman ini.'
        });
      }
      return next();
    });
  };
}

module.exports = { requireAuth, requireRole };
