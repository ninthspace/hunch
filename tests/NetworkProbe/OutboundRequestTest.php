<?php

/*
 * Run only by `composer test:offline`, with outbound network denied by the
 * OS sandbox. It opens a raw socket, bypassing the Http fake, and passes only
 * when the connection fails, so it proves the sandbox really is offline.
 */
it('cannot reach the network', function () {
    $socket = @stream_socket_client('tcp://1.1.1.1:443', $errno, $error, timeout: 3);

    expect($socket)->toBeFalse();
})->group('network-probe');
