<?php

// Petits utilitaires communs aux modules (dates, entiers, listes).

function valid_date($s)
{
    $d = DateTime::createFromFormat('Y-m-d', (string)$s);
    return $d && $d->format('Y-m-d') === $s;
}

function valid_time($s)
{
    $d = DateTime::createFromFormat('H:i', (string)$s);
    return $d && $d->format('H:i') === $s;
}

/** Date locale de l'utilisateur au format Y-m-d. */
function today_for(array $user)
{
    $tz = new DateTimeZone(opt($user, 'timezone', 'Africa/Abidjan') ?: 'Africa/Abidjan');
    $d = new DateTime('now', $tz);
    return $d->format('Y-m-d');
}

function fr_date($ymd, $withYear = false)
{
    $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $d = DateTime::createFromFormat('Y-m-d', substr($ymd, 0, 10));
    if (!$d) {
        return '';
    }
    return $days[(int)$d->format('w')] . ' ' . $d->format('j') . ' ' . $months[(int)$d->format('n') - 1]
        . ($withYear ? ' ' . $d->format('Y') : '');
}

/** Montant en francs CFA (entier) avec espaces : 125000 -> « 125 000 F ». */
function fcfa($amount)
{
    return number_format((int)$amount, 0, ',', "\xC2\xA0") . ' F';
}

function post_str($name, $max)
{
    return mb_substr(trim((string)opt($_POST, $name, '')), 0, $max);
}

function post_id($name = 'id')
{
    return (int)opt($_POST, $name, 0);
}
