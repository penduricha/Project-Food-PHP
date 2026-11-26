#!/bin/sh
# Demo F&B Nodes (nhánh arena/4c228e73-project-food-php) bằng Docker.
# - MySQL 8.4: host 127.0.0.1:3206 (3306 trong container; tránh trùng cổng MySQL của máy bạn)
# - Web Apache/PHP 8.3: http://localhost:8080/restaurant-chain/
#
# Dùng:  sh docker-demo.sh          (lần đầu hoặc cập nhật code — tự rebuild image)
#        ./docker-demo.sh           (sau khi chmod +x)
# Dừng:  docker compose restart app  (giữ container + config/database.php trong container)
#        docker compose down         (gỡ container; DỮ LIỆU DB vẫn giữ trong volume fbn_mysql_data)
#        docker compose down -v      (xóa SẠCH dữ liệu, lần sau setup lại từ đầu)
# Chế độ sửa-code-thấy-ngay (không rebuild): xem hướng dẫn trong docker-compose.dev.yml

set -eu
cd "$(dirname -- "$0")"

MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-fbn_demo_root}"
APP_DB_PASSWORD="${APP_DB_PASSWORD:-fbn_demo_app_password}"
APP_DB_USER="${APP_DB_USER:-restaurant_chain_scope_v2_app}"
APP_PORT="${APP_PORT:-8080}"
export MYSQL_ROOT_PASSWORD APP_PORT

# 0) Kiểm tra docker + compose plugin
if ! command -v docker >/dev/null 2>&1; then
  echo "!! Chưa có docker. Cài Docker Engine rồi chạy lại." >&2
  exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "!! Thiếu 'docker compose' (plugin). Cài docker-compose-plugin rồi chạy lại." >&2
  exit 1
fi

# 1) Build image app + bật stack
echo "==> Build & khởi động: MySQL 8.4 (host port 3206), Web (host port ${APP_PORT})…"
docker compose up -d --build

# 2) Chờ MySQL healthy (tối đa ~3 phút)
echo "==> Chờ MySQL sẵn sàng…"
i=0
status="starting"
while [ "$i" -lt 90 ]; do
  status=$(docker inspect --format '{{.State.Health.Status}}' fbn-mysql 2>/dev/null || echo "unknown")
  if [ "$status" = "healthy" ]; then
    break
  fi
  i=$((i + 1))
  printf '.'
  sleep 2
done
echo
if [ "$status" != "healthy" ]; then
  echo "!! MySQL chưa sẵn sàng. Xem log: docker compose logs db" >&2
  exit 1
fi

# 3) Tạo/cập nhật tài khoản MySQL cho app với HOST '%' (kết nối container->container).
#    setup.php của project tạo user chỉ cho '@localhost' (thiết kế cho Laragon cùng host)
#    nên trong Docker app KHÔNG bao giờ kết nối được nếu không làm bước này.
echo "==> Tạo/cập nhật tài khoản MySQL cho app ('${APP_DB_USER}'@'%')…"
docker compose exec -T db mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "
CREATE USER IF NOT EXISTS '${APP_DB_USER}'@'%' IDENTIFIED BY '${APP_DB_PASSWORD}';
ALTER USER '${APP_DB_USER}'@'%' IDENTIFIED BY '${APP_DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE ON restaurant_chain_scope_v2.* TO '${APP_DB_USER}'@'%';
FLUSH PRIVILEGES;"

# 4) Ghi config/database.php BÊN TRONG container app, trỏ host 'db' (không phải 127.0.0.1).
#    Ghi lại mỗi lần chạy nên container recreate cũng không làm mất kết nối.
echo "==> Ghi config/database.php trong container app (host=db)…"
tmp_config="$(mktemp)"
cat > "$tmp_config" <<PHP
<?php
declare(strict_types=1);
// Sinh bởi docker-demo.sh cho stack Docker demo. KHÔNG commit (đã gitignore).
return [
    'host' => 'db',
    'port' => 3306,
    'database' => 'restaurant_chain_scope_v2',
    'username' => '${APP_DB_USER}',
    'password' => '${APP_DB_PASSWORD}',
];
PHP
chmod 0644 "$tmp_config"  # mktemp tạo file 0600 -> Apache (www-data) không đọc được
docker compose cp "$tmp_config" app:/var/www/html/restaurant-chain/config/database.php
rm -f "$tmp_config"
# Đảm bảo quyền đọc đúng cho Apache (www-data): cp có thể giữ uid host nên chown lại trong container
docker compose exec -T app sh -c 'chown www-data:www-data /var/www/html/restaurant-chain/config/database.php && chmod 0640 /var/www/html/restaurant-chain/config/database.php'

# 5) Migration (cần chỉ đúng host nội bộ db:3306, KHÔNG phải 127.0.0.1)
echo "==> Chạy migration (001 -> 006)…"
docker compose exec -T \
  -e RESTAURANT_SETUP_HOST=db \
  -e RESTAURANT_SETUP_PORT=3306 \
  -e RESTAURANT_SETUP_USER=root \
  -e RESTAURANT_SETUP_PASSWORD="${MYSQL_ROOT_PASSWORD}" \
  app php database/migrate.php

# 6) Setup đầy đủ (idempotent): tạo database + 5 tài khoản demo.
#    Đã có config/database.php (bước 4) nên setup KHÔNG tạo user ngẫu nhiên trùng lặp;
#    chạy lại KHÔNG đổi mật khẩu tài khoản web đã có và BỎ QUA migration đã áp dụng.
echo "==> Chạy setup (mật khẩu khởi tạo tài khoản web demo: 123456)…"
docker compose exec -T \
  -e RESTAURANT_SETUP_HOST=db \
  -e RESTAURANT_SETUP_PORT=3306 \
  -e RESTAURANT_SETUP_USER=root \
  -e RESTAURANT_SETUP_PASSWORD="${MYSQL_ROOT_PASSWORD}" \
  -e RESTAURANT_DEMO_PASSWORD=123456 \
  app php database/setup.php

# 7) Mở khóa đăng nhập: xóa bộ đếm nhập sai (tài khoản bị khóa 15 phút sau 5 lần sai).
#    Demo thường bị khóa do thử nhiều lần; bước này an toàn, không đụng dữ liệu nghiệp vụ.
echo "==> Mở khóa đăng nhập (xóa bộ đếm GioiHanDangNhap)…"
docker compose exec -T db mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" restaurant_chain_scope_v2 -e "DELETE FROM GioiHanDangNhap;"

echo
echo "==================== DEMO SẴN SÀNG ===================="
echo "Web:         http://localhost:${APP_PORT}/restaurant-chain/login.php"
echo "             (mở http://localhost:${APP_PORT}/ cũng tự chuyển về trang đăng nhập)"
echo "MySQL host:  127.0.0.1:3206   user=root   password=${MYSQL_ROOT_PASSWORD}"
echo "Database:    restaurant_chain_scope_v2"
echo "Tài khoản web demo (mật khẩu 123456):"
echo "             chu_chuoi | quan_ly | phuc_vu | bep | kho"
echo "Xem schema:  docker compose exec db mysql -uroot -p${MYSQL_ROOT_PASSWORD} restaurant_chain_scope_v2"
echo "Đăng nhập 401? Chạy lại: sh docker-demo.sh   (bước 7 tự mở khóa tài khoản)"
echo "Sửa code?    Chạy lại: sh docker-demo.sh   (tự build lại image từ source hiện tại)"
echo "Dừng demo:   docker compose down          (giữ dữ liệu)"
echo "             docker compose down -v       (xóa SẠCH dữ liệu)"
echo "======================================================="
