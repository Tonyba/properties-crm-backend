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

        add_action('wp_ajax_get_stages', array($this, 'get_stages'));
        add_action('wp_ajax_nopriv_get_stages', array($this, 'get_stages'));

        add_action('wp_ajax_change_stage', array($this, 'change_stage'));
        add_action('wp_ajax_nopriv_change_stage', array($this, 'change_stage'));

        add_action('wp_ajax_related_services', array($this, 'related_services'));
        add_action('wp_ajax_nopriv_related_services', array($this, 'related_services'));

        add_action('wp_ajax_get_sources', array($this, 'get_sources'));
        add_action('wp_ajax_nopriv_get_sources', array($this, 'get_sources'));
    }

    public function related_services()
    {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'invalid id'
            ), 400);
        }


        $related_services = get_field('related_services', $id);
        $data = [];

        if (!empty($related_services)) {
            foreach ($related_services as $service) {
                $title = get_the_title($service);
                $data[] = [
                    'id' => $service,
                    'title' => $title
                ];
            }
        }




        return wp_send_json($data);
    }

    public function get_taxonomies()
    {
        return wp_send_json($this->helperService->get_post_types_taxonomies_terms($this->post_type, $this->select_taxonomies, true));
    }


    public function change_stage()
    {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $stage = isset($_POST['stage']) ? intval($_POST['stage']) : 0;

        if ($id <= 0 || $stage <= 0) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'invalid id or stage'
            ), 400);
        }

        wp_set_post_terms($id, array($stage), 'lead_status', false);

        return wp_send_json(array(
            'ok' => true,
            'msg' => 'stage changed'
        ), 200);
    }

    public function get_stages()
    {

        $stages = get_terms([
            'taxonomy' => 'lead_status',
            'hide_empty' => false,
            'fields' => 'id=>name'
        ]);

        $formatted = [];

        foreach ($stages as $term_id => $term_name) {
            $formatted[] = [
                'label' => $term_name,
                'value' => $term_id
            ];
        }

        wp_send_json_success($formatted);

        wp_die();

    }

    public function get_sources()
    {

        $stages = get_terms([
            'taxonomy' => 'lead_source',
            'hide_empty' => false,
            'fields' => 'id=>name'
        ]);

        $formatted = [];

        foreach ($stages as $term_id => $term_name) {
            $formatted[] = [
                'label' => $term_name,
                'value' => $term_id
            ];
        }

        wp_send_json_success($formatted);

        wp_die();

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

        error_log(json_encode($single));

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

        $taxonomies_arr = $this->select_taxonomies;

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

        if (isset($filters['title'])) {
            $args['s'] = $filters['title'];
        }

        if (!empty($filters)) {
            $args['tax_query']['relation'] = 'AND';
            // $args['meta_query'][] = ['relation' => 'AND'];

            foreach ($filters as $item => $value) {

                if (in_array($item, $taxonomies_arr) && ($value && !empty($value))) {
                    $args['tax_query'][] = [
                        'taxonomy' => $item,
                        'terms' => !is_array($value) ? array($value) : $value,
                        'field' => 'term_id'
                    ];
                }

                if (in_array($item, $fields) && ($value && !empty($value))) {


                    if ($item == 'close_date') {

                        if (is_array($value) && isset($value['start']) && isset($value['end'])) {

                            $start_day = $value['start']['day'];
                            $start_year = $value['start']['year'];
                            $start_month = $value['start']['month'];

                            $end_day = $value['end']['day'];
                            $end_year = $value['end']['year'];
                            $end_month = $value['end']['month'];

                            $start_date = sprintf('%04d-%02d-%02d', $start_year, $start_month, $start_day);
                            $end_date = sprintf('%04d-%02d-%02d', $end_year, $end_month, $end_day);

                            $args['meta_query'][] = [
                                'key' => $item,
                                'value' => array($start_date, $end_date),
                                'type' => 'DATE',
                                'compare' => 'BETWEEN'
                            ];
                        }

                    } else {
                        if (!is_array($value)) {
                            $args['meta_query'][] = [
                                'key' => $item,
                                'value' => $value,
                                'compare' => is_numeric($value) ? '=' : 'LIKE'
                            ];
                        } else {
                            $args['meta_query'][] = [
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
            $data_arr[] = $this->construct_object($id);
        }

        $response = array(
            'recordsTotal' => intval($total_records),
            'recordsFiltered' => $total_records_filtered,
            'data' => $data_arr,
            // 'filters' => $filters,
            'args' => $args,
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
            'post_title' => $title,
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

            $this->helperService->save_custom_data($id, $fields, $this->post_type, $this->select_taxonomies, true);

            $resp = [
                'ok' => true,
                'msg' => "$this->post_type edited",
                'data' => $id,
                'fields' => $fields
            ];

            return wp_send_json($resp, 201);
        }
    }

    private function construct_object($item_id, $editing = false)
    {

        $taxonomies_list = wp_get_post_terms($item_id, $this->select_taxonomies);

        $default_format = 'd-m-Y g:i a';
        $obj = array();
        $obj['id'] = $item_id;
        $obj['title'] = get_the_title($item_id);
        $obj['contact'] = get_field('contact', $item_id);

        if (!empty($taxonomies_list)) {
            foreach ($taxonomies_list as $tax_term) {
                $obj[$tax_term->taxonomy] = $tax_term->name;
            }
        }

        $assigned = '';
        $assigned_to = get_field('assigned_to', $item_id);

        if (!$editing) {
            $assigned = get_user_by('ID', $assigned_to)->display_name;
        } else {
            $assigned = $assigned_to;
        }

        $obj['related_services'] = get_field('related_services', $item_id);

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