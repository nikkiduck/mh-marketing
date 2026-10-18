<?php
/**
 * inc/brand_embed.php — the public Brand Guidelines page (brand-guidelines/
 * index.html, plain HTML for vendors, no sign-in) shown INSIDE the portal
 * with the portal's header and menu (Nikki, 2026-10-18). The page stays the
 * one source: its <main> is lifted at request time, its asset paths made
 * absolute, and its stylesheet scoped under .bg-embed so its :root variables
 * (--ink, --muted, --line, the same names the portal uses) and its html/body
 * rules cannot touch the portal around it.
 */

/** The guidelines' sections and the anchors of its own nav: ['html' => ..., 'jump' => [id => label]]. */
function portal_brand_guidelines(): array {
    $file = __DIR__ . '/../brand-guidelines/index.html';
    $src  = is_file($file) ? (string)file_get_contents($file) : '';
    $html = '';
    if (preg_match('~<main class="bg-content">(.*)</main>~s', $src, $m)) $html = $m[1];
    // Relative assets resolve from /brand-guidelines/ there; here they must not.
    $html = preg_replace('~(src|href)="assets/~', '$1="/brand-guidelines/assets/', $html);
    $jump = [];
    if (preg_match('~<nav class="bg-nav">(.*?)</nav>~s', $src, $n)) {
        $nav = preg_replace('~<!--.*?-->~s', '', $n[1]);   // a commented-out link is not offered
        if (preg_match_all('~<a href="#([a-z0-9-]+)">([^<]+)</a>~', $nav, $all, PREG_SET_ORDER)) {
            foreach ($all as $a) $jump[$a[1]] = html_entity_decode($a[2], ENT_QUOTES, 'UTF-8');
        }
    }
    return ['html' => $html, 'jump' => $jump];
}

/**
 * The guidelines' stylesheet with every rule scoped under $prefix: ":root"
 * becomes the prefix itself (its variables apply inside only), "html", "body"
 * and "*" rules are dropped (the portal owns the page), everything else gets
 * the prefix in front; @media blocks are walked the same way.
 */
function portal_scope_css(string $css, string $prefix = '.bg-embed'): string {
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    return portal_scope_css_block($css, $prefix);
}
function portal_scope_css_block(string $css, string $prefix): string {
    $out = ''; $i = 0; $n = strlen($css);
    while ($i < $n) {
        $open = strpos($css, '{', $i);
        if ($open === false) break;
        $sel = trim(substr($css, $i, $open - $i));
        // find the matching close brace (nested for @media)
        $depth = 1; $j = $open + 1;
        while ($j < $n && $depth > 0) { if ($css[$j] === '{') $depth++; elseif ($css[$j] === '}') $depth--; $j++; }
        $body = substr($css, $open + 1, $j - $open - 2);
        $i = $j;
        if ($sel === '') continue;
        if ($sel[0] === '@') {
            if (str_starts_with($sel, '@media') || str_starts_with($sel, '@supports')) $out .= $sel . '{' . portal_scope_css_block($body, $prefix) . "}\n";
            else $out .= $sel . '{' . $body . "}\n";   // @font-face, @keyframes: as is
            continue;
        }
        $parts = [];
        foreach (array_map('trim', explode(',', $sel)) as $s) {
            if ($s === '') continue;
            if ($s === ':root') { $parts[] = $prefix; continue; }
            if (preg_match('~^(html|body|\*|\*::before|\*::after)$~', $s)) continue;
            $parts[] = $prefix . ' ' . $s;
        }
        if ($parts) $out .= implode(', ', $parts) . '{' . $body . "}\n";
    }
    return $out;
}
