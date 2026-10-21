<?php

defined('BASEPATH') || exit('No direct script access allowed');

class MY_Exceptions extends CI_Exceptions
{
    public function show_error($heading, $message, $template = 'error_general', $status_code = 500)
    {
        if (ENVIRONMENT === 'testing') {
            $text = is_array($message) ? implode(' | ', $message) : (string) $message;
            throw new RuntimeException($text, $status_code);
        }

        // Security (CWE-209): unauthenticated users see generic error messages,
        // not system details (paths, backtraces, database errors). Authenticated
        // admins can see the real error in logs.
        if (ENVIRONMENT === 'production' && ! $this->_is_authenticated()) {
            $heading = 'Error';
            $message = 'An error occurred. Please contact support if the problem persists.';
        }

        return parent::show_error($heading, $message, $template, $status_code);
    }

    /**
     * Check if current request is authenticated (has a valid user session).
     */
    private function _is_authenticated(): bool
    {
        $ci =& get_instance();

        return isset($ci->session->user_id);
    }
}
