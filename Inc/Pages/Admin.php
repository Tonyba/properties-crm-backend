<?php

namespace Inc\Pages;

class Admin
{

    function __construct()
    {

    }

    public function register()
    {
        add_action('admin_menu', array($this, 'add_admin_pages'));
        add_action('rest_api_init', array($this, 'add_cors_headers'));
    }

    function add_cors_headers()
    {
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
    }

    public function add_admin_pages()
    {

        add_submenu_page(
            'edit.php?post_type=property', // Parent slug (your CPT)
            'Properties CRM',                // Page title
            'Properties CRM',                // Menu title
            'manage_options',                    // Capability
            'properties-crm',                 // Menu slug
            [$this, 'admin_index']            // Callback function
        );

    }

    public function admin_index()
    {
        require_once PLUGIN_PATH . 'templates/admin.php';
    }

}