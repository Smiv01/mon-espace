<?php
// Lecture d'une capture d'écran (message Wave / Mobile Money) avec Tesseract.
// L'image est ré-encodée, lue puis supprimée tout de suite : rien n'est conservé.

function ocr_binary()
{
    $set = trim((string)cfg('tesseract_path', ''));
    $cands = [];
    if ($set !== '') {
        $cands[] = $set;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        $cands[] = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';
        $cands[] = 'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe';
    } else {
        $cands[] = '/usr/bin/tesseract';
        $cands[] = '/usr/local/bin/tesseract';
        $cands[] = '/opt/homebrew/bin/tesseract';
    }
    foreach ($cands as $c) {
        if (@is_file($c)) {
            return $c;
        }
    }
    return '';
}

function ocr_available()
{
    return function_exists('exec') && function_exists('imagecreatefromstring') && ocr_binary() !== '';
}

function ocr_run($bin, $img, $outBase, $lang)
{
    $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($img) . ' ' . escapeshellarg($outBase) . ' -l ' . $lang . ' --psm 6';
    if (DIRECTORY_SEPARATOR === '\\') {
        $cmd = '"' . $cmd . '"';
    }
    $o = [];
    $code = 1;
    @exec($cmd . ' 2>&1', $o, $code);
    $txt = @is_file($outBase . '.txt') ? (string)@file_get_contents($outBase . '.txt') : '';
    @unlink($outBase . '.txt');
    return $txt;
}

/**
 * @return array [texte|null, message d'erreur|'']
 */
function ocr_read_upload($file)
{
    if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, "Choisis d'abord une capture d'écran."];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, "L'image n'a pas pu être envoyée (trop lourde ?). Essaie avec une capture plus légère."];
    }
    if ($file['size'] > 6 * 1024 * 1024) {
        return [null, 'Image trop lourde (6 Mo maximum).'];
    }
    if (!ocr_available()) {
        return [null, "La lecture des captures n'est pas encore activée sur cet ordinateur. Colle le SMS à la place."];
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] > 6000 || $info[1] > 12000 || $info[0] < 50) {
        return [null, 'Envoie une capture au format JPG ou PNG.'];
    }
    $im = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
    if (!$im) {
        return [null, "Cette image n'est pas lisible."];
    }
    // Réduit les très grandes photos (plus rapide, aussi précis).
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w > 2200) {
        $nh = (int)round($h * 2000 / $w);
        $small = imagecreatetruecolor(2000, $nh);
        imagecopyresampled($small, $im, 0, 0, 0, 0, 2000, $nh, $w, $h);
        imagedestroy($im);
        $im = $small;
        $w = 2000;
        $h = $nh;
    }
    // Agrandit les petites captures : Tesseract lit mieux avec un texte plus grand.
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 1000) {
        $k = 1000 / $w;
        $big = imagecreatetruecolor(1000, (int)round($h * $k));
        imagecopyresampled($big, $im, 0, 0, 0, 0, 1000, (int)round($h * $k), $w, $h);
        imagedestroy($im);
        $im = $big;
    }
    imagefilter($im, IMG_FILTER_GRAYSCALE);
    $base = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'me_ocr_' . bin2hex(random_bytes(8));
    $png = $base . '.png';
    imagepng($im, $png);
    imagedestroy($im);
    $bin = ocr_binary();
    $txt = ocr_run($bin, $png, $base, 'fra+eng');
    if (trim($txt) === '') {
        $txt = ocr_run($bin, $png, $base, 'eng');
    }
    @unlink($png);
    if (trim($txt) === '') {
        return [null, "Je n'ai pas réussi à lire du texte sur cette image. Essaie une capture plus nette, ou colle le SMS."];
    }
    return [mb_substr($txt, 0, 3000), ''];
}
