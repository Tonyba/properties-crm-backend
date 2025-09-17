<?php
/*
Plugin Name: Properties CRM
Description: A plugin i made for properties broker
Version: 0.0.2
Author: Anthony
Text Domain: properties-crm
Requires PHP: 8.1
Requires Plugins: wpfront-user-role-editor, advanced-custom-fields
*/


if (!defined('ABSPATH')) {
    die;
}


if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
    require_once dirname(__FILE__) . '/vendor/autoload.php';
}

require_once dirname(__FILE__) . '/autoload.php';


use Inc\Base\Activate;
use Inc\Base\Deactivate;

function activate_properties_crm_plugin()
{
    Activate::activate();
}

function deactivate_properties_crm_plugin()
{
    Deactivate::deactivate();
}


register_activation_hook(__FILE__, 'activate_properties_crm_plugin');
register_deactivation_hook(__FILE__, 'deactivate_properties_crm_plugin');


if (class_exists('Inc\\Init')) {
    Inc\Init::register_services();
}