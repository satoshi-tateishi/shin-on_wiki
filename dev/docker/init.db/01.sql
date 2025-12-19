# create test database
CREATE DATABASE IF NOT EXISTS `bookstack-test`;

# grant rights to bookstack user (main development user)
GRANT ALL PRIVILEGES ON `bookstack-test`.* TO 'bookstack'@'%';
