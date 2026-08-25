<?php
/**
 * Flags PHP 8.0/8.1-only syntax using the tokenizer rather than regex.
 * Run under PHP 8.x; reports constructs that would be a parse error on 7.4.
 */
$paths = array_slice($argv, 1) ?: ['src', 'config'];

$files = [];
foreach ($paths as $path) {
    if (is_file($path)) { $files[] = $path; continue; }
    if (! is_dir($path)) { fwrite(STDERR, "no such path: $path\n"); exit(2); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') { $files[] = $f->getPathname(); }
    }
}
sort($files);

$findings = [];
foreach ($files as $file) {
    $t = token_get_all(file_get_contents($file), TOKEN_PARSE);
    $n = count($t);
    $depth = 0; $inParams = false; $paramDepth = 0;

    for ($i = 0; $i < $n; $i++) {
        $tok = $t[$i];
        $line = is_array($tok) ? $tok[1] : null;
        $id   = is_array($tok) ? $tok[0] : null;
        $txt  = is_array($tok) ? $tok[1] : $tok;

        $flag = function (string $what) use (&$findings, $file, $t, $i) {
            for ($j = $i; $j >= 0; $j--) { if (is_array($t[$j])) { $ln = $t[$j][2]; break; } }
            $findings[] = sprintf('%s:%d  %s', $file, $ln ?? 0, $what);
        };

        if ($id === T_NULLSAFE_OBJECT_OPERATOR) { $flag('nullsafe ?-> (8.0)'); }
        if ($id === T_ATTRIBUTE)                { $flag('attribute #[ ] (8.0)'); }
        if ($id === T_MATCH)                    { $flag('match expression (8.0)'); }
        if ($id === T_ENUM)                     { $flag('enum (8.1)'); }
        if ($id === T_READONLY)                 { $flag('readonly (8.1)'); }

        // $var::class  (8.0)
        if ($id === T_VARIABLE && isset($t[$i+1], $t[$i+2])
            && is_array($t[$i+1]) && $t[$i+1][0] === T_DOUBLE_COLON
            && is_array($t[$i+2])
            && ($t[$i+2][0] === T_CLASS || ($t[$i+2][0] === T_STRING && strtolower($t[$i+2][1]) === 'class'))) { $flag('$object::class (8.0)'); }

        // first-class callable  foo(...)
        if ($id === T_ELLIPSIS) {
            for ($j = $i+1; $j < $n; $j++) { if (is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) continue; break; }
            if (($t[$j] ?? null) === ')') { $flag('first-class callable f(...) (8.1)'); }
        }

        // named argument:  ( or ,  IDENT :  (but not ::, not ternary ?:)
        if ($id === T_STRING && isset($t[$i+1])) {
            $next = $t[$i+1];
            $isColon = ($next === ':') || (is_array($next) && $next[0] === T_WHITESPACE && ($t[$i+2] ?? null) === ':');
            $prev = null;
            for ($j = $i-1; $j >= 0; $j--) { if (is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) continue; $prev = $t[$j]; break; }
            if ($isColon && ($prev === '(' || $prev === ',')) { $flag('named argument '.$txt.': (8.0)'); }
        }

        // non-capturing catch:  catch (Foo)  with no variable
        if ($id === T_CATCH) {
            for ($j = $i+1; $j < $n && ($t[$j] ?? null) !== ')'; $j++) {}
            $hasVar = false;
            for ($k = $i+1; $k < $j; $k++) { if (is_array($t[$k]) && $t[$k][0] === T_VARIABLE) { $hasVar = true; break; } }
            if (! $hasVar) { $flag('non-capturing catch (8.0)'); }
        }

        // promoted constructor property / trailing comma in a parameter list
        if ($id === T_FUNCTION) {
            $open = null;
            for ($j = $i+1; $j < $n; $j++) { if (($t[$j] ?? null) === '(') { $open = $j; break; } if (($t[$j] ?? null) === '{') break; }
            if ($open !== null) {
                $d = 0;
                for ($j = $open; $j < $n; $j++) {
                    $c = $t[$j];
                    if ($c === '(') $d++;
                    elseif ($c === ')') { $d--; if ($d === 0) { $close = $j; break; } }
                    elseif ($d === 1 && is_array($c) && in_array($c[0], [T_PUBLIC, T_PRIVATE, T_PROTECTED], true)) { $flag('promoted constructor property (8.0)'); }
                }
                if (isset($close)) {
                    for ($j = $close-1; $j > $open; $j--) { if (is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) continue; break; }
                    if (($t[$j] ?? null) === ',') { $flag('trailing comma in parameter list (8.0)'); }
                    unset($close);
                }
            }
        }
    }
}

if ($findings) {
    echo implode("\n", $findings)."\n\n";
    printf("FAIL: %d PHP 8-only construct(s) in %d file(s); the package floor is PHP 7.4.\n", count($findings), count($files));
    exit(1);
}

printf("OK: %d file(s) scanned, no PHP 8-only syntax.\n", count($files));
