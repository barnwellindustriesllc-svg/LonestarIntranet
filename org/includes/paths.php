<?php
function lonestar_base_path() {
    $appRoot = realpath(__DIR__ . '/..');
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : '';
    if ($appRoot && $docRoot && strpos($appRoot, $docRoot) === 0) {
        $rel = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        return rtrim($rel, '/');
    }
    return '';
}

function lonestar_sibling_path($sibling) {
    $base = lonestar_base_path();
    if ($base === '') {
        return '/' . ltrim($sibling, '/');
    }
    $parent = rtrim(str_replace('\\', '/', dirname($base)), '/');
    return ($parent ? $parent : '') . '/' . ltrim($sibling, '/');
}
