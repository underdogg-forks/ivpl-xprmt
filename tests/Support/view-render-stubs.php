<?php

/**
 * Global-namespace seam for rendering view files inside isolated unit tests: site_url() only
 * exists in the full CodeIgniter runtime. Guarded so the real function wins when it is loaded.
 */
if ( ! function_exists('site_url')) {
    function site_url($uri = '', $protocol = null): string
    {
        return 'http://localhost/index.php/' . (is_array($uri) ? implode('/', $uri) : $uri);
    }
}
