# Laragon tương đương cho demo: Apache + PHP 8.3 (+ PDO MySQL, mbstring, Argon2id).
# MySQL 8.4 chạy ở service riêng (xem docker-compose.yml, port host 3206).
#
# Lưu ý: image này KHÔNG copy source vào image; docker-compose bind-mount
# thư mục repo vào /var/www/html/restaurant-chain (giống Laragon trỏ docroot
# chứa thư mục restaurant-chain), nên sửa code trên máy host là thấy ngay.

FROM php:8.3-apache

# PHP cần: pdo_mysql (mysqlnd có sẵn trong base image) + mbstring (cần libonig-dev).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libonig-dev \
 && docker-php-ext-install pdo_mysql mbstring \
 && a2enmod rewrite \
 && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername \
 && rm -rf /var/lib/apt/lists/*

# Cho phép .htaccess của dự án hoạt động (chặn truy cập trực tiếp config/, modules/, .git/).
# Nếu thiếu AllowOverride All thì Apache bỏ qua .htaccess => mất lớp chặn
# "Require all denied" của modules/.htaccess (chỉ demo nhưng vẫn nên đúng).
RUN printf '<Directory /var/www/html/restaurant-chain>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
      > /etc/apache2/conf-available/restaurant-chain.conf \
 && a2enconf restaurant-chain

# password_hash(PASSWORD_ARGON2ID) bắt buộc với dự án này (mật khẩu web băm Argon2id).
# Image php:8.3-apache chính thức có build Argon2id; fail sớm + rõ ràng nếu thiếu.
RUN php -r "exit(defined('PASSWORD_ARGON2ID') ? 0 : 1);" \
 || (echo 'ERROR: PHP image chưa có Argon2id (PASSWORD_ARGON2ID) — không thể hash mật khẩu demo.' >&2 && exit 1)

WORKDIR /var/www/html/restaurant-chain

# Đóng gói source vào image (xem .dockerignore). Không chứa config/database.php
# (bị .dockerignore loại) — setup.php sẽ tự sinh bên trong container khi demo chạy.
COPY . /var/www/html/restaurant-chain

EXPOSE 80
