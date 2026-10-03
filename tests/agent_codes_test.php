<?php

declare(strict_types=1);
// How a librarian's consent becomes an agent session (Api\AgentCodes): the code works once, for
// five minutes, only with the PKCE verifier and only for Klaras Panel's callback; switching
// agents off ends their sessions and no others. Runs on SQLite; needs no MySQL.

require __DIR__ . '/../src/Api/AgentCodes.php';

use SLiMS\Plugins\Inventory\Api\AgentCodes;

function check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'ok   ' . $label . PHP_EOL;
}
function rejects(callable $operation, string $label): void
{
    try { $operation(); } catch (RuntimeException $e) { check(true, $label); return; }
    throw new RuntimeException('Tidak ditolak: ' . $label);
}
$verifierFor = static fn (string $verifier): string => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE setting (setting_id INTEGER PRIMARY KEY, setting_name TEXT UNIQUE, setting_value TEXT)');
$db->exec('CREATE TABLE inventory_api_codes (code_hash TEXT PRIMARY KEY, user_id INTEGER, client_name TEXT, code_challenge TEXT, redirect_uri TEXT, created_at TEXT, expires_at TEXT, used_at TEXT NULL)');
$db->exec("CREATE TABLE inventory_api_sessions (id INTEGER PRIMARY KEY, user_id INTEGER, kind TEXT NOT NULL DEFAULT 'app', revoked_at TEXT NULL)");

// Where the code may go.
$panel = 'https://panel.klaras.id';
$callback = $panel . AgentCodes::CALLBACK_PATH;
check(AgentCodes::redirectAllowed($callback, $panel) && AgentCodes::redirectAllowed($callback, $panel . '/'), 'the code goes to Klaras Panel\'s callback');
foreach (['https://panel.klaras.id/oauth/agent/callback?x=1', 'https://panel.klaras.id.evil.example/oauth/agent/callback', 'https://evil.example/oauth/agent/callback', 'https://panel.klaras.id/other'] as $elsewhere) {
    check(!AgentCodes::redirectAllowed($elsewhere, $panel), 'and nowhere else: ' . $elsewhere);
}
check(!AgentCodes::redirectAllowed('http://panel.klaras.id' . AgentCodes::CALLBACK_PATH, 'http://panel.klaras.id') && !AgentCodes::redirectAllowed($callback, ''), 'not to a panel over plain http, nor when SLiMS Connect knows no panel');
check(AgentCodes::validChallenge($verifierFor('x')) && !AgentCodes::validChallenge('plain') && !AgentCodes::validChallenge(str_repeat('a', 43) . '='), 'only an S256 challenge is accepted');
check(AgentCodes::clientName("Claude\n<b>") === 'Claude <b>' && AgentCodes::clientName('') === 'Agent AI' && mb_strlen(AgentCodes::clientName(str_repeat('x', 200))) === 60, 'the app\'s name is one short line');

// A code, used once.
$now = 1_790_000_000;
$verifier = str_repeat('v', 50);
$code = AgentCodes::create($db, 7, 'Claude', $verifierFor($verifier), $callback, $now);
check(preg_match('/\A[a-f0-9]{64}\z/', $code) === 1 && !str_contains((string) $db->query('SELECT code_hash FROM inventory_api_codes')->fetchColumn(), $code), 'the code is random and only its hash is stored');
check(AgentCodes::redeem($db, $code, $verifier, $callback, $now + 10) === ['user_id' => 7, 'client' => 'Claude'], 'the code with its verifier names the librarian and the app');
rejects(static fn () => AgentCodes::redeem($db, $code, $verifier, $callback, $now + 20), 'a code works only once');

$code = AgentCodes::create($db, 7, 'Claude', $verifierFor($verifier), $callback, $now);
rejects(static fn () => AgentCodes::redeem($db, $code, str_repeat('w', 50), $callback, $now + 10), 'a code with another verifier is refused');
rejects(static fn () => AgentCodes::redeem($db, $code, $verifier, $callback, $now + 20), 'and is spent by the wrong try, so it cannot be guessed at');
$code = AgentCodes::create($db, 7, 'Claude', $verifierFor($verifier), $callback, $now);
rejects(static fn () => AgentCodes::redeem($db, $code, $verifier, 'https://evil.example/cb', $now + 10), 'a code for another address is refused');
$code = AgentCodes::create($db, 7, 'Claude', $verifierFor($verifier), $callback, $now);
rejects(static fn () => AgentCodes::redeem($db, $code, $verifier, $callback, $now + 301), 'a code older than five minutes is refused');
rejects(static fn () => AgentCodes::redeem($db, str_repeat('a', 64), $verifier, $callback, $now), 'an unknown code is refused');
rejects(static fn () => AgentCodes::create($db, 7, 'Claude', 'plain', $callback, $now), 'no code without an S256 challenge');
AgentCodes::create($db, 7, 'Claude', $verifierFor($verifier), $callback, $now + 2 * 86400);
check((int) $db->query('SELECT COUNT(*) FROM inventory_api_codes')->fetchColumn() === 1, 'codes older than a day are cleared away');

// The administrator's switch.
check(!AgentCodes::enabled($db), 'agents are off until the administrator allows them');
AgentCodes::setEnabled($db, true, '2026-10-03 10:00:00');
check(AgentCodes::enabled($db), 'the administrator allows them');
$db->exec("INSERT INTO inventory_api_sessions (user_id, kind) VALUES (7, 'agent'), (7, 'app'), (8, 'agent')");
AgentCodes::setEnabled($db, false, '2026-10-03 11:00:00');
check(!AgentCodes::enabled($db)
    && $db->query("SELECT kind || ':' || COALESCE(revoked_at, '-') FROM inventory_api_sessions ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) === ['agent:2026-10-03 11:00:00', 'app:-', 'agent:2026-10-03 11:00:00'],
    'switching agents off ends every agent session and leaves phones signed in');
echo "ok   done\n";
