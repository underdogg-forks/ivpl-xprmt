<?php

/**
 * Security headers sent with every web response (see bootstrap/kernel.php).
 *
 * Pure so it can be unit-tested: the CLI request harness never sends headers, so the kernel's
 * `PHP_SAPI !== 'cli'` guard would otherwise hide this logic from every test.
 *
 * @return list<string> header lines, ready for header()
 */
if ( ! function_exists('ip_security_headers')) {
    function ip_security_headers(string $frame_options = 'SAMEORIGIN', bool $nosniff = true): array
    {
        $frame_ancestors = ['SAMEORIGIN' => "'self'", 'DENY' => "'none'"];
        $frame_options   = mb_strtoupper(trim($frame_options));

        if ( ! isset($frame_ancestors[$frame_options])) {
            $frame_options = 'SAMEORIGIN';
        }

        $headers = [
            'X-Frame-Options: ' . $frame_options,
            "Content-Security-Policy: frame-ancestors {$frame_ancestors[$frame_options]}; object-src 'none'; base-uri 'self'",
            'Referrer-Policy: strict-origin-when-cross-origin',
        ];

        if ($nosniff) {
            $headers[] = 'X-Content-Type-Options: nosniff';
        }

        return $headers;
    }
}
