#!/bin/sh
# Demo F&B Nodes (nhánh arena/4c228e73-project-food-php) bằng Docker.
# - MySQL 8.4: host 127.0.0.1:3206 (3306 trong container; tránh trùng cổng MySQL của máy bạn)
# - Web Apache/PHP 8.3: http://localhost:8080/restaurant-chain/
#
# Dùng:  sh docker-demo.sh          (lần đầu hoặc cập nhật)
#        ./docker-demo.sh           (sau khi chmod +x)
# Dừng:  docker compose down         (giữ dữ liệu trong volume fbn_mysql_data)
#        docker compose down -v      (xóa SẠCH dữ liệu, lần sau setup lại từ đầu)

set -eu
cd "$(dirname -- "$0")"

MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-fbn_demo_root}"
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

# 3) Migration (cần chỉ đúng host nội bộ db:3306, KHÔNG phải 127.0.0.1)
echo "==> Chạy migration (001 -> 006)…"
docker compose exec -T \
  -e RESTAURANT_SETUP_HOST=db \
  -e RESTAURANT_SETUP_PORT=3306 \
  -e RESTAURANT_SETUP_USER=root \
  -e RESTAURANT_SETUP_PASSWORD="${MYSQL_ROOT_PASSWORD}" \
  app php database/migrate.php

# 4) Setup đầy đủ (idempotent): tạo database/app user/config + 5 tài khoản demo.
#    Chạy lại KHÔNG đổi mật khẩu tài khoản web đã có và BỎ QUA migration đã áp dụng.
echo "==> Chạy setup (mật khẩu khởi tạo tài khoản web demo: 123456)…"
docker compose exec -T \
  -e RESTAURANT_SETUP_HOST=db \
  -e RESTAURANT_SETUP_PORT=3306 \
  -e RESTAURANT_SETUP_USER=root \
  -e RESTAURANT_SETUP_PASSWORD="${MYSQL_ROOT_PASSWORD}" \
  -e RESTAURANT_DEMO_PASSWORD=123456 \
  app php database/setup.php

echo
echo "==================== DEMO SẴN SÀNG ===================="
echo "Web:         http://localhost:${APP_PORT}/restaurant-chain/login.php"
echo "MySQL host:  127.0.0.1:3206   user=root   password=${MYSQL_ROOT_PASSWORD}"
echo "Database:    restaurant_chain_scope_v2"
echo "Tài khoản web demo (mật khẩu 123456):"
echo "             chu_chuoi | quan_ly | phuc_vu | bep | kho"
echo "Xem schema:  docker compose exec db mysql -uroot -p${MYSQL_ROOT_PASSWORD} restaurant_chain_scope_v2"
echo "Dừng demo:   docker compose down          (giữ dữ liệu)"
echo "             docker compose down -v       (xóa SẠCH dữ liệu)"
echo "======================================================="
