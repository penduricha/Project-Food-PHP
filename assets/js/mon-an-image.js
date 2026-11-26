'use strict';

// Module mon-an: hỗ trợ lấy ảnh món ăn từ Google Ảnh (SD-19/21).
// Luồng: bấm "Tìm ảnh trên Google" -> chọn ảnh trên Google Ảnh -> sao chép địa chỉ hình ảnh
// -> dán vào ô đường dẫn. Link trang kết quả google.com/imgres?imgurl=... được tự tách ảnh gốc.
(function () {
    const moduleRoot = document.querySelector('.menu-management');
    if (!moduleRoot) { return; }

    const formCard = document.getElementById('form-mon-an');

    // Khi lưu thất bại, trang hiển thị lại form kèm thông báo lỗi; cuộn tới form để sửa ngay.
    if (formCard && moduleRoot.querySelector('.alert-box')) {
        formCard.scrollIntoView({ block: 'start' });
    }

    const form = formCard ? formCard.querySelector('form') : null;
    const urlField = form ? form.querySelector('[data-mon-an-url]') : null;
    const nameField = form ? form.querySelector('input[name="tenMon"]') : null;
    const preview = form ? form.querySelector('[data-mon-an-preview]') : null;
    const statusText = form ? form.querySelector('[data-mon-an-status]') : null;

    if (!urlField || !preview || !statusText) { return; }

    const imageUrl = (value) => {
        const raw = String(value || '').trim();
        if (raw === '') { return ''; }
        try {
            const imgurl = new URL(raw).searchParams.get('imgurl');
            if (imgurl && /^https?:\/\//i.test(imgurl)) { return imgurl; }
        } catch (error) {
            // Không parse được URL đầy đủ; kiểm tra tiếp bằng biểu thức chính quy bên dưới.
        }
        return /^https?:\/\//i.test(raw) ? raw : '';
    };

    const updatePreview = () => {
        const url = imageUrl(urlField.value);
        preview.classList.remove('is-loaded');
        preview.removeAttribute('src');
        statusText.textContent = '';

        if (urlField.value.trim() === '') { return; }

        if (url === '') {
            statusText.textContent = 'Đường dẫn chưa hợp lệ: cần là địa chỉ http/https của ảnh.';
            return;
        }

        if (url !== urlField.value.trim()) {
            urlField.value = url; // Link google.com/imgres tự đổi thành địa chỉ ảnh gốc.
        }

        preview.onload = () => preview.classList.add('is-loaded');
        preview.onerror = () => {
            statusText.textContent = 'Không tải được ảnh từ đường dẫn này, hãy chọn ảnh khác.';
        };
        preview.src = url;
    };

    urlField.addEventListener('input', updatePreview);
    urlField.addEventListener('change', updatePreview);

    moduleRoot.querySelectorAll('[data-mon-an-google]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            const name = (nameField && nameField.value ? nameField.value : '').trim();
            const query = name !== '' ? name : 'món ăn Việt Nam';
            window.open('https://www.google.com/search?tbm=isch&q=' + encodeURIComponent(query), '_blank', 'noopener');
            statusText.textContent = 'Đã mở Google Ảnh: chọn ảnh, nhấp chuột phải chọn "Sao chép địa chỉ hình ảnh" rồi dán vào ô đường dẫn.';
        });
    });

    moduleRoot.querySelectorAll('[data-mon-an-clear]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            urlField.value = '';
            updatePreview();
            urlField.focus();
        });
    });

    updatePreview();
})();
