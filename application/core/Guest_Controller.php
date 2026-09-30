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
class Guest_Controller extends User_Controller
{
    use XSS_Protection_Trait;

    /** @var array */
    public $user_clients = [];

    /**
     * Guest_Controller constructor.
     */
    public function __construct()
    {
        parent::__construct('user_type', 2);
        $this->setSecurityHeaders();

        $this->load->model('user_clients/mdl_user_clients');

        $user_clients = $this->mdl_user_clients->assigned_to($this->session->userdata('user_id'))->get()->result();

        if ( ! $user_clients) {
            show_error(trans('guest_account_denied'), 403);
            exit;
        }

        foreach ($user_clients as $user_client) {
            $this->user_clients[$user_client->client_id] = $user_client->client_id;
        }

        // Automatically filter all POST input to prevent XSS attacks
        // This applies to all guest controllers
        if ($this->input->method() === 'post' && ! empty($_POST)) {
            $this->filter_input();
        }
    }

    protected function setSecurityHeaders(): void
    {
        // One source for these headers (bootstrap/security_headers.php), so a configured X_FRAME_OPTIONS=DENY
        // cannot be overridden here by a second, hard-coded copy of the policy.
        require_once FCPATH . '../bootstrap/security_headers.php';

        foreach (ip_security_headers((string) env('X_FRAME_OPTIONS', 'SAMEORIGIN'), env_bool('ENABLE_X_CONTENT_TYPE_OPTIONS', true)) as $header) {
            $this->output->set_header($header);
        }
    }
}
