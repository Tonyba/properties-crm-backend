<?php


namespace Inc\Api;

use Inc\Service\HelpersService;
use Inc\Service\UpdatesService;


if (!defined('ABSPATH')) {
    die;
}

class LeadsApi
{
    private $post_type = 'lead';

    private $helperService;

    private $select_taxonomies = array(
        'industry',
        'lead_source',
        'lead_status'
    );

    private static $privated_tax = array(
        'industry',
        'lead_source',
        'lead_status'
    );

    private $expected_body = array(
        array(
            'key' => 'first_name',
            'required' => true
        ),
        array(
            'key' => 'last_name',
            'required' => true,
        ),
        array(
            'key' => 'phone',
            'required' => true,
        ),
        array(
            'key' => 'company',
            'required' => false
        ),
        array(
            'key' => 'website',
            'required' => false
        ),
        array(
            'key' => 'email',
            'required' => true
        ),
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
        add_action('wp_ajax_get_leads', array($this, 'get_leads'));
        add_action('wp_ajax_nopriv_get_leads', array($this, 'get_leads'));

        add_action('wp_ajax_new_lead', array($this, 'new_lead'));
        add_action('wp_ajax_nopriv_new_lead', array($this, 'new_lead'));

        add_action('wp_ajax_edit_lead', array($this, 'edit_lead'));
        add_action('wp_ajax_nopriv_edit_lead', array($this, 'edit_lead'));


        add_action('wp_ajax_get_lead', array($this, 'get_lead'));
        add_action('wp_ajax_nopriv_get_lead', array($this, 'get_lead'));

        add_action('wp_ajax_lead_select_values', array($this, 'send_selects_values'));
        add_action('wp_ajax_nopriv_lead_select_values', array($this, 'send_selects_values'));

        add_action('wp_ajax_lead_agents', array($this, 'lead_agents'));
        add_action('wp_ajax_nopriv_lead_agents', array($this, 'lead_agents'));


        add_action('wp_ajax_delete_lead', array($this, 'delete_lead'));
        add_action('wp_ajax_nopriv_delete_lead', array($this, 'delete_lead'));

        add_action('wp_ajax_' . $this->post_type . '_taxonomies', array($this, 'get_taxonomies'));
        add_action('wp_ajax_nopriv_' . $this->post_type . '_taxonomies', array($this, 'get_taxonomies'));
    }

    public function get_taxonomies()
    {
        return wp_send_json($this->helperService->get_post_types_taxonomies_terms($this->post_type, $this->select_taxonomies));
    }

    private function get_select_taxonomies()
    {

        $terms = [];
        $agents = get_users(array('role' => 'agent'));

        $terms['assigned_to'] = [];

        foreach ($agents as $agent) {

            $terms['assigned_to'][] = array(
                'term_id' => $agent->ID,
                'name' => $agent->display_name
            );

        }

        foreach ($this->select_taxonomies as $taxonomy) {


            foreach ($this->select_taxonomies as $taxonomy) {

                $terms[$taxonomy] = get_terms(array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                ));

            }

            return $terms;
        }
    }

    public static function get_type_taxonomies()
    {
        return self::$privated_tax;
    }

    public function lead_agents()
    {
        $agents = get_users(array('role' => 'agent'));

        $data = [];

        foreach ($agents as $agent) {
            $data[] = array(
                'id' => $agent->ID,
                'name' => $agent->display_name
            );
        }

        return wp_send_json($data);

    }

    public function send_selects_values()
    {
        $select_values = $this->get_select_taxonomies();
        return wp_send_json($select_values);
    }

    function get_lead()
    {
        $lead_id = isset($_POST['lead_id']) ? intval($_POST['lead_id']) : 0;
        $updating = isset($_POST['updating']) ? boolval($_POST['updating']) : false;

        if ($lead_id <= 0) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'invalid lead id'
            ), 400);
        }

        $lead = get_post($lead_id);

        if (!$lead || $lead->post_type !== $this->post_type) {
            return wp_send_json(array(
                'ok' => false,
                'msg' => 'lead not found'
            ), 404);
        }

        $data = $this->construct_object($lead_id, $updating);

        return wp_send_json(array(
            'data' => $data
        ), 200);
    }

    public function get_leads()
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


    public function new_lead()
    {

        $fields = isset($_POST['fields']) ? wp_unslash($_POST['fields']) : '';
        $fields = json_decode($fields, true);

        $fields = sanitize_associative_array($fields);
        $is_valid = $this->helperService->check_body($fields, $this->expected_body);

        if (!$is_valid) {

            return wp_send_json(array(
                'ok' => false,
                'msg' => 'invalid field or fields'
            ), 400);
        }

        $args = array(
            'post_title' => $this->post_type . '_' . wp_generate_uuid4(),
            'post_status' => 'publish',
            'post_type' => $this->post_type,
            'post_author' => get_current_user_id()
        );

        $lead_id = wp_insert_post($args);

        if (is_wp_error($lead_id)) {

            return wp_send_json(array(
                'ok' => false,
                'msg' => 'error found - lead not added'
            ), 400);

        } else {

            $field_groups = acf_get_field_groups([
                'post_type' => $this->post_type
            ]);

            $acf_fields = [];
            foreach ($field_groups as $group) {
                $acf_fields = acf_get_fields($group['key']);
            }

            $acf_fields = wp_list_pluck($acf_fields, 'name');

            $taxonomies = get_taxonomies(['object_type' => [$this->post_type]]);
            $taxonomies_arr = [];

            foreach ($taxonomies as $taxonomy => $value) {
                $taxonomies_arr[] = $value;
            }

            foreach ($fields as $item => $value) {
                if (in_array($item, $taxonomies_arr) && ($value && !empty($value))) {
                    wp_set_post_terms($lead_id, $value, $item);
                }


                if (in_array($item, $acf_fields) && ($value && !empty($value))) {
                    update_field($item, $value, $lead_id);
                }
            }

            return wp_send_json(array(
                'ok' => true,
                'msg' => $this->post_type . ' added',
            ), 201);

        }

    }

    public function edit_lead()
    {
        $fields = isset($_POST['fields']) ? wp_unslash($_POST['fields']) : '';
        $fields = json_decode($fields, true);

        $fields = sanitize_associative_array($fields);
        $is_valid = $this->helperService->check_body($fields, expected_body: $this->expected_body);

        if (!$is_valid || !isset($fields['id'])) {

            return wp_send_json(array(
                'ok' => false,
                'msg' => 'invalid field or fields'
            ), 400);
        }

        $id = $fields['id'];

        $new_data = array(
            'ID' => $id,
        );

        $data = wp_update_post($new_data);

        if (is_wp_error($data)) {

            return wp_send_json(array(
                'ok' => false,
                'msg' => 'error found - not updated'
            ), 400);

        } else {
            $fields['action'] = 'edited';
            do_action('pre_post_update', $id, $fields);

            $field_groups = acf_get_field_groups([
                'post_type' => $fields['post_type']
            ]);

            $acf_fields = [];
            foreach ($field_groups as $group) {
                $acf_fields = acf_get_fields($group['key']);
            }

            $acf_fields = wp_list_pluck($acf_fields, 'name');

            $taxonomies = get_taxonomies(['object_type' => [$fields['post_type']]]);
            $taxonomies_arr = [];

            foreach ($taxonomies as $taxonomy => $value) {
                $taxonomies_arr[] = $value;
            }

            $taxonomies_arr = array_merge($taxonomies_arr, $this->select_taxonomies);

            foreach ($fields as $item => $value) {
                if (in_array($item, $taxonomies_arr) && ($value && !empty($value))) {
                    wp_set_post_terms($id, $value, $item);
                }


                if (in_array($item, $acf_fields) && ($value && !empty($value))) {
                    update_field($item, $value, $id);
                }
            }


            return wp_send_json(array(
                'ok' => true,
                'msg' => 'lead edited',
            ), 201);

        }
    }

    private function construct_object($item_id, $editing = false)
    {
        $terms = get_the_terms($item_id, $this->select_taxonomies);

        $first_name = get_field('first_name', $item_id);
        $last_name = get_field('last_name', $item_id);
        $company = get_field('company', $item_id);
        $phone = get_field('phone', $item_id);
        $website = get_field('website', $item_id);
        $email = get_field('email', $item_id);
        $assigned_to = get_field('assigned_to', $item_id);
        $description = get_field('description', $item_id);

        $country = '';
        $state = '';
        $city = '';

        $assigned = '';

        if (!$editing) {
            $assigned = get_user_by('ID', $assigned_to)->display_name;
        } else {
            $assigned = $assigned_to;
            $country = get_field('country', $item_id);
            $state = get_field('state', $item_id);
            $city = get_field('city', $item_id);
        }

        $requested_property = get_field('requested_property', $item_id);

        $final_obj = array(
            'id' => $item_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'company' => $company,
            'phone' => $phone,
            'website' => $website,
            'email' => $email,
            'assigned_to' => $assigned,
            'requested_property' => $requested_property,
            'description' => $description,
            'country' => $country,
            'state' => $state,
            'city' => $city
        );

        foreach ($this->select_taxonomies as $taxonomy) {
            $final_obj[$taxonomy] = [];
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    if ($term->taxonomy === $taxonomy) {
                        $final_obj[$taxonomy] = $term->term_id;
                    }
                }
            }
        }
        return $final_obj;
    }
}
