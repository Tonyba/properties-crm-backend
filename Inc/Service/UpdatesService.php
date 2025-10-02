<?php

namespace Inc\Service;

class UpdatesService
{
    private $post_type = 'update';
    private $valid_post_types = ['lead', 'contact', 'property', 'task', 'event', 'document'];

    private $helpersService;

    function __construct()
    {
        $this->helpersService = new HelpersService();
    }

    public function register()
    {

        add_action('wp_ajax_get_updates', array($this, 'get_updates'));
        add_action('wp_ajax_nopriv_get_updates', array($this, 'get_updates'));

        add_action('pre_post_update', array($this, 'add_update'), 10, 2);

    }

    public function get_updates()
    {
        $relation_id = $_POST['id'];

        if (empty($relation_id))
            return wp_send_json_error([
                'ok' => false,
                'msg' => 'needs relation id'
            ]);

        $query_args = array(
            'post_type' => $this->post_type,
            'fields' => 'ids',
            'posts_per_page' => 50,
            'no_found_rows' => true,
            'orderby' => 'publish_date',
            'order' => 'DESC',
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
            'cache_results' => true,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'relation',
                    'value' => $relation_id,
                    'compare' => '='
                ),
                array(
                    'key' => 'affected_other',
                    'value' => $relation_id,
                    'compare' => '='
                )
            )
        );

        $query = new \WP_Query($query_args);
        $updates = [];

        $creation = [];
        $related_obj = get_post($relation_id);

        $creator = get_user_by('id', $related_obj->post_author == 0 ? 1 : $related_obj->post_author);

        $creation['id'] = 0;
        $creation['date'] = $related_obj->post_date . ' - ' . $this->helpersService->timeAgo($related_obj->post_date);
        $creation['user'] = $creator->display_name;
        $creation['action'] = 'Created';


        foreach ($query->posts as $update) {
            $updates[] = $this->construct_object($update);
        }

        $updates[] = $creation;

        return wp_send_json(array(
            'ok' => true,
            'data' => $updates,
        ));
    }

    private function formatSpecialValues($key, $value)
    {
        switch ($key) {
            case 'requested_property':
                $value = get_the_title($value);
                break;

            case 'assigned_to':
                $user = get_user_by('id', $value);
                $value = $user->display_name;
                break;

            default:

                break;
        }

        return $value;
    }

    public function add_update($id, $new_data)
    {
        $relation_id = $id;
        $relation_post_type = get_post_type($relation_id);

        if (!in_array($relation_post_type, $this->valid_post_types))
            return $new_data;

        $action = $new_data['action'];

        $action_selected = get_term_by('slug', $action, 'update-action');

        $user_id = 1;
        // $user_id = get_current_user_id(  );

        $field_groups = acf_get_field_groups([
            'post_type' => $relation_post_type
        ]);

        $acf_fields = [];
        foreach ($field_groups as $group) {
            $acf_fields = acf_get_fields($group['key']);
        }

        $acf_fields = wp_list_pluck($acf_fields, 'name');


        $taxonomies_arr = [];

        if ($relation_post_type != 'task' && $relation_post_type != 'event') {

        }

        switch ($relation_post_type) {
            case 'task':
                $taxonomies_arr = ['event_state', 'priority'];
                break;

            case 'event':
                $taxonomies_arr = ['event_state', 'priority', 'event_type'];
                break;

            default:
                $taxonomies = get_taxonomies(['object_type' => [$relation_post_type]]);
                foreach ($taxonomies as $taxonomy => $value) {
                    $taxonomies_arr[] = $value;
                }
                break;
        }

        $old_data = [];

        foreach ($acf_fields as $acf_field) {
            $old_data[$acf_field] = get_field($acf_field, $id);
        }

        foreach ($taxonomies_arr as $tax) {
            $item_terms = wp_get_post_terms($id, $tax);
            $old_data[$tax] = $item_terms;
        }


        $post_data = [
            'post_title' => 'update_' . $relation_post_type . '_' . $relation_id,
            'post_type' => $this->post_type,
            'post_author' => $user_id,
            'post_status' => 'publish'
        ];

        $id = wp_insert_post($post_data);

        if (is_wp_error($id)) {
            return wp_send_json_error([
                'ok' => false,
                'msg' => 'error creating update'
            ]);
        }

        wp_set_post_terms($id, $action_selected->term_id, 'update-action');

        $inserted_old = '';
        $insert_new = '';

        $filtered_old_data = [];
        $filterd_new_data = [];

        foreach ($new_data as $key => $value) {
            if (array_key_exists($key, $old_data)) {

                $is_term = taxonomy_exists($key);

                if ($old_data[$key] != $value && !$is_term) {
                    $filtered_old_data[$key] = $old_data[$key];
                    $filterd_new_data[$key] = $value;
                } else {
                    foreach ($old_data[$key] as $term) {
                        $term_id = $term->term_id;
                        if (is_array($value)) {
                            foreach ($value as $val) {
                                if ($val != $term_id) {
                                    $filtered_old_data[$key] = $old_data[$key];
                                    $filterd_new_data[$key] = $value;
                                }
                            }
                        } else {
                            if ($value != $term_id) {
                                $filtered_old_data[$key] = $old_data[$key];
                                $filterd_new_data[$key] = $value;
                            }
                        }
                    }
                }

            }
        }


        foreach ($filtered_old_data as $key => $value) {
            if (is_array($value)) {
                $inserted_old .= "<strong>$key:</strong> ";
                $arr_count = count($value);
                foreach ($value as $index => $term_val) {
                    $inserted_old .= "$term_val->name";
                    if ($index < ($arr_count - 1))
                        $inserted_old .= ',';
                }
                $inserted_old .= '<br/>';
            } else {
                $value = $this->formatSpecialValues($key, $value);
                $inserted_old .= "<strong>$key:</strong> $value <br/>";
            }
        }

        foreach ($filterd_new_data as $key => $value) {

            if (is_array($value) || taxonomy_exists($key)) {

                if (is_array($value)) {
                    $insert_new .= "<strong>$key:</strong> ";
                    $arr_count = count($value);
                    foreach ($value as $i => $val) {
                        $term = get_term($value, $key);
                        $insert_new .= "$term->name";
                        if ($i < ($arr_count - 1))
                            $insert_new .= ',';
                    }

                    $insert_new .= '<br/>';

                } else {
                    $term = get_term($value, $key);
                    $insert_new .= "<strong>$key:</strong> $term->name";
                    $insert_new .= '<br/>';
                }

            } else {
                $value = $this->formatSpecialValues($key, $value);
                $insert_new .= "<strong>$key:</strong> $value <br/>";
            }
        }

        update_field('user', $user_id, $id);
        update_field('relation', $relation_id, $id);
        update_field('new_data', $insert_new, $id);
        update_field('old_data', $inserted_old, $id);
        if (isset($new_data['affected_other']))
            update_field('affected_other', $new_data['affected_other'], $id);
    }

    private function construct_object($id)
    {

        $object = [];

        $date = get_the_date('Y/m/d H:i:s', $id);
        $new_data = get_field('new_data', $id);
        $old_data = get_field('old_data', $id);

        $user_id = get_field('user', $id);
        $user = get_user_by('id', $user_id);

        $action = wp_get_post_terms($id, 'update-action', ['fields' => 'names']);
        $action = $action[0];

        $object['date'] = $date . ' - ' . $this->helpersService->timeAgo($date);
        $object['new_data'] = $new_data;
        $object['old_data'] = $old_data;
        $object['user'] = $user->display_name;
        $object['id'] = $id;
        $object['action'] = $action;

        if ($action == 'Linked') {
            unset($object['new_data']);
            unset($object['old_data']);

            $relation = get_field('relation', $id);

            if (is_numeric($relation)) {
                $affected = get_post_type($relation);
                $object['affected'] = $affected;
            }
        }

        return $object;
    }

}