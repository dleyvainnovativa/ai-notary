<?php
$R = getenv('NOTARIA_ROOT') ?: dirname(__DIR__, 2);
require "$R/app/Services/CatalogService.php";      // real class (catalogPath touches no framework code)
require "$R/app/Services/Ai/RetryPolicy.php";
use App\Services\Ai\RetryPolicy;
$fail = 0; function check($l,$c){ global $fail; echo ($c?'  PASS ':'  FAIL ')."$l\n"; if(!$c) $fail++; }

echo "CatalogService::catalogPath (real class)\n";
$c = new App\Services\CatalogService();
$p = $c->catalogPath('catalogo_inmuebles', "$R/modules/declaranot_adquisicion/v1");
check('derived module resolves to DeclaraNOT\'s catalog via "extends"', realpath($p) === realpath("$R/modules/declaranot/v1/catalogs/catalogo_inmuebles.json"));
check('a normal module still uses its own catalogs/', $c->catalogPath('catalogo_persona', "$R/modules/avisos_uif/v1") === "$R/modules/avisos_uif/v1/catalogs/catalogo_persona.json");
check('missing everywhere → own path (caller logs "not found")', str_ends_with($c->catalogPath('nope', "$R/modules/declaranot_adquisicion/v1"), 'declaranot_adquisicion/v1/catalogs/nope.json'));

echo "RetryPolicy (OpenAI calls)\n";
$sleeps = [];
$policy = new RetryPolicy(2, [3], function ($s) use (&$sleeps) { $sleeps[] = $s; });
$n = 0; $r = $policy->run(function () use (&$n) { if (++$n === 1) throw new RuntimeException('cURL error 28: Operation timed out after 90001 ms'); return 'ok'; });
check('timeout → retried once after 3 s, then succeeds', $r === 'ok' && $n === 2 && $sleeps === [3]);
foreach (['HTTP 429 Too Many Requests', 'Rate limit reached for gpt-4o-mini', '503 Service Unavailable', 'The server is overloaded'] as $msg) {
  check("transient: \"$msg\"", RetryPolicy::isTransient(new RuntimeException($msg)));
}
check('transient when wrapped (previous exception)', RetryPolicy::isTransient(new RuntimeException('OpenAI request failed', 0, new RuntimeException('Connection reset by peer'))));
foreach (['Incorrect API key provided', 'This model\'s maximum context length is 128000 tokens', 'Invalid JSON from AI: Syntax error'] as $msg) {
  check("NOT retried: \"$msg\"", !RetryPolicy::isTransient(new RuntimeException($msg)));
}
$n = 0; $sleeps = [];
try { $policy->run(function () use (&$n) { $n++; throw new RuntimeException('Incorrect API key provided'); }); } catch (RuntimeException $e) {}
check('non-transient error → 1 attempt, no wait', $n === 1 && $sleeps === []);
$n = 0;
try { $policy->run(function () use (&$n) { $n++; throw new RuntimeException('429 rate limit'); }); } catch (RuntimeException $e) {}
check('still failing → stops after 2 attempts (job stays within its timeout)', $n === 2);
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
