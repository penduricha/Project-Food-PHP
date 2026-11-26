<?php
declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function page_url(string $page): string
{
    return url('index.php?' . http_build_query(['page' => $page]));
}

/**
 * Kiểm tra và chuẩn hoá URL hình ảnh món ăn.
 * Chấp nhận URL tuyệt đối http(s) (ví dụ "Copy image address" từ Google Images)
 * hoặc đường dẫn tương đối nội bộ bắt đầu bằng "assets/" (không chứa "..").
 * Đường dẫn nội bộ không được bắt đầu bằng "/" vì BASE_URL của Laragon là /restaurant-chain.
 * Trả về null nếu không hợp lệ.
 */
function dish_image_url(?string $url): ?string
{
    if ($url === null) {
        return null;
    }

    $url = trim($url);

    if ($url === '' || mb_strlen($url) > 255 || preg_match('/\s/', $url)) {
        return null;
    }

    if (preg_match('#^https?://#i', $url)) {
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    if (preg_match('#^assets/[A-Za-z0-9\-._/%]+$#', $url) && !str_contains($url, '..')) {
        return $url;
    }

    return null;
}

/** Chữ cái đại diện của món ăn khi chưa có ảnh. */
function dish_initials(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    return $name === '' ? '//' : mb_strtoupper(mb_substr($name, 0, 2));
}

/**
 * Ô chữ cái đại diện với ảnh món phủ lên trên.
 * Ảnh lỗi (onerror) tự gỡ khỏi DOM để lộ chữ cái đại diện bên dưới — không vỡ giao diện.
 * $initials cho phép truyền chữ cái tuỳ ý (POS dùng mã 2 chữ theo 2 từ đầu).
 */
function dish_thumb_html(string $name, ?string $hinhAnh, string $extraClass = '', ?string $initials = null): string
{
    $classes = 'food-thumb' . ($extraClass !== '' ? ' ' . $extraClass : '');
    $label = $initials !== null && trim($initials) !== '' ? trim($initials) : dish_initials($name);

    $html = '<div class="' . e($classes) . '">'
        . '<span class="food-thumb-initials" aria-hidden="true">' . e($label) . '</span>';

    $image = dish_image_url($hinhAnh);

    if ($image !== null) {
        $html .= '<img class="food-thumb-img" src="' . e($image) . '" alt="' . e($name)
            . '" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">';
    }

    return $html . '</div>';
}
