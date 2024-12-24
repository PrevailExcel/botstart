<?php

if (!function_exists('base_path')) {
    function base_path($path = '')
    {
        return getcwd() . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}

if (!function_exists('resource_path')) {
    function resource_path($path = '')
    {
        return base_path('resources' . ($path ? DIRECTORY_SEPARATOR . $path : $path));
    }
}

if (!function_exists('app_path')) {
    function app_path($path = '')
    {
        return base_path('app' . ($path ? DIRECTORY_SEPARATOR . $path : $path));
    }
}