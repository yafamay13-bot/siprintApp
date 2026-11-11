USE siprint;

ALTER TABLE users
  ADD COLUMN profile_photo VARCHAR(80) NULL AFTER role;
