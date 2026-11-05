<?php

declare(strict_types=1);

// This modifies only the ephemeral CI checkout, never the published manifest.
$version = getenv('SYMFONY_VERSION');
if (!in_array($version, ['7.4', '8.1'], true)) {
    throw new InvalidArgumentException('SYMFONY_VERSION must be 7.4 or 8.1.');
}
$root = dirname(__DIR__, 2);
$path = $root.'/composer.json';
$composer = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$lock = json_decode(file_get_contents($root.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);

foreach (['require', 'require-dev'] as $section) {
    foreach ($composer[$section] ?? [] as $name => $constraint) {
        if (str_starts_with($name, 'symfony/') && preg_match('/\^[78]\./', $constraint)) {
            $composer[$section][$name] = $version.'.*';
        }
    }
}
// Constrain transitive framework components too; contracts/polyfills keep independent versions.
foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
    if (str_starts_with($package['name'], 'symfony/') && preg_match('/^v?[78]\./', $package['version'])) {
        if (!isset($composer['require'][$package['name']])) {
            $composer['require-dev'][$package['name']] = $version.'.*';
        }
    }
}
$composer['config']['platform']['php'] = PHP_VERSION;
file_put_contents($path, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
