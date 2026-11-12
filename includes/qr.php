<?php
/**
 * Générateur de code QR (mode octets, correction d'erreur M, versions 1 à 10).
 * Aucune bibliothèque externe : le code QR est dessiné en SVG côté serveur.
 * Compatible PHP 5.5.
 */

function qr_gf_mul($x, $y)
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = ($z << 1) ^ (($z >> 7) * 0x11D);
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z & 0xFF;
}

function qr_rs_divisor($degree)
{
    $result = array_fill(0, $degree - 1, 0);
    $result[] = 1;
    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $result[$j] = qr_gf_mul($result[$j], $root);
            if ($j + 1 < $degree) {
                $result[$j] ^= $result[$j + 1];
            }
        }
        $root = qr_gf_mul($root, 0x02);
    }
    return $result;
}

function qr_rs_remainder($data, $divisor)
{
    $degree = count($divisor);
    $result = array_fill(0, $degree, 0);
    foreach ($data as $b) {
        $factor = $b ^ array_shift($result);
        $result[] = 0;
        for ($i = 0; $i < $degree; $i++) {
            $result[$i] ^= qr_gf_mul($divisor[$i], $factor);
        }
    }
    return $result;
}

function qr_raw_modules($ver)
{
    $r = (16 * $ver + 128) * $ver + 64;
    if ($ver >= 2) {
        $n = (int)floor($ver / 7) + 2;
        $r -= (25 * $n - 10) * $n - 55;
        if ($ver >= 7) {
            $r -= 36;
        }
    }
    return $r;
}

/** Retourne une matrice de booléens (tableau de lignes) ou null si le texte est trop long. */
function qr_matrix($text)
{
    $eccPer = [10, 16, 26, 18, 24, 16, 18, 22, 22, 26];   // niveau M, versions 1..10
    $blocksN = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    $bytes = array_values(unpack('C*', (string)$text));
    $len = count($bytes);
    $ver = 0;
    for ($v = 1; $v <= 10; $v++) {
        $cap = (int)floor(qr_raw_modules($v) / 8) - $eccPer[$v - 1] * $blocksN[$v - 1];
        $need = (4 + ($v < 10 ? 8 : 16) + 8 * $len + 7) >> 3;
        if ($need <= $cap) {
            $ver = $v;
            break;
        }
    }
    if ($ver === 0) {
        return null;
    }
    $size = $ver * 4 + 17;
    $dataCw = (int)floor(qr_raw_modules($ver) / 8) - $eccPer[$ver - 1] * $blocksN[$ver - 1];

    // Flux de bits
    $bits = [];
    $put = function ($val, $n) use (&$bits) {
        for ($i = $n - 1; $i >= 0; $i--) {
            $bits[] = ($val >> $i) & 1;
        }
    };
    $put(4, 4);
    $put($len, $ver < 10 ? 8 : 16);
    foreach ($bytes as $b) {
        $put($b, 8);
    }
    $capBits = $dataCw * 8;
    for ($i = 0; $i < 4 && count($bits) < $capBits; $i++) {
        $bits[] = 0;
    }
    while (count($bits) % 8 !== 0) {
        $bits[] = 0;
    }
    $data = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $v = 0;
        for ($j = 0; $j < 8; $j++) {
            $v = ($v << 1) | $bits[$i + $j];
        }
        $data[] = $v;
    }
    for ($pad = 0xEC; count($data) < $dataCw; $pad ^= 0xEC ^ 0x11) {
        $data[] = $pad;
    }

    // Blocs + correction d'erreur
    $nb = $blocksN[$ver - 1];
    $eccLen = $eccPer[$ver - 1];
    $rawCw = (int)floor(qr_raw_modules($ver) / 8);
    $numShort = $nb - $rawCw % $nb;
    $shortLen = (int)floor($rawCw / $nb);
    $div = qr_rs_divisor($eccLen);
    $blocks = [];
    $k = 0;
    for ($i = 0; $i < $nb; $i++) {
        $dl = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
        $dat = array_slice($data, $k, $dl);
        $k += $dl;
        $ecc = qr_rs_remainder($dat, $div);
        if ($i < $numShort) {
            $dat[] = 0;
        }
        $blocks[] = array_merge($dat, $ecc);
    }
    $all = [];
    for ($i = 0; $i < count($blocks[0]); $i++) {
        for ($j = 0; $j < $nb; $j++) {
            if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                $all[] = $blocks[$j][$i];
            }
        }
    }

    // Matrice
    $m = array_fill(0, $size, array_fill(0, $size, false));
    $f = array_fill(0, $size, array_fill(0, $size, false));
    $setf = function ($x, $y, $dark) use (&$m, &$f, $size) {
        if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
            $m[$y][$x] = (bool)$dark;
            $f[$y][$x] = true;
        }
    };
    for ($i = 0; $i < $size; $i++) {
        $setf(6, $i, $i % 2 === 0);
        $setf($i, 6, $i % 2 === 0);
    }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as $c) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $d = max(abs($dx), abs($dy));
                $setf($c[0] + $dx, $c[1] + $dy, $d !== 2 && $d !== 4);
            }
        }
    }
    $pos = [];
    if ($ver > 1) {
        $na = (int)floor($ver / 7) + 2;
        $step = (int)(ceil(($ver * 4 + 4) / ($na * 2 - 2)) * 2);
        $pos = [6];
        for ($p = $size - 7; count($pos) < $na; $p -= $step) {
            array_splice($pos, 1, 0, [$p]);
        }
        $last = $na - 1;
        for ($i = 0; $i < $na; $i++) {
            for ($j = 0; $j < $na; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $setf($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
    }
    $drawFormat = function ($mask) use (&$setf, $size) {
        $d = (0 << 3) | $mask;                // niveau M = 0
        $rem = $d;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($d << 10) | $rem) ^ 0x5412;
        $b = function ($i) use ($bits) { return (($bits >> $i) & 1) === 1; };
        for ($i = 0; $i <= 5; $i++) { $setf(8, $i, $b($i)); }
        $setf(8, 7, $b(6)); $setf(8, 8, $b(7)); $setf(7, 8, $b(8));
        for ($i = 9; $i < 15; $i++) { $setf(14 - $i, 8, $b($i)); }
        for ($i = 0; $i < 8; $i++) { $setf($size - 1 - $i, 8, $b($i)); }
        for ($i = 8; $i < 15; $i++) { $setf(8, $size - 15 + $i, $b($i)); }
        $setf(8, $size - 8, true);
    };
    $drawFormat(0);
    if ($ver >= 7) {
        $rem = $ver;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($ver << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            $bb = (int)floor($i / 3);
            $setf($a, $bb, $dark);
            $setf($bb, $a, $dark);
        }
    }

    // Placement des données en zigzag
    $i = 0;
    $total = count($all) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $upward = (($right + 1) & 2) === 0;
                $y = $upward ? $size - 1 - $vert : $vert;
                if (!$f[$y][$x] && $i < $total) {
                    $m[$y][$x] = ((($all[$i >> 3] >> (7 - ($i & 7))) & 1) === 1);
                    $i++;
                }
            }
        }
    }

    // Choix du masque (pénalité minimale)
    $best = null;
    $bestPen = PHP_INT_MAX;
    $bestMask = 0;
    for ($mask = 0; $mask < 8; $mask++) {
        $t = $m;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($f[$y][$x]) {
                    continue;
                }
                switch ($mask) {
                    case 0: $inv = ($x + $y) % 2 === 0; break;
                    case 1: $inv = $y % 2 === 0; break;
                    case 2: $inv = $x % 3 === 0; break;
                    case 3: $inv = ($x + $y) % 3 === 0; break;
                    case 4: $inv = ((int)floor($x / 3) + (int)floor($y / 2)) % 2 === 0; break;
                    case 5: $inv = $x * $y % 2 + $x * $y % 3 === 0; break;
                    case 6: $inv = ($x * $y % 2 + $x * $y % 3) % 2 === 0; break;
                    default: $inv = (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
                }
                if ($inv) {
                    $t[$y][$x] = !$t[$y][$x];
                }
            }
        }
        // le format dépend du masque : on l'applique sur la copie
        $m2 = $m; $f2 = $f;
        $m = $t;
        $drawFormat($mask);
        $t = $m;
        $m = $m2; $f = $f2;
        $pen = qr_penalty($t, $size);
        if ($pen < $bestPen) {
            $bestPen = $pen;
            $best = $t;
            $bestMask = $mask;
        }
    }
    return $best;
}

function qr_penalty($t, $size)
{
    $p = 0;
    for ($pass = 0; $pass < 2; $pass++) {
        for ($a = 0; $a < $size; $a++) {
            $run = 1;
            $line = '';
            for ($b = 0; $b < $size; $b++) {
                $c = $pass === 0 ? $t[$a][$b] : $t[$b][$a];
                $line .= $c ? '1' : '0';
                if ($b > 0) {
                    $prev = $pass === 0 ? $t[$a][$b - 1] : $t[$b - 1][$a];
                    if ($prev === $c) {
                        $run++;
                        if ($run === 5) { $p += 3; } elseif ($run > 5) { $p += 1; }
                    } else {
                        $run = 1;
                    }
                }
            }
            $p += 40 * (substr_count($line, '000010111010') + substr_count($line, '010111010000')
                      + substr_count($line, '00001011101') * 0);
        }
    }
    $dark = 0;
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($t[$y][$x]) {
                $dark++;
                if ($x + 1 < $size && $y + 1 < $size && $t[$y][$x + 1] && $t[$y + 1][$x] && $t[$y + 1][$x + 1]) {
                    $p += 3;
                }
            } elseif ($x + 1 < $size && $y + 1 < $size && !$t[$y][$x + 1] && !$t[$y + 1][$x] && !$t[$y + 1][$x + 1]) {
                $p += 3;
            }
        }
    }
    $k = (int)(abs($dark * 20 - $size * $size * 10) / ($size * $size)) ;
    return $p + $k * 10;
}

/** SVG du code QR (noir sur blanc, zone de silence incluse). Chaîne vide si impossible. */
function qr_svg($text, $px = 220)
{
    $m = qr_matrix($text);
    if ($m === null) {
        return '';
    }
    $n = count($m);
    $q = 4;
    $d = '';
    for ($y = 0; $y < $n; $y++) {
        $x = 0;
        while ($x < $n) {
            if ($m[$y][$x]) {
                $s = $x;
                while ($x < $n && $m[$y][$x]) {
                    $x++;
                }
                $d .= 'M' . ($s + $q) . ' ' . ($y + $q) . 'h' . ($x - $s) . 'v1h-' . ($x - $s) . 'z';
            } else {
                $x++;
            }
        }
    }
    $w = $n + 2 * $q;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $w . '" width="' . (int)$px . '" height="' . (int)$px
        . '" role="img" aria-label="Code QR d\'invitation" shape-rendering="crispEdges"><rect width="' . $w . '" height="' . $w
        . '" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
}
