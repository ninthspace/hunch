<?php

use Symfony\Component\Finder\Finder;

/**
 * The extensions Hunch may rely on: PHP's always-on core, plus what
 * laravel/framework itself requires. Anything else would be a requirement
 * Hunch adds, which ENVX3 rules out.
 */
const ALLOWED_EXTENSIONS = [
    'core', 'date', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard',
    'ctype', 'filter', 'hash', 'mbstring', 'openssl', 'session', 'tokenizer',
];

/**
 * The functions called and classes imported by one PHP file.
 *
 * @return array{functions: list<string>, classes: list<string>}
 */
function symbolsUsedIn(string $code): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($code),
        fn (PhpToken $token) => ! $token->isIgnorable(),
    ));

    $functions = [];
    $classes = [];

    foreach ($tokens as $i => $token) {
        $previous = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;

        if ($token->is(T_USE) && ($previous === null || $previous->is(';') || $previous->is('{') || $previous->is('}'))) {
            $name = $tokens[$i + 1] ?? null;

            if ($name?->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])) {
                $classes[] = ltrim($name->text, '\\');
            }
        }

        if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $next?->is('(')
            && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
            $functions[] = ltrim($token->text, '\\');
        }
    }

    return ['functions' => $functions, 'classes' => $classes];
}

it('uses no PHP extension beyond those Laravel requires', function () {
    $violations = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__).'/src')->name('*.php') as $file) {
        $symbols = symbolsUsedIn($file->getContents());

        foreach ($symbols['functions'] as $function) {
            $extension = function_exists($function) ? new ReflectionFunction($function)->getExtensionName() : false;

            if ($extension !== false && ! in_array(strtolower($extension), ALLOWED_EXTENSIONS, true)) {
                $violations[] = "{$file->getRelativePathname()}: {$function}() is from ext-{$extension}";
            }
        }

        foreach ($symbols['classes'] as $class) {
            $exists = class_exists($class) || interface_exists($class) || enum_exists($class);
            $extension = $exists ? new ReflectionClass($class)->getExtensionName() : false;

            if ($extension !== false && ! in_array(strtolower($extension), ALLOWED_EXTENSIONS, true)) {
                $violations[] = "{$file->getRelativePathname()}: {$class} is from ext-{$extension}";
            }
        }
    }

    expect($violations)->toBe([]);
});
