<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    /**
     * How the current user is written into the action log: «Айнур Дурдыева» -> «Айнур Д.».
     */
    protected function actor(): string
    {
        $name = Auth::user()?->name ?? 'Система';
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return isset($parts[1]) ? $parts[0].' '.mb_substr($parts[1], 0, 1).'.' : ($parts[0] ?? 'Система');
    }
}
