<?php
declare(strict_types=1);
// One-shot CLI operation after AdSense site approval, manual-placement and CMP checks.
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
ini_set('display_errors', '0');
$root = __DIR__;
$out = $root.'/ads-activation.json';
$lock = fopen($root.'/ads-activation.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB) || is_file($out)) exit;
$report = ['time'=>gmdate('c'), 'success'=>false, 'config_changed'=>false];
function requireCheck(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); }
try {
    requireCheck(PHP_VERSION_ID >= 80300, 'php_version');
    $path = $root.'/private/config.json';
    $original = file_get_contents($path);
    $config = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
    requireCheck(($config['origin']??'') === 'https://brand-new.tokyo', 'origin');
    requireCheck(($config['public_indexing']??false) === true && ($config['legacy_public_enabled']??false) === true, 'production_active');
    requireCheck(($config['local_test']??false) === false, 'not_test');
    requireCheck(($config['adsense']['enabled']??null) === false && ($config['adsense']['account_settings_verified']??null) === false, 'previous_ad_state');
    requireCheck(hash_file('sha256', $root.'/xserver/src/Ads.php') === 'ae701d0996531a48a26e4365a4062e16e9e08736149172829ea2f4b3caf6b6d2', 'ads_code');
    require $root.'/xserver/src/Ads.php';
    $next = $config;
    $next['adsense']['enabled'] = true;
    $next['adsense']['account_settings_verified'] = true;
    requireCheck(Chronicle\Ads::enabled($next, true) && !Chronicle\Ads::enabled($next, false), 'public_only');
    $backup = $root.'/private/config-before-ad-activation.json';
    requireCheck(!file_exists($backup), 'backup_absent');
    requireCheck(file_put_contents($backup, $original) === strlen($original) && chmod($backup, 0600), 'backup');
    requireCheck(!file_exists($path.'.ads-next'), 'stage_absent');
    $json = json_encode($next, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    requireCheck(file_put_contents($path.'.ads-next', $json) === strlen($json) && chmod($path.'.ads-next', 0600), 'stage');
    requireCheck(hash_file('sha256', $path) === hash('sha256', $original), 'config_unchanged');
    requireCheck(rename($path.'.ads-next', $path), 'replace');
    $report['config_changed'] = true;
    $actual = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    requireCheck($actual === $next && Chronicle\Ads::enabled($actual, true), 'readback');
    $report['public_ads_enabled'] = true;
    $report['admin_ads_disabled'] = !Chronicle\Ads::enabled($actual, false);
    $report['success'] = true;
} catch (Throwable $e) {
    $report['error_class'] = get_class($e);
    if ($e instanceof RuntimeException) $report['failed_check'] = $e->getMessage();
}
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
chmod($out, 0600);
