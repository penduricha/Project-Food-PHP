<?php
if (!defined('APP_ENTRY')) {
    http_response_code(404);
    exit;
}

require_permission('menu.view');

$pdo = db();
$maChuoi = 'FBN';
$error = '';
// Dữ liệu giữ lại cho form khi lưu thất bại (nhánh lỗi/nhập lại của SD-19/21).
$formSticky = null;

function mon_an_redirect(string $message): never
{
    redirect_to('index.php?page=menu&message=' . rawurlencode($message));
}

function mon_an_duplicate(PDO $pdo, string $maNhom, string $tenMon, ?string $exclude = null): bool
{
    $sql = "SELECT tenMon FROM MonAn WHERE maNhom = ?";
    $params = [$maNhom];

    if ($exclude !== null) {
        $sql .= " AND maMon <> ?";
        $params[] = $exclude;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $target = mb_strtolower(preg_replace('/\s+/u', ' ', trim($tenMon)));

    foreach ($stmt->fetchAll() as $row) {
        $existing = mb_strtolower(preg_replace('/\s+/u', ' ', trim($row['tenMon'])));
        if ($existing === $target) {
            return true;
        }
    }

    return false;
}

function mon_an_generate_id(PDO $pdo): string
{
    for ($i = 0; $i < 10; $i++) {
        $id = 'MA' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare('SELECT 1 FROM MonAn WHERE maMon = ?');
        $stmt->execute([$id]);

        if (!$stmt->fetchColumn()) {
            return $id;
        }
    }

    throw new RuntimeException('Không thể tạo mã món ăn. Vui lòng thử lại.');
}

// Chuẩn hóa đường dẫn ảnh món ăn (SD-19/21). Chấp nhận địa chỉ ảnh trực tiếp http/https;
// nếu người dùng dán link trang kết quả Google Ảnh (google.com/imgres?imgurl=...)
// thì tự tách lấy địa chỉ ảnh gốc. Sai định dạng thì báo lỗi để người dùng nhập lại.
function mon_an_image_url(string $raw): string
{
    $raw = trim($raw);

    if ($raw === '') {
        return '';
    }

    if (preg_match('/[?&]imgurl=([^&]+)/i', $raw, $matches)) {
        $candidate = $matches[1];

        if (!preg_match('/^https?:\/\//i', $candidate)) {
            $candidate = urldecode($candidate);
        }

        if (preg_match('/^https?:\/\//i', $candidate)) {
            $raw = $candidate;
        }
    }

    if (!preg_match('/^https?:\/\/\S+$/i', $raw)) {
        throw new RuntimeException(
            'Hình ảnh phải là đường dẫn http/https. Bấm "Tìm ảnh trên Google", sao chép địa chỉ hình ảnh rồi dán vào đây.'
        );
    }

    return $raw;
}

// Xử lý thêm (SD-19), cập nhật (SD-21) và ngừng kinh doanh (SD-22).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('menu.manage');
    require_csrf();

    $action = post_text('action');

    try {
        if ($action === 'add' || $action === 'update') {
            $maMon = trim(post_text('maMon'));
            $maNhom = trim(post_text('maNhom'));
            $tenMon = trim(post_text('tenMon'));
            $donGia = trim(post_text('donGia'));
            $hinhAnhGoc = trim(post_text('hinhAnh'));
            $hinhAnh = mon_an_image_url($hinhAnhGoc);
            $moTa = trim(post_text('moTa'));

            if ($tenMon === '' || mb_strlen($tenMon) > 100) {
                throw new RuntimeException('Tên món không được để trống và tối đa 100 ký tự.');
            }

            if (!preg_match('/^\d{1,16}(\.\d{1,2})?$/', $donGia)) {
                throw new RuntimeException('Đơn giá phải là số không âm, tối đa 2 chữ số thập phân.');
            }

            if (mb_strlen($hinhAnh) > 255 || mb_strlen($moTa) > 500) {
                throw new RuntimeException('Hình ảnh tối đa 255 ký tự, mô tả tối đa 500 ký tự.');
            }

            $pdo->beginTransaction();

            // Thứ tự khóa thống nhất: NhomMon -> MonAn -> ThucDonChiNhanh.
            // Khóa nhóm món FOR UPDATE trước để hai yêu cầu cùng nhóm không kiểm tra
            // trùng tên song song và không tranh chấp khóa với luồng ngừng kinh doanh.
            $groupStmt = $pdo->prepare(
                "SELECT maNhom FROM NhomMon
                 WHERE maNhom = ? AND maChuoi = ? AND trangThai = 'HOAT_DONG'
                 FOR UPDATE"
            );
            $groupStmt->execute([$maNhom, $maChuoi]);

            if (!$groupStmt->fetch()) {
                throw new RuntimeException('Nhóm món không tồn tại hoặc đã ngừng hoạt động.');
            }

            // Khi cập nhật, khóa món và kiểm tra món còn tồn tại, thuộc chuỗi.
            if ($action === 'update') {
                if ($maMon === '') {
                    throw new RuntimeException('Mã món không hợp lệ.');
                }

                $checkDish = $pdo->prepare(
                    "SELECT m.maMon
                     FROM MonAn m
                     JOIN NhomMon n ON n.maNhom = m.maNhom
                     WHERE m.maMon = ? AND n.maChuoi = ?
                     FOR UPDATE"
                );
                $checkDish->execute([$maMon, $maChuoi]);

                if (!$checkDish->fetchColumn()) {
                    throw new RuntimeException('Không tìm thấy món ăn cần cập nhật.');
                }
            }

            // Kiểm tra tên món bị trùng trong cùng nhóm (R08: so sánh chuẩn hóa ở server).
            if (mon_an_duplicate(
                $pdo,
                $maNhom,
                $tenMon,
                $action === 'update' ? $maMon : null
            )) {
                throw new RuntimeException('Tên món đã tồn tại trong nhóm món này.');
            }

            if ($action === 'add') {
                $maMon = mon_an_generate_id($pdo);

                $stmt = $pdo->prepare(
                    "INSERT INTO MonAn
                    (maMon, maNhom, tenMon, donGia, hinhAnh, moTa)
                    VALUES (?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $maMon,
                    $maNhom,
                    $tenMon,
                    $donGia,
                    $hinhAnh !== '' ? $hinhAnh : null,
                    $moTa !== '' ? $moTa : null
                ]);

                $pdo->commit();
                mon_an_redirect('added');
            }

            $stmt = $pdo->prepare(
                "UPDATE MonAn
                 SET maNhom = ?, tenMon = ?, donGia = ?, hinhAnh = ?, moTa = ?
                 WHERE maMon = ?"
            );

            $stmt->execute([
                $maNhom,
                $tenMon,
                $donGia,
                $hinhAnh !== '' ? $hinhAnh : null,
                $moTa !== '' ? $moTa : null,
                $maMon
            ]);

            $pdo->commit();
            mon_an_redirect('updated');
        }

        if ($action === 'disable') {
            $maMon = trim(post_text('maMon'));

            if ($maMon === '') {
                throw new RuntimeException('Mã món không hợp lệ.');
            }

            $pdo->beginTransaction();

            // Thứ tự khóa thống nhất với thêm/cập nhật: NhomMon -> MonAn -> ThucDonChiNhanh.
            // Tìm nhóm món của món (chưa khóa) rồi khóa nhóm trước.
            $findGroup = $pdo->prepare(
                "SELECT n.maNhom
                 FROM MonAn m
                 JOIN NhomMon n ON n.maNhom = m.maNhom
                 WHERE m.maMon = ? AND n.maChuoi = ?"
            );
            $findGroup->execute([$maMon, $maChuoi]);
            $maNhomCuaMon = $findGroup->fetchColumn();

            if ($maNhomCuaMon === false) {
                throw new RuntimeException('Không tìm thấy món ăn.');
            }

            $lockGroup = $pdo->prepare('SELECT maNhom FROM NhomMon WHERE maNhom = ? FOR UPDATE');
            $lockGroup->execute([$maNhomCuaMon]);

            // Khóa món và đọc lại trạng thái sau khi chờ khóa.
            $check = $pdo->prepare(
                "SELECT m.trangThai
                 FROM MonAn m
                 JOIN NhomMon n ON n.maNhom = m.maNhom
                 WHERE m.maMon = ? AND n.maChuoi = ?
                 FOR UPDATE"
            );
            $check->execute([$maMon, $maChuoi]);
            $dish = $check->fetch();

            if (!$dish) {
                throw new RuntimeException('Không tìm thấy món ăn.');
            }

            if ($dish['trangThai'] === 'NGUNG') {
                throw new RuntimeException('Món ăn đã ngừng kinh doanh.');
            }

            // Ngừng kinh doanh món ăn; giữ chi tiết đơn, giá và lịch sử định mức theo R08.
            $stmt = $pdo->prepare(
                "UPDATE MonAn
                 SET trangThai = 'NGUNG'
                 WHERE maMon = ?"
            );
            $stmt->execute([$maMon]);

            // Ngừng bán món tại các chi nhánh đang bán (idempotent).
            $stmt = $pdo->prepare(
                "UPDATE ThucDonChiNhanh
                 SET trangThai = 'TAM_NGUNG'
                 WHERE maMon = ? AND trangThai = 'DANG_BAN'"
            );
            $stmt->execute([$maMon]);

            $pdo->commit();
            mon_an_redirect('disabled');
        }

        throw new RuntimeException('Thao tác không hợp lệ.');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($action === 'add' || $action === 'update') {
            // Giữ nguyên form kèm dữ liệu vừa nhập để người dùng sửa và gửi lại.
            $formSticky = [
                'action' => $action,
                'maMon' => trim(post_text('maMon')),
                'maNhom' => trim(post_text('maNhom')),
                'tenMon' => trim(post_text('tenMon')),
                'donGia' => trim(post_text('donGia')),
                'hinhAnh' => trim(post_text('hinhAnh')),
                'moTa' => trim(post_text('moTa')),
            ];
        }

        if ($e instanceof RuntimeException) {
            $error = $e->getMessage();
        } else {
            error_log('MonAn module: ' . $e->getMessage());
            $error = 'Có lỗi xảy ra khi xử lý dữ liệu. Vui lòng thử lại.';
        }
    }
}

// Thông báo thao tác.
$messages = [
    'added' => 'Thêm món ăn thành công.',
    'updated' => 'Cập nhật món ăn thành công.',
    'disabled' => 'Đã ngừng kinh doanh món ăn.'
];

$messageKey = is_string($_GET['message'] ?? null) ? $_GET['message'] : '';
$success = $messages[$messageKey] ?? '';

// Lấy nhóm món.
$groupStmt = $pdo->prepare(
    "SELECT maNhom, tenNhom
     FROM NhomMon
     WHERE maChuoi = ? AND trangThai = 'HOAT_DONG'
     ORDER BY tenNhom"
);
$groupStmt->execute([$maChuoi]);
$groups = $groupStmt->fetchAll();

// Tìm kiếm và lọc nhóm món (SD-23): tham số PDO bắt buộc; ký tự đại diện LIKE
// được escape bằng ESCAPE '!' để % và _ trong từ khóa tìm đúng nghĩa chữ.
$keyword = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$selectedGroup = trim(is_string($_GET['group'] ?? null) ? $_GET['group'] : '');

$sql = "SELECT m.maMon, m.tenMon, m.donGia, m.hinhAnh, m.moTa,
               m.trangThai, m.trangThaiDinhMuc,
               n.tenNhom, n.maNhom
        FROM MonAn m
        JOIN NhomMon n ON n.maNhom = m.maNhom
        WHERE n.maChuoi = ?";

$params = [$maChuoi];

if ($keyword !== '') {
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword) . '%';
    $sql .= " AND (m.maMon LIKE ? ESCAPE '!' OR m.tenMon LIKE ? ESCAPE '!')";
    $params[] = $like;
    $params[] = $like;
}

if ($selectedGroup !== '') {
    $sql .= " AND m.maNhom = ?";
    $params[] = $selectedGroup;
}

$sql .= " ORDER BY m.trangThai ASC, n.tenNhom, m.tenMon";

$listStmt = $pdo->prepare($sql);
$listStmt->execute($params);
$dishes = $listStmt->fetchAll();

// Liên kết giữ lại bộ lọc đang chọn: xem chi tiết, cập nhật hoặc hủy form
// quay lại danh sách vẫn giữ từ khóa và nhóm món (luồng SD-20/23).
$menuUrl = page_url('menu');
$keywordQuery = $keyword !== '' ? '&q=' . rawurlencode($keyword) : '';
$groupQuery = $selectedGroup !== '' ? '&group=' . rawurlencode($selectedGroup) : '';

// Xem chi tiết món ăn.
$detail = null;
$detailId = trim(is_string($_GET['detail'] ?? null) ? $_GET['detail'] : '');

if ($detailId !== '') {
    $stmt = $pdo->prepare(
        "SELECT m.*, n.tenNhom
         FROM MonAn m
         JOIN NhomMon n ON n.maNhom = m.maNhom
         WHERE m.maMon = ? AND n.maChuoi = ?"
    );
    $stmt->execute([$detailId, $maChuoi]);
    $detail = $stmt->fetch() ?: null;
}

$canManage = can('menu.manage');

// Lưu thất bại: nhận lại chế độ cập nhật từ dữ liệu vừa gửi.
$sticky = is_array($formSticky) ? $formSticky : null;
$stickyAction = $sticky['action'] ?? '';

if ($stickyAction === 'update' && ($sticky['maMon'] ?? '') !== '') {
    $editId = $sticky['maMon'];
} else {
    $editId = trim(is_string($_GET['edit'] ?? null) ? $_GET['edit'] : '');
}

// Lấy món cần cập nhật.
$editDish = null;
if ($editId !== '' && $canManage) {
    $stmt = $pdo->prepare(
        "SELECT m.*
         FROM MonAn m
         JOIN NhomMon n ON n.maNhom = m.maNhom
         WHERE m.maMon = ? AND n.maChuoi = ?"
    );
    $stmt->execute([$editId, $maChuoi]);
    $editDish = $stmt->fetch() ?: null;
}

// Chỉ mở form khi người dùng chọn thêm, cập nhật hoặc khi lưu thất bại cần nhập lại.
$addMode = ($_GET['add'] ?? '') === '1' || $stickyAction === 'add';
$showForm = $addMode || $editId !== '';

// Giá trị hiển thị trong form: ưu tiên dữ liệu vừa nhập khi có lỗi, sau đó dữ liệu món trong DB.
$formValues = [
    'maNhom' => $sticky['maNhom'] ?? (string)($editDish['maNhom'] ?? ''),
    'tenMon' => $sticky['tenMon'] ?? (string)($editDish['tenMon'] ?? ''),
    'donGia' => $sticky['donGia'] ?? (string)($editDish['donGia'] ?? ''),
    'hinhAnh' => $sticky['hinhAnh'] ?? (string)($editDish['hinhAnh'] ?? ''),
    'moTa' => $sticky['moTa'] ?? (string)($editDish['moTa'] ?? ''),
];
$formIsUpdate = $stickyAction === 'update' || $editDish !== null;
$formMaMon = (string)($editDish['maMon'] ?? ($sticky['maMon'] ?? ''));
?>

<div class="screen-content menu-management">

    <div class="section-head">
        <div>
            <span class="eyebrow accent">THỰC ĐƠN TOÀN CHUỖI</span>
            <h2 class="title title-md">Danh sách món ăn</h2>
        </div>

        <?php if ($canManage): ?>
            <a href="<?= e($menuUrl . '&add=1' . $keywordQuery . $groupQuery) ?>#form-mon-an"
               class="btn btn-primary">
                + Thêm món ăn
            </a>
        <?php endif; ?>
    </div>

    <?php if ($success !== ''): ?>
        <div class="success-banner"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert-box"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($detailId !== ''): ?>
        <div class="card" style="padding:20px;margin-bottom:20px;">
            <h3>Chi tiết món ăn</h3>

            <?php if ($detail): ?>
                <?php if (!empty($detail['hinhAnh'])): ?>
                    <div class="food-thumb detail-thumb">
                        <img class="food-thumb-img"
                             src="<?= e($detail['hinhAnh']) ?>"
                             alt="<?= e($detail['tenMon']) ?>"
                             referrerpolicy="no-referrer"
                             onload="this.classList.add('is-loaded')"
                             onerror="this.remove()">
                        <span class="food-thumb-fallback"><?= e(mb_strtoupper(mb_substr($detail['tenMon'], 0, 2))) ?></span>
                    </div>
                <?php endif; ?>

                <p><strong>Mã món:</strong> <?= e($detail['maMon']) ?></p>
                <p><strong>Tên món:</strong> <?= e($detail['tenMon']) ?></p>
                <p><strong>Nhóm món:</strong> <?= e($detail['tenNhom']) ?></p>
                <p><strong>Đơn giá:</strong> <?= number_format((float)$detail['donGia'], 0, ',', '.') ?>đ</p>
                <p><strong>Mô tả:</strong> <?= e($detail['moTa'] ?? '') ?></p>
                <p><strong>Hình ảnh:</strong> <?= e($detail['hinhAnh'] ?? '') ?></p>
                <p><strong>Định mức nguyên vật liệu:</strong> <?= e($detail['trangThaiDinhMuc']) ?></p>
                <p><strong>Trạng thái:</strong> <?= e($detail['trangThai']) ?></p>

                <?php if ($canManage): ?>
                    <a class="btn btn-primary"
                       href="<?= e($menuUrl . '&edit=' . rawurlencode($detail['maMon']) . $keywordQuery . $groupQuery) ?>#form-mon-an">
                        Cập nhật món ăn
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <p>Không tìm thấy món ăn.</p>
            <?php endif; ?>

            <a class="btn btn-ghost" href="<?= e($menuUrl . $keywordQuery . $groupQuery) ?>">
                Quay lại danh sách
            </a>
        </div>
    <?php endif; ?>

    <div class="catalog">
        <div class="catalog-tools">

            <form method="get" action="index.php" class="search-wide">
                <input type="hidden" name="page" value="menu">
                <input type="hidden" name="group" value="<?= e($selectedGroup) ?>">
                <input class="control" type="text" name="q"
                       value="<?= e($keyword) ?>"
                       placeholder="Tìm món ăn, mã món...">
                <button type="submit" class="btn btn-primary">
                    Tìm kiếm
                </button>
            </form>

            <div class="category-tabs">
                <a class="btn <?= $selectedGroup === '' ? 'btn-primary' : 'btn-ghost' ?>"
                   href="<?= e($menuUrl . $keywordQuery) ?>">
                    Tất cả
                </a>

                <?php foreach ($groups as $group): ?>
                    <a class="btn <?= $selectedGroup === $group['maNhom'] ? 'btn-primary' : 'btn-ghost' ?>"
                       href="<?= e($menuUrl . $keywordQuery . '&group=' . rawurlencode($group['maNhom'])) ?>">
                        <?= e($group['tenNhom']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="menu-grid">
            <?php if (!$dishes): ?>
                <div class="card" style="padding:20px;">
                    Không tìm thấy món ăn phù hợp.
                </div>
            <?php endif; ?>

            <?php foreach ($dishes as $dish): ?>
                <div class="card menu-card <?= $dish['trangThai'] === 'NGUNG' ? 'disabled-card' : '' ?>">

                    <div class="food-thumb">
                        <?php if (!empty($dish['hinhAnh'])): ?>
                            <img class="food-thumb-img"
                                 src="<?= e($dish['hinhAnh']) ?>"
                                 alt="<?= e($dish['tenMon']) ?>"
                                 loading="lazy"
                                 referrerpolicy="no-referrer"
                                 onload="this.classList.add('is-loaded')"
                                 onerror="this.remove()">
                        <?php endif; ?>
                        <span class="food-thumb-fallback"><?= e(mb_strtoupper(mb_substr($dish['tenMon'], 0, 2))) ?></span>
                    </div>

                    <div class="menu-info">

                        <?php if ($dish['trangThai'] === 'HOAT_DONG'): ?>
                            <span class="badge badge-success">Đang kinh doanh</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Ngừng kinh doanh</span>
                        <?php endif; ?>

                        <strong style="display:block; white-space:normal; overflow:visible; overflow-wrap:anywhere; word-break:normal; line-height:1.5;">
                            <?= e($dish['tenMon']) ?>
                        </strong>

                        <span><?= e($dish['tenNhom']) ?></span>

                        <b><?= number_format((float)$dish['donGia'], 0, ',', '.') ?>đ</b>

                        <small><?= e($dish['maMon']) ?></small>

                        <a class="btn btn-ghost"
                           href="<?= e($menuUrl . '&detail=' . rawurlencode($dish['maMon']) . $keywordQuery . $groupQuery) ?>">
                            Xem chi tiết
                        </a>

                        <?php if ($canManage): ?>
                            <a class="btn btn-secondary"
                               href="<?= e($menuUrl . '&edit=' . rawurlencode($dish['maMon']) . $keywordQuery . $groupQuery) ?>#form-mon-an">
                                Cập nhật
                            </a>

                            <?php if ($dish['trangThai'] === 'HOAT_DONG'): ?>
                                <form method="post"
                                      onsubmit="return confirm('Bạn có chắc muốn ngừng kinh doanh món này?');">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="disable">
                                    <input type="hidden" name="maMon" value="<?= e($dish['maMon']) ?>">
                                    <button type="submit" class="btn btn-danger">
                                        Ngừng kinh doanh
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($canManage && $showForm): ?>
        <div class="card" id="form-mon-an" style="padding:20px;margin-top:24px;">

            <h3><?= $formIsUpdate ? 'Cập nhật món ăn' : 'Thêm món ăn mới' ?></h3>

            <?php if ($editId !== '' && !$editDish && $sticky === null): ?>
                <p>Không tìm thấy món ăn cần cập nhật.</p>
            <?php else: ?>

                <form method="post">
                    <?= csrf_input() ?>

                    <input type="hidden" name="action"
                           value="<?= $formIsUpdate ? 'update' : 'add' ?>">

                    <?php if ($formIsUpdate): ?>
                        <input type="hidden" name="maMon"
                               value="<?= e($formMaMon) ?>">
                    <?php endif; ?>

                    <div class="form-grid">

                        <div class="field">
                            <label class="field-label">Tên món ăn</label>
                            <input class="control" type="text" name="tenMon"
                                   maxlength="100" required
                                   value="<?= e($formValues['tenMon']) ?>">
                        </div>

                        <div class="field">
                            <label class="field-label">Nhóm món</label>
                            <select class="control" name="maNhom" required>
                                <option value="">-- Chọn nhóm món --</option>

                                <?php foreach ($groups as $group): ?>
                                    <option value="<?= e($group['maNhom']) ?>"
                                        <?= $formValues['maNhom'] === $group['maNhom'] ? 'selected' : '' ?>>
                                        <?= e($group['tenNhom']) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="field">
                            <label class="field-label">Đơn giá (VNĐ)</label>
                            <input class="control" type="number" name="donGia"
                                   min="0" step="0.01" required
                                   value="<?= e($formValues['donGia']) ?>">
                        </div>

                        <div class="field">
                            <label class="field-label">Ảnh món ăn (lấy từ Google Ảnh)</label>

                            <div class="field-image-row">
                                <input class="control" type="text" name="hinhAnh"
                                       maxlength="255" autocomplete="off"
                                       data-mon-an-url
                                       placeholder="Dán địa chỉ ảnh hoặc bấm nút bên cạnh"
                                       value="<?= e($formValues['hinhAnh']) ?>">
                                <button type="button" class="btn btn-secondary" data-mon-an-google>
                                    Tìm ảnh trên Google
                                </button>
                            </div>

                            <img class="mon-an-preview" data-mon-an-preview
                                 alt="Xem trước ảnh món ăn" referrerpolicy="no-referrer">
                            <p class="mon-an-image-status" data-mon-an-status></p>
                            <p class="mon-an-image-hint">
                                Bấm "Tìm ảnh trên Google" để mở Google Ảnh với tên món đã nhập.
                                Chọn ảnh, sao chép địa chỉ hình ảnh rồi dán vào ô trên; link dạng
                                google.com/imgres?imgurl=... sẽ được tự đổi thành ảnh gốc.
                            </p>
                            <button type="button" class="btn btn-ghost mon-an-clear" data-mon-an-clear>
                                Xóa ảnh đã chọn
                            </button>
                        </div>

                        <div class="field">
                            <label class="field-label">Mô tả</label>
                            <textarea class="control textarea" name="moTa"
                                      maxlength="500"><?= e($formValues['moTa']) ?></textarea>
                        </div>

                    </div>

                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">
                            <?= $formIsUpdate ? 'Lưu cập nhật' : 'Thêm món ăn' ?>
                        </button>

                        <a href="<?= e($menuUrl . $keywordQuery . $groupQuery) ?>" class="btn btn-ghost">
                            Hủy
                        </a>
                    </div>

                </form>

            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>
