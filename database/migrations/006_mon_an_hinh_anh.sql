-- Ảnh minh họa cho 6 món mẫu (MA001-MA006), lấy từ kết quả Google Images.
-- Chỉ cập nhật khi món chưa có ảnh, không ghi đè ảnh người dùng đã chọn.
-- An toàn khi chạy lại nhờ điều kiện hinhAnh IS NULL OR hinhAnh=''.
UPDATE MonAn SET hinhAnh='https://pos.nvncdn.com/867afd-52643/art/artCT/20230830_0n8HY7hQ.jpg' WHERE maMon='MA001' AND (hinhAnh IS NULL OR hinhAnh='');
UPDATE MonAn SET hinhAnh='https://i0.wp.com/beyondsweetandsavory.com/wp-content/uploads/2021/09/Vietnamese-grilled-beef-wrapped-in-betel-leaves-Vy-Tran-12-of-12-853x1280-1.jpg?resize=610,916&ssl=1' WHERE maMon='MA002' AND (hinhAnh IS NULL OR hinhAnh='');
UPDATE MonAn SET hinhAnh='https://img-global.cpcdn.com/recipes/572868ed33b0bef2/300x220cq80/ca-h%E1%BB%93i-s%E1%BB%91t-chanh-leo-recipe-main-photo.jpg' WHERE maMon='MA003' AND (hinhAnh IS NULL OR hinhAnh='');
UPDATE MonAn SET hinhAnh='https://thewoksoflife.com/wp-content/uploads/2025/07/shaking-beef-12.jpg' WHERE maMon='MA004' AND (hinhAnh IS NULL OR hinhAnh='');
UPDATE MonAn SET hinhAnh='https://cdn.tgdd.vn/2021/01/CookRecipe/Avatar/com-chien-hai-san-thumbnail-1.jpg' WHERE maMon='MA005' AND (hinhAnh IS NULL OR hinhAnh='');
UPDATE MonAn SET hinhAnh='https://vietnamdiscovery.com/wp-content/uploads/2026/10/mushroom-hotpot-recipe-lau-nam-1-vd.jpg' WHERE maMon='MA006' AND (hinhAnh IS NULL OR hinhAnh='');
