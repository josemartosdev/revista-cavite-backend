ALTER TABLE users ADD COLUMN username VARCHAR(80) NULL AFTER id;
UPDATE users SET username = CONCAT('user', id) WHERE username IS NULL;
ALTER TABLE users MODIFY username VARCHAR(80) NOT NULL;
CREATE UNIQUE INDEX users_username_unique ON users (username);
ALTER TABLE users MODIFY email VARCHAR(190) NULL;
