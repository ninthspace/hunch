<?php

/*
 * Run first by `composer compat`. The compatibility run is manual and local:
 * the key comes from the environment of the person running it, and from
 * nowhere else. No key is ever read from, or written to, this repository.
 */
$key = getenv('ANTHROPIC_API_KEY');

if (! is_string($key) || trim($key) === '') {
    fwrite(STDERR, <<<'MESSAGE'
    composer compat needs ANTHROPIC_API_KEY in your environment.

    It is a manual, local run that calls Anthropic and costs money. Export the
    key in your shell, or source it from a file outside this repository:

        set -a; . ~/.config/hunch/compat.env; set +a; composer compat

    No key is read from, or written to, this repository.

    MESSAGE);

    exit(1);
}

exit(0);
