<?php

/**
 * Global-namespace seams for rendering view files inside isolated unit tests
 * (site_url() and _core_asset() only exist in the full CodeIgniter runtime).
 */
if ( ! function_exists('site_url')) {
    function site_url($uri = '', $protocol = null): string
    {
        return 'http://localhost/index.php/' . (is_array($uri) ? implode('/', $uri) : $uri);
    }
}

if ( ! function_exists('_core_asset')) {
    function _core_asset($asset): void
    {
        echo 'assets/core/' . $asset;
    }
}
