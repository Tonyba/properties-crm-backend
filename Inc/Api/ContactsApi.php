<?php


namespace Inc\Api;

use Inc\Service\HelpersService;


if (!defined('ABSPATH')) {
    die;
}

class ContactsApi
{

    private $post_type = 'contact';
    private $helperService;

    private $select_taxonomies = array(
        'lead_source'
    );

    private static $privated_tax = array(
        'lead_source'
    );

    private $expected_body = array(
        array(
            'key' => 'assigned_to',
            'required' => true
        )
    );


    public function __construct()
    {
        $this->helperService = new HelpersService();
    }

    public function register()
    {
        add_action('wp_ajax_' . $this->post_type . '_taxonomies', array($this, 'get_taxonomies'));
        add_action('wp_ajax_nopriv_' . $this->post_type . '_taxonomies', array($this, 'get_taxonomies'));

        add_action('wp_ajax_new_contact', array($this, 'new_contact'));
        add_action('wp_ajax_nopriv_new_contact', array($this, 'new_contact'));

        add_action('wp_ajax_edit_contact', array($this, 'edit_contact'));
        add_action('wp_ajax_nopriv_edit_contact', array($this, 'edit_contact'));

        add_action('wp_ajax_get_contacts', array($this, 'get_contacts'));
        add_action('wp_ajax_nopriv_get_contacts', array($this, 'get_contacts'));

        add_action('wp_ajax_get_contact', array($this, 'get_contact'));
        add_action('wp_ajax_nopriv_get_contact', array($this, 'get_contact'));

        add_action('wp_ajax_contact_by_name_or_id', array($this, 'get_by_name_or_id'));
        add_action('wp_ajax_nopriv_contact_by_name_or_id', array($this, 'get_by_name_or_id'));
    }

    public function get_taxonomies()
    {
        return wp_send_json($this->helperService->get_post_types_taxonomies_terms($this->post_type, $this->select_taxonomies));
    }

    public function get_contact()
    {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $updating = isset($_POST['updating']) ? boolval($_POST['updating']) : false;

        if ($id <= 0) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'invalid id'
            ), 400);
        }

        $lead = get_post($id);

        if (!$lead || $lead->post_type !== $this->post_type) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => $this->post_type . ' not found'
            ), 404);
        }

        $data = $this->construct_object($id, $updating);

        return wp_send_json(array(
            'ok' => true,
            'data' => $data
        ), 200);
    }

    public static function get_type_taxonomies()
    {
        return self::$privated_tax;
    }

    public function new_contact()
    {
        $fields = $this->helperService->sanitize_fields($_POST['fields']);
        $is_valid = $this->helperService->check_body($fields, $this->expected_body);
        $default_user = get_current_user_id();
        $default_user = $default_user == 0 ? 1 : $default_user;

        $assigned_to = isset($fields['assigned_to']) ? $fields['assigned_to'] : $default_user;
        $title = isset($fields['first_name']) ? $fields['first_name'] . ' ' . $fields['last_name'] : $this->post_type . '_' . wp_generate_uuid4();

        if (!$is_valid) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'invalid field or fields'
            ), 400);
        }

        $args = array(
            'post_title' => $title,
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

            $this->helperService->save_custom_data($id, $fields, $this->post_type, $this->select_taxonomies);

            $resp = [
                'ok' => true,
                'msg' => "$this->post_type created",
                'data' => $id
            ];

            return wp_send_json($resp, 201);
        }

    }

    public function get_by_name_or_id()
    {
        $search_term = isset($_POST['search_term']) ? sanitize_text_field($_POST['search_term']) : '';
        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        if (empty($search_term) && empty($id)) {
            return wp_send_json(array(), 200);
        }

        $args = array(
            'post_type' => $this->post_type,
            'fields' => 'ids',
            'posts_per_page' => 10,
            'no_found_rows' => true,
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'first_name',
                    'value' => $search_term,
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => 'last_name',
                    'value' => $search_term,
                    'compare' => 'LIKE'
                )
            )
        );


        if (!empty($id) && is_numeric($id)) {
            $args['p'] = intval($id);
            unset($args['meta_query']);
        }

        $query = new \WP_Query($args);
        $data = [];


        foreach ($query->posts as $id) {
            $title = get_field('first_name', $id) . ' ' . get_field('last_name', $id);
            $data[] = [
                'title' => $title,
                'id' => $id,
            ];
        }

        return wp_send_json($data);
    }

    public function edit_contact()
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

            $fields['action'] = 'edited';
            do_action('pre_post_update', $id, $fields);

            $this->helperService->save_custom_data($id, $fields, $this->post_type, $this->select_taxonomies);

            $resp = [
                'ok' => true,
                'msg' => "$this->post_type edited",
                'data' => $id
            ];

            return wp_send_json($resp, 201);
        }
    }

    public function get_contacts()
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

        $taxonomies = get_taxonomies(['object_type' => [$this->post_type]]);
        $taxonomies_arr = [];

        foreach ($taxonomies as $taxonomy => $value) {
            $taxonomies_arr[] = $value;
        }

        $filters = sanitize_associative_array($filters);

        $counts = wp_count_posts($this->post_type);
        $total_records = $counts->publish;

        $args = array(
            'post_type' => $this->post_type,
            'fields' => 'ids',
            'posts_per_page' => intval($row_per_page),
            'paged' => $row,
            'meta_query' => [],
            'tax_query' => []
        );

        if (!empty($filters)) {
            $args['tax_query']['relation'] = 'AND';
            $args['meta_query'][] = ['relation' => 'AND'];

            foreach ($filters as $item => $value) {

                if (in_array($item, $taxonomies_arr) && ($value && !empty($value))) {
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

        $query = new \WP_Query($args);

        $total_records_filtered = $query->found_posts;
        $data_arr = [];

        foreach ($query->posts as $id) {
            $data_arr[] = $this->construct_object($id);
        }

        $response = array(
            'recordsTotal' => intval($total_records),
            'recordsFiltered' => $total_records_filtered,
            'data' => $data_arr,
            //     'args' => $args,
        );

        return wp_send_json($response);

    }

    private function construct_object($item_id, $editing = false)
    {
        $first_name = get_field('first_name', $item_id);
        $last_name = get_field('last_name', $item_id);
        $office_phone = get_field('office_phone', $item_id);
        $email = get_field('email', $item_id);
        $assigned = '';
        $assigned_to = get_field('assigned_to', $item_id);

        $country = '';
        $state = '';
        $city = '';

        if (!$editing) {
            $assigned = get_user_by('ID', $assigned_to)->display_name;
        } else {
            $assigned = $assigned_to;
            $country = get_field('country', $item_id);
            $state = get_field('state', $item_id);
            $city = get_field('city', $item_id);
        }

        $obj = [
            'id' => $item_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'office_phone' => $office_phone,
            'assigned_to' => $assigned,
            'country' => $country,
            'state' => $state,
            'city' => $city
        ];

        if ($editing) {
            $rest_of_fields = get_fields($item_id);
            $tax = wp_get_post_terms($item_id, $this->select_taxonomies, ['fields' => 'ids']);

            foreach ($this->select_taxonomies as $i => $key) {
                $obj[$key] = $tax[$i];
            }

            foreach ($rest_of_fields as $name => $value) {
                $obj[$name] = $value;
            }

            if (!isset($obj['not_call']))
                $obj['not_call'] = false;
            if (!isset($obj['email_opt_out']))
                $obj['email_opt_out'] = false;
        }

        return $obj;
    }
}