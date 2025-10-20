<?php


namespace Inc\Api;

use Inc\Service\HelpersService;

if (!defined('ABSPATH')) {
    die;
}

class OpportunitiesApi
{
    private $post_type = 'opportunity';
    private $helperService;

    private $select_taxonomies = array(
        'lead_status',
        'lead_source'
    );

    private $expected_body = array(
        array(
            'key' => 'title',
            'required' => true
        ),
        array(
            'key' => 'assigned_to',
            'required' => true
        ),
        array(
            'key' => 'contact',
            'required' => true
        ),
        array(
            'key' => 'lead_status',
            'required' => true
        ),
        array(
            'key' => 'close_date',
            'required' => true
        ),
    );


    public function __construct()
    {
        $this->helperService = new HelpersService();
    }

    public function register()
    {
        add_action('wp_ajax_' . $this->post_type . '_taxonomies', array($this, 'get_taxonomies'));
        add_action('wp_ajax_nopriv_' . $this->post_type . '_taxonomies', array($this, 'get_taxonomies'));

        add_action('wp_ajax_get_opportunity', array($this, 'get_opportunity'));
        add_action('wp_ajax_nopriv_get_opportunity', array($this, 'get_opportunity'));

        add_action('wp_ajax_get_opportunies', array($this, 'get_opportunies'));
        add_action('wp_ajax_nopriv_get_opportunies', array($this, 'get_opportunies'));

        add_action('wp_ajax_new_opportunity', array($this, 'new_opportunity'));
        add_action('wp_ajax_nopriv_new_opportunity', array($this, 'new_opportunity'));

        add_action('wp_ajax_edit_opportunity', array($this, 'edit_opportunity'));
        add_action('wp_ajax_nopriv_edit_opportunity', array($this, 'edit_opportunity'));

    }

    public function get_taxonomies()
    {
        return wp_send_json($this->helperService->get_post_types_taxonomies_terms($this->post_type, $this->select_taxonomies, true));
    }

    public function get_opportunity()
    {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $updating = isset($_POST['updating']) ? boolval($_POST['updating']) : false;

        if ($id <= 0) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'invalid id'
            ), 400);
        }

        $single = get_post($id);

        if (!$single || $single->post_type !== $this->post_type) {
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

    public function get_opportunies()
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

    public function new_opportunity()
    {
        $fields = $this->helperService->sanitize_fields($_POST['fields']);
        $is_valid = $this->helperService->check_body($fields, $this->expected_body);
        $default_user = get_current_user_id();
        $default_user = $default_user == 0 ? 1 : $default_user;

        $assigned_to = isset($fields['assigned_to']) ? $fields['assigned_to'] : $default_user;
        $title = isset($fields['title']) ? sanitize_text_field($fields['title']) : '';

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

        if (empty($title)) {
            $contact = isset($fields['contact']);
            $contact_name = get_field('first_name', $contact);
            $args['post_title'] = $contact_name;
        }

        $id = wp_insert_post($args);

        if (is_wp_error($id)) {

            return wp_send_json(array(
                'ok' => false,
                'msg' => "error found - $this->post_type not created"
            ), 400);

        } else {
            $this->helperService->save_custom_data($id, $fields, $this->post_type, $this->select_taxonomies, true);
            $resp = [
                'ok' => true,
                'msg' => "$this->post_type created",
                'data' => $id
            ];
            return wp_send_json($resp, 201);
        }
    }

    public function edit_opportunity()
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

        $title = isset($fields['title']) ? sanitize_text_field($fields['title']) : $this->post_type . '_' . wp_generate_uuid4();

        if (!$is_valid) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'invalid field or fields'
            ), 400);
        }

        $args = array(
            'post_type' => $title,
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


            $this->helperService->save_custom_data($id, $fields, $this->post_type, $this->select_taxonomies, true);

            $resp = [
                'ok' => true,
                'msg' => "$this->post_type edited",
                'data' => $id
            ];

            return wp_send_json($resp, 201);
        }
    }

    private function construct_object($item_id, $editing = false)
    {
        $default_format = 'd-m-Y g:i a';
        $obj = array();
        $obj['id'] = $item_id;
        $obj['title'] = get_the_title($item_id);

        $assigned = '';
        $assigned_to = get_field('assigned_to', $item_id);

        if (!$editing) {
            $assigned = get_user_by('ID', $assigned_to)->display_name;
        } else {
            $assigned = $assigned_to;
        }

        $obj['assigned_to'] = $assigned;
        $obj['close_date'] = get_field('close_date', $item_id);

        if ($editing) {
            $rest_of_fields = get_fields($item_id);
            $taxonomies = array_merge(get_taxonomies(['object_type' => [$this->post_type]]), $this->select_taxonomies);
            $tax = wp_get_post_terms($item_id, $taxonomies);

            foreach ($tax as $term) {
                $obj[$term->taxonomy] = $term->term_id;
            }
            foreach ($rest_of_fields as $name => $value) {
                $obj[$name] = $value;
            }
        }

        $obj['created_at'] = get_the_date($default_format, $item_id);

        return $obj;
    }

}