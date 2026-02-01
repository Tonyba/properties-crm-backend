<?php


namespace Inc\Api;

use Inc\Service\HelpersService;


if (!defined('ABSPATH')) {
    die;
}

class AuthApi
{
    private $helperService;

    private $expected_body = [
        array(
            'key' => 'email',
            'required' => true
        ),
        array(
            'key' => 'password',
            'required' => true
        )
    ];

    public function __construct()
    {
        $this->helperService = new HelpersService();
    }

    public function register()
    {
        add_action('wp_ajax_user_login', array($this, 'login'));
        add_action('wp_ajax_nopriv_user_login', array($this, 'login'));
    }

    public function login()
    {
        $fields = [
            'email' => isset($_POST['email']) ? sanitize_text_field($_POST['email']) : '',
            'password' => isset($_POST['password']) ? sanitize_text_field($_POST['password']) : ''
        ];
        $is_valid = $this->helperService->check_body($fields, $this->expected_body);

        if (!$is_valid) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'email and password are mandatory'
            ), 400);
        }

        $creds = array(
            'user_login' => $fields['email'],
            'user_password' => $fields['password'],
            'remember' => true
        );

        $user = wp_signon($creds, false);

        if (is_wp_error($user)) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'email or password is incorrect'
            ), 403);
        }

        $resp = [
            'ok' => true,
            'data' => $user
        ];

        return wp_send_json($resp);
    }
}