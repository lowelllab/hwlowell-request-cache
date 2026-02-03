<?php

namespace HwlowellRequestCache\Facades;

use Illuminate\Support\Facades\Facade;

class RequestCache extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'request-cache';
    }
}