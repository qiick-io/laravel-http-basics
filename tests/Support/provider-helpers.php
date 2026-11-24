<?php

declare(strict_types=1);

use Qiick\HttpBasics\Laravel\HttpBasicsServiceProvider;

/*
 * Helpers for the tests that check what HttpBasicsServiceProvider builds from config.
 */

/**
 * Reads a private property: the bindings expose no getters for what they were built with.
 */
function wiredProperty(object $object, string $property): mixed
{
    return (fn (): mixed => $this->{$property})->call($object);
}

function bootProvider(): void
{
    /** @var HttpBasicsServiceProvider $provider */
    $provider = app()->getProvider(HttpBasicsServiceProvider::class);
    $provider->boot();
}
