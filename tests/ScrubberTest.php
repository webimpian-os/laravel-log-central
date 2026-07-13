<?php

use Webimpian\LogCentral\Support\Scrubber;

it('scrubs sensitive keys recursively and case-insensitively', function () {
    $scrubbed = Scrubber::scrub([
        'order_id' => 42,
        'Password' => 'hunter2',
        'card_number' => '4111111111111111',
        'nested' => [
            'api_key' => 'abc',
            'safe' => 'ok',
        ],
    ]);

    expect($scrubbed)->toBe([
        'order_id' => 42,
        'Password' => '[scrubbed]',
        'card_number' => '[scrubbed]',
        'nested' => [
            'api_key' => '[scrubbed]',
            'safe' => 'ok',
        ],
    ]);
});
