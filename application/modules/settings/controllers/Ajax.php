<?php

if ( ! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/*
 * InvoicePlane
 *
 * @author		InvoicePlane Developers & Contributors
 * @copyright	Copyright (c) 2012 - 2018 InvoicePlane.com
 * @license		https://invoiceplane.com/license.txt
 * @link		https://invoiceplane.com
 */

#[AllowDynamicProperties]
class Ajax extends Admin_Controller
{
    public $ajax_controller = true;

    public function get_cron_key()
    {
<<<<<<< HEAD
<<<<<<< HEAD
        $this->load->helper('ip_security');
        // 8 bytes -> 16 hexadecimal characters.
        echo generate_secure_token(8);
=======
        $this->load->helper('security');
        echo generate_secure_token(16);
>>>>>>> e37c4306e (Use CSPRNG for invoice/quote URL keys and CRON key)
=======
        $this->load->helper('ip_security');
        // 8 bytes -> 16 hexadecimal characters.
        echo generate_secure_token(8);
>>>>>>> 688a09aab (Reuse canonical CSPRNG token generator for URL and CRON keys)
    }
}
