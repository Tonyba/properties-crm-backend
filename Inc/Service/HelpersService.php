<?php

namespace Inc\Service;

class HelpersService
{
    public function register()
    {
        add_action('wp_ajax_trash_item', array($this, 'delete_item'));
        add_action('wp_ajax_nopriv_trash_item', array($this, 'delete_item'));
    }

    public function delete_item()
    {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $relation = isset($_POST['relation']) ? intval($_POST['relation']) : 0;

        if ($id && $relation) {

            $fields = array(
                'action' => 'trashed',
                'affected_other' => $relation,
            );

            if ($relation == 0) {
                $search_relation = get_field('relation', $id);
                if ($search_relation && $search_relation != 0)
                    $fields['affected_other'] = $search_relation;
            }

            do_action('pre_post_update', $id, $fields);
            var_dump($fields);
            wp_trash_post($id);
            wp_send_json_success(['ok' => true, 'msg' => 'Deleted successfully']);
        } else {
            wp_send_json_error(['ok' => false, 'msg' => 'Invalid Request']);
        }

        wp_die();
    }

    public function timeAgo($datetime)
    {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return $diff . " seconds ago";
        } elseif ($diff < 3600) {
            return floor($diff / 60) . " minutes ago";
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . " hours ago";
        } elseif ($diff < 2592000) {
            return floor($diff / 86400) . " days ago";
        } elseif ($diff < 31536000) {
            return floor($diff / 2592000) . " months ago";
        } else {
            return floor($diff / 31536000) . " years ago";
        }
    }

    public function check_body($body, $expected_body)
    {
        $is_valid = true;

        foreach ($expected_body as $expected) {
            $expected_key = $expected['key'];
            $is_required = $expected['required'];

            if (
                (!isset($body[$expected_key]) || empty($body[$expected_key])) && $is_required
            ) {
                $is_valid = false;
                break;
            }
        }


        return $is_valid;
    }

    public function sanitize_fields($fields)
    {
        $fields = isset($fields) ? wp_unslash($fields) : '';
        $fields = json_decode($fields, true);

        $fields = sanitize_associative_array($fields);

        return $fields;
    }

    public function get_data($id, $post_type, $with_tax = false)
    {

        $data = [];

        $data['id'] = $id;

        $field_groups = acf_get_field_groups([
            'post_type' => $post_type
        ]);


        $acf_fields = [];
        foreach ($field_groups as $group) {
            $acf_fields = acf_get_fields($group['key']);
        }

        $acf_fields = wp_list_pluck($acf_fields, 'name');

        $taxonomies = get_taxonomies(['object_type' => [$post_type]]);
        $taxonomies_arr = [];

        foreach ($taxonomies as $taxonomy => $value) {
            $taxonomies_arr[] = $value;
        }

        foreach ($acf_fields as $acf_key) {
            $data[$acf_key] = get_field($acf_key, $id);
        }

        if ($with_tax) {
            foreach ($taxonomies_arr as $taxonomy) {
                $terms = wp_get_post_terms($id, $taxonomy, ['fields' => 'names']);
                $data[$taxonomy] = $terms;
            }
        }

        return $data;
    }

    public function update_custom_data($id, $fields, $post_type)
    {
        if (isset($fields['title'])) {
            $new_data = array(
                'ID' => $id,
                'post_title' => $fields['title'],
            );

            wp_update_post($new_data);
        }

        $this->save_custom_data($id, $fields, $post_type);
    }

    public function save_custom_data($id, $fields, $post_type, $specific_taxonomies = [])
    {
        $field_groups = acf_get_field_groups([
            'post_type' => $post_type
        ]);

        $acf_fields = [];
        foreach ($field_groups as $group) {
            $acf_fields = acf_get_fields($group['key']);
        }

        $acf_fields = wp_list_pluck($acf_fields, 'name');

        $taxonomies = [];
        $taxonomies_arr = [];

        if (!empty($specific_taxonomies)) {
            foreach ($specific_taxonomies as $taxonomy) {
                $taxonomies_arr[] = $taxonomy;
            }
        } else {
            $taxonomies = get_taxonomies(['object_type' => [$post_type]]);
            foreach ($taxonomies as $taxonomy => $value) {
                $taxonomies_arr[] = $value;
            }
        }

        foreach ($fields as $item => $value) {
            if (in_array($item, $taxonomies_arr) && ($value && !empty($value))) {
                wp_set_post_terms($id, $value, $item);
            }


            if (in_array($item, $acf_fields) && ($value && !empty($value))) {
                if ($item == 'from' || $item == 'to') {
                    $date_object = \DateTime::createFromFormat('d/m/Y H:i', $value);
                    if ($date_object)
                        $value = $date_object->format('Y-m-d H:i:s');
                } else if ($item == 'birthdate') {
                    $date_object = \DateTime::createFromFormat('Y-m-d', $value);
                    if ($date_object)
                        $value = $date_object->format('d/m/Y');
                }
                update_field($item, $value, $id);
            }
        }
    }

    public function get_post_types_taxonomies_terms($post_type, $specific_taxonomies = [])
    {
        $data = [];


        if (!empty($specific_taxonomies)) {
            foreach ($specific_taxonomies as $taxonomy) {
                $terms = get_terms([
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'fields' => 'id=>name'
                ]);

                $formatted = [];

                foreach ($terms as $term_id => $term_name) {
                    $formatted[] = [
                        'label' => $term_name,
                        'value' => $term_id
                    ];
                }

                $data[$taxonomy] = $formatted;
            }

        } else {
            $taxonomies = get_taxonomies(['object_type' => [$post_type]]);
            foreach ($taxonomies as $taxonomy => $value) {
                $terms = get_terms([
                    'taxonomy' => $value,
                    'hide_empty' => false,
                    'fields' => 'id=>name'
                ]);

                $formatted = [];

                foreach ($terms as $term_id => $term_name) {
                    $formatted[] = [
                        'label' => $term_name,
                        'value' => $term_id
                    ];
                }

                $data[$value] = $formatted;
            }
        }



        return $data;
    }

}