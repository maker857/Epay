<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$template = file_get_contents($root . '/template/studio/index.php');
$css = file_get_contents($root . '/template/studio/assets/css/studio.css');
$scriptPath = $root . '/template/studio/assets/js/studio.js';
$script = is_file($scriptPath) ? file_get_contents($scriptPath) : false;

if ($template === false || $css === false) {
    fwrite(STDERR, "Studio template assets are missing.\n");
    exit(1);
}

$requiredTemplatePatterns = [
    'assets/js/studio.js',
    'data-reveal',
    'data-reveal-group',
    'data-reveal-item',
    'data-tilt',
    'data-parallax',
    'data-magnetic',
];

foreach ($requiredTemplatePatterns as $pattern) {
    if (strpos($template, $pattern) === false) {
        fwrite(STDERR, "Studio template is missing motion hook: {$pattern}\n");
        exit(1);
    }
}

foreach (["鈫?", "鉁?", "�"] as $brokenGlyph) {
    if (strpos($template, $brokenGlyph) !== false) {
        fwrite(STDERR, "Studio template contains a malformed glyph: {$brokenGlyph}\n");
        exit(1);
    }
}

$requiredCssPatterns = [
    '--motion-intensity: 8',
    '.motion-ready',
    '@media (prefers-reduced-motion: reduce)',
    '--tilt-x',
    '--tilt-y',
    '--pointer-x',
    '--pointer-y',
];

foreach ($requiredCssPatterns as $pattern) {
    if (strpos($css, $pattern) === false) {
        fwrite(STDERR, "Studio CSS is missing motion contract: {$pattern}\n");
        exit(1);
    }
}

if (preg_match('/transition\s*:\s*all\b/i', $css) === 1) {
    fwrite(STDERR, "Studio CSS must not use transition: all.\n");
    exit(1);
}

if ($script === false) {
    fwrite(STDERR, "Studio motion controller is missing.\n");
    exit(1);
}

$requiredScriptPatterns = [
    'IntersectionObserver',
    'requestAnimationFrame',
    "matchMedia('(prefers-reduced-motion: reduce)')",
    "classList.add('motion-ready')",
];

foreach ($requiredScriptPatterns as $pattern) {
    if (strpos($script, $pattern) === false) {
        fwrite(STDERR, "Studio motion controller is missing: {$pattern}\n");
        exit(1);
    }
}

if (preg_match('/window\.addEventListener\s*\(\s*[\'\"]scroll[\'\"]/', $script) === 1) {
    fwrite(STDERR, "Studio motion controller must not register a window scroll listener.\n");
    exit(1);
}

echo "Studio motion intensity 8 design test passed." . PHP_EOL;

