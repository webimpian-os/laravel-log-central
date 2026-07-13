<?php

namespace Webimpian\LogCentral\Listeners;

use Illuminate\Log\Events\MessageLogged;
use Throwable;
use Webimpian\LogCentral\Jobs\ShipErrorToCentral;
use Webimpian\LogCentral\Support\ErrorPayload;

class ShipExceptionListener
{
    public function handle(MessageLogged $event): void
    {
        $exception = $event->context['exception'] ?? null;

        if (! $exception instanceof Throwable || $this->fromThisPackage($exception)) {
            return;
        }

        rescue(function () use ($exception) {
            dispatch(new ShipErrorToCentral(ErrorPayload::fromThrowable($exception)))
                ->onQueue(config('log-central.queue'));
        }, report: false);
    }

    /**
     * Failures inside the shipper itself must never be shipped — that would loop.
     */
    private function fromThisPackage(Throwable $exception): bool
    {
        if (str_starts_with($exception->getFile(), dirname(__DIR__))) {
            return true;
        }

        foreach (array_slice($exception->getTrace(), 0, 20) as $frame) {
            if (str_starts_with($frame['class'] ?? '', 'Webimpian\\LogCentral\\')) {
                return true;
            }
        }

        return false;
    }
}
