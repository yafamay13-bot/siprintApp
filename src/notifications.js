async function createNotification(connection, { userId, title, message, link }) {
  await connection.execute(
    'INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)',
    [userId, title, message, link]
  );
}

function isSafeInternalLink(link) {
  return typeof link === 'string'
    && link.startsWith('/')
    && !link.startsWith('//')
    && !link.includes('\\')
    && !/[\r\n]/.test(link);
}

module.exports = { createNotification, isSafeInternalLink };
