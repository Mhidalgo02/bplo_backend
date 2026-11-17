<?php
// Run from backend/: php scripts/setup_stripe_payments.php
// Creates only the Stripe payment-attempt table and its setup marker in the configured BPLO database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
foreach (is_file($root . '/.env') ? file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    if (getenv($key) === false) putenv($key . '=' . trim($value, " \t\n\r\0\x0B\"'"));
}
if (!getenv('DB_HOST') || !getenv('DB_NAME')) {
    fwrite(STDERR, "Configure DB_HOST and DB_NAME in backend/.env or the Render environment first.\n"); exit(1);
}
define('PREVENT_DIRECT_ACCESS', true);
define('ROOT_DIR', $root . DIRECTORY_SEPARATOR);
define('APP_DIR', ROOT_DIR . 'app' . DIRECTORY_SEPARATOR);
define('SYSTEM_DIR', ROOT_DIR . 'scheme' . DIRECTORY_SEPARATOR);
require_once SYSTEM_DIR . 'kernel/Routine.php';
require_once SYSTEM_DIR . 'database/Database.php';
require_once APP_DIR . 'libraries/BploManualPayments.php';
require_once APP_DIR . 'libraries/BploStripePayments.php';

try {
    $db = new Database();
    $checkOnly = in_array('--check', $argv, true);
    if (!$checkOnly) {
        // DDL is not transactional in MySQL. IF NOT EXISTS permits a safe retry after partial setup.
        $sql = preg_replace('/^--.*$/m', '', file_get_contents($root . '/database/stripe_payments.sql'));
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') $db->raw($statement);
        }
    }
    if (!BploStripePayments::installed($db)) throw new RuntimeException('Missing schema marker.');
    $db->table('bplo_stripe_payments')->select('id,application_id,attempt_key,session_id,state')->limit(1)->get_all();
    echo $checkOnly ? "Verified: the Stripe payment table and the setup marker are available.\n" : "Stripe payment table is ready. Existing applications and account records were preserved.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Stripe payment setup failed. Check the configured BPLO database and CREATE TABLE permissions.\n");
    exit(1);
}
