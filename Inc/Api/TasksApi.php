<?php

namespace Inc\Api;


if (!defined('ABSPATH')) {
    die;
}

use interfaces\ApiInterface;
use Inc\Service\HelpersService;

class TasksApi implements ApiInterface
{

    private $helperService;
    private $post_type = 'task';

    public function __construct()
    {
        $this->helperService = new HelpersService();
    }

    private $expected_body = array(
        array(
            'key' => 'title',
            'required' => true
        ),
        array(
            'key' => 'from',
            'required' => true,
        ),
        array(
            'key' => 'to',
            'required' => true
        ),
        array(
            'key' => 'assigned_to',
            'required' => true
        ),
        array(
            'key' => 'event_status',
            'required' => true
        )
    );

    public function register()
    {
        add_action('wp_ajax_task_taxonomies', array($this, 'get_taxonomies'));
        add_action('wp_ajax_nopriv_task_taxonomies', array($this, 'get_taxonomies'));

        add_action('wp_ajax_create_task', array($this, 'create_task'));
        add_action('wp_ajax_nopriv_create_task', array($this, 'create_task'));
    }

    public function get_taxonomies()
    {
        return wp_send_json($this->helperService->get_post_types_taxonomies_terms($this->post_type, ['event_status', 'priority']));
    }


    public function create_task()
    {
        $fields = $this->helperService->sanitize_fields($_POST['fields']);
        $is_valid = $this->helperService->check_body($fields, $this->expected_body);
        $default_user = get_current_user_id();
        $default_user = $default_user == 0 ? 1 : $default_user;

        $assigned_to = isset($fields['assigned_to']) ? $fields['assigned_to'] : $default_user;

        if (!$is_valid) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'invalid field or fields'
            ), 400);
        }

        $args = array(
            'post_title' => $fields['title'],
            'post_status' => 'publish',
            'post_type' => $this->post_type,
            'post_author' => $assigned_to
        );

        $id = wp_insert_post($args);

        if (is_wp_error($id)) {

            return wp_send_json(array(
                'ok' => false,
                'msg' => "error found - $this->post_type not created"
            ), 400);

        } else {

            if (isset($fields['relation'])) {
                $fields['action'] = 'linked';
                $fields['affected_other'] = intval($fields['relation']);
                do_action('pre_post_update', $id, $fields);
            }

            $this->helperService->save_custom_data($id, $fields, $this->post_type, ['event_status', 'priority']);

            $resp = [
                'ok' => true,
                'msg' => "$this->post_type created",
                'data' => $id
            ];

            return wp_send_json($resp, 201);
        }
    }

    public function construct_object($id): array
    {
        $obj = [];

        return $obj;
    }

}