<?php
/**
 * scripts/make_extension_icons.php
 * Sinh icon PNG cho Chrome Extension (chạy 1 lần, không phụ thuộc thư viện ngoài).
 *
 *   php scripts/make_extension_icons.php
 */

declare(strict_types=1);

$dir = dirname(__DIR__) . '/chrome-extension/icons';

if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

/**
 * Vẽ một icon WebArchive: nền bo góc màu xanh + dấu mũi tên xuống (lưu trữ).
 */
function wa_make_icon(int $size, string $path): void
{
    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, true);
    imagesavealpha($img, true);
    imagesetthickness($img, max(1, (int) round($size / 32)));
    imageantialias($img, true);

    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $transparent);

    $blue   = imagecolorallocate($img, 43, 94, 168);
    $blueLt = imagecolorallocate($img, 90, 145, 220);

    // Nền bo góc.
    $radius = (int) round($size * 0.22);
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            // Khoảng cách tới góc bo.
            $cx = min($x, $size - 1 - $x);
            $cy = min($y, $size - 1 - $y);

            if ($cx < $radius && $cy < $radius) {
                $dx = $radius - $cx;
                $dy = $radius - $cy;
                if (($dx * $dx) + ($dy * $dy) > $radius * $radius) {
                    continue; // để trong suốt
                }
            }

            imagefilledrectangle($img, $x, $y, $x, $y, $blue);
        }
    }

    // Vệt sáng trên.
    imagefilledrectangle(
        $img,
        (int) round($size * 0.18),
        (int) round($size * 0.12),
        (int) round($size * 0.82),
        (int) round($size * 0.18),
        $blueLt
    );

    // Mũi tên xuống (mũi tên xuống + thanh ngang) = biểu tượng "lưu".
    $white = imagecolorallocate($img, 255, 255, 255);
    $thick = max(2, (int) round($size * 0.10));

    // Thân mũi tên.
    imagefilledrectangle(
        $img,
        (int) round($size * 0.44),
        (int) round($size * 0.28),
        (int) round($size * 0.56),
        (int) round($size * 0.62),
        $white
    );

    // Đầu mũi tên tam giác.
    $tipY = (int) round($size * 0.78);
    $topY = (int) round($size * 0.60);
    for ($y = $topY; $y <= $tipY; $y++) {
        $ratio = ($y - $topY) / max(1, $tipY - $topY);
        $half  = (int) round($size * 0.22 * (1 - $ratio));
        imagefilledrectangle(
            $img,
            (int) round($size * 0.50) - $half,
            $y,
            (int) round($size * 0.50) + $half,
            $y,
            $white
        );
    }

    // Thanh ngang phía dưới (đáy "hộp lưu").
    imagefilledrectangle(
        $img,
        (int) round($size * 0.28),
        (int) round($size * 0.84),
        (int) round($size * 0.72),
        (int) round($size * 0.84) + $thick - 1,
        $white
    );

    imagepng($img, $path, 9);
    imagedestroy($img);
}

foreach ([16, 32, 48, 128] as $size) {
    $path = $dir . '/icon' . $size . '.png';
    wa_make_icon($size, $path);
    echo 'Đã tạo ' . $path . PHP_EOL;
}
