<?php

namespace Inc\Api;

use Inc\Service\HelpersService;

if (!defined('ABSPATH')) {
    die;
}

class EventsApi
{
    private $post_type = 'event';
    private $helperService;
    private $specific_taxonomies = ['event_status', 'event_type', 'priority'];

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
        ),
        array(
            'key' => 'event_type',
            'required' => true
        )
    );

    public function __construct()
    {
        $this->helperService = new HelpersService();
    }

    public function register()
    {
        add_action('wp_ajax_create_event', array($this, 'create_event'));
        add_action('wp_ajax_nopriv_create_event', array($this, 'create_event'));

        add_action('wp_ajax_edit_event', array($this, 'edit_event'));
        add_action('wp_ajax_nopriv_edit_event', array($this, 'edit_event'));


        add_action('wp_ajax_related_activities', array($this, 'related_activities'));
        add_action('wp_ajax_nopriv_related_activities', array($this, 'related_activities'));

        add_action('wp_ajax_event_taxonomies', array($this, 'get_taxonomies'));
        add_action('wp_ajax_nopriv_event_taxonomies', array($this, 'get_taxonomies'));

        add_action('wp_ajax_activities_list', array($this, 'activities_list'));
        add_action('wp_ajax_nopriv_activities_list', array($this, 'activities_list'));
    }

    public function activities_list()
    {
        $filters = isset($_POST['filters']) ? wp_unslash($_POST['filters']) : '';
        $filters = json_decode($filters, true);

        $row = $_POST['page'];
        $row_per_page = $_POST['perPage'];


        $field_groups = acf_get_field_groups([
            'post_type' => $this->post_type
        ]);

        $fields = [];
        foreach ($field_groups as $group) {
            $fields = acf_get_fields($group['key']);
        }

        $fields = wp_list_pluck($fields, 'name');

        $filters = sanitize_associative_array($filters);

        $task_counts = wp_count_posts($this->post_type);
        $event_counts = wp_count_posts('task');

        $total_records = $task_counts->publish + $event_counts->publish;
        $selected_default = ['event', 'task'];

        if (isset($filters['selected_types']))
            $selected_default = $filters['selected_types'];

        $args = array(
            'post_type' => $selected_default,
            'fields' => 'ids',
            'posts_per_page' => intval($row_per_page),
            'paged' => $row,
            'meta_query' => [],
            'tax_query' => [],
            'date_query' => []
        );

        if (isset($filters['title'])) {
            $args['s'] = $filters['title'];
        }

        if (!empty($filters)) {
            $args['tax_query']['relation'] = 'AND';
            $args['meta_query'][] = ['relation' => 'AND'];

            foreach ($filters as $item => $value) {

                if ($item == 'from' && isset($filters['to'])) {

                    $args['meta_query'][0][] = array(
                        'key' => $item,
                        'value' => $value,
                        'compare' => '>=',
                        'type' => 'DATE',
                    );


                    $args['meta_query'][0][] = array(
                        'key' => 'to', // Replace with your custom field key storing the date
                        'value' => $filters['to'],
                        'compare' => '<=',
                        'type' => 'DATE', // Specify the type as DATE for proper comparison
                    );


                } elseif ($item == 'from' && !isset($filters['to'])) {
                    $args['meta_query'][0][] = array(
                        'key' => $item, // Replace with your custom field key storing the date
                        'value' => $value,
                        'compare' => '>=',
                        'type' => 'DATE', // Specify the type as DATE for proper comparison
                    );
                } elseif ($item == 'to' && !isset($filters['from'])) {
                    $args['meta_query'][0][] = array(
                        'key' => $item, // Replace with your custom field key storing the date
                        'value' => $value,
                        'compare' => '<=',
                        'type' => 'DATE', // Specify the type as DATE for proper comparison
                    );
                } else if ($item != 'to' && $item != 'from') {

                    if (in_array($item, $this->specific_taxonomies) && ($value && !empty($value))) {
                        $args['tax_query'][] = [
                            'taxonomy' => $item,
                            'terms' => array($value),
                            'field' => 'term_id'
                        ];
                    }

                    if (in_array($item, $fields) && ($value && !empty($value))) {

                        if (!is_array($value)) {
                            $args['meta_query'][0][] = [
                                'key' => $item,
                                'value' => $value,
                                'compare' => is_numeric($value) ? '=' : 'LIKE'
                            ];
                        } else {
                            $args['meta_query'][0][] = [
                                'key' => $item,
                                'value' => $value,
                                'compare' => 'IN',
                            ];
                        }

                    }

                }
            }
        }

        $query = new \WP_Query($args);

        $total_records_filtered = $query->found_posts;
        $data_arr = [];

        foreach ($query->posts as $id) {
            $data_arr[] = $this->construct_activity($id, true);
        }

        $response = array(
            'recordsTotal' => intval($total_records),
            'recordsFiltered' => $total_records_filtered,
            'data' => $data_arr
        );

        return wp_send_json($response);
    }

    public function related_activities()
    {
        $related = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        if (empty($related)) {
            return wp_send_json_error(array(
                'ok' => false,
                'msg' => 'relation is mandatory'
            ));
        }

        $related_data = [];

        $args = [
            'post_type' => ['event', 'task'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => 'relation',
            'meta_value' => $related
        ];

        $query = new \WP_Query($args);

        foreach ($query->posts as $id) {
            $related_data[] = $this->construct_activity($id);
        }

        return wp_send_json($related_data);

    }

    public function get_taxonomies()
    {
        return wp_send_json($this->helperService->get_post_types_taxonomies_terms($this->post_type, $this->specific_taxonomies));
    }

    public function create_event()
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
                $fields['affected_other'] = $fields['relation'];
                do_action('pre_post_update', $id, $fields);
            }

            $this->helperService->save_custom_data($id, $fields, $this->post_type, $this->specific_taxonomies);

            $resp = [
                'ok' => true,
                'msg' => "$this->post_type created",
                'data' => $id
            ];

            return wp_send_json($resp, 201);
        }
    }

    public function edit_event()
    {
        $fields = $this->helperService->sanitize_fields($_POST['fields']);
        $is_valid = $this->helperService->check_body($fields, $this->expected_body);

        if (!$is_valid || !isset($fields['id'])) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'invalid field or fields'
            ), 400);
        }

        $id = $fields['id'];
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
            'post_type' => isset($fields['post_type']) ? $fields['post_type'] : $this->post_type,
            'post_author' => $assigned_to,
            'ID' => $id,
        );

        $id = wp_update_post($args);

        if (is_wp_error($id)) {

            return wp_send_json(array(
                'ok' => false,
                'msg' => "error found - $this->post_type not created"
            ), 400);

        } else {

            if (isset($fields['relation'])) {
                $fields['action'] = 'edited';
                do_action('pre_post_update', $id, $fields);
            }

            $this->helperService->save_custom_data($id, $fields, $this->post_type, $this->specific_taxonomies);

            $resp = [
                'ok' => true,
                'msg' => "$this->post_type edited",
                'data' => $id
            ];

            return wp_send_json($resp, 201);
        }
    }

    public function get_all_events()
    {

    }

    public function get_related_events()
    {

    }


    private function construct_object($id)
    {

        $obj = [];



        return $obj;

    }
    private function construct_activity($id, $list = false)
    {
        $obj = [];
        $date = get_the_date('D j M', $id);
        $terms = wp_get_post_terms($id, 'event_status', ['fields' => 'names']);

        $obj['title'] = get_the_title($id);
        $obj['post_type'] = get_post_type($id);
        $obj['id'] = $id;
        $obj['date'] = $date;
        $obj['status'] = !empty($terms) ? $terms[0] : '';
        $obj['relation'] = get_field('relation', $id);
        $obj['from'] = get_field('from', $id);
        $obj['to'] = get_field('to', $id);

        if (!$list) {
            $activity = wp_get_post_terms($id, 'event_type', ['fields' => 'ids']);
            $obj['event_type'] = !empty($activity) ? $activity[0] : 'Task';
            $obj['assigned_to'] = get_field('assigned_to', $id);
        }


        if ($list) {

            $obj['event_status'] = !empty($terms) ? $terms[0] : '';

            $activity = wp_get_post_terms($id, 'event_type', ['fields' => 'names']);
            $obj['event_type'] = !empty($activity) ? $activity[0] : 'Task';
            $obj['description'] = get_field('description', $id);

            $assigned = get_field('assigned_to', $id);
            $user = get_user_by('id', $assigned);

            $obj['assigned_to'] = $user->display_name;
        }



        return $obj;
    }


}