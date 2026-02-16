<?php

namespace Inc\Service;

use Inc\Api\ContactsApi;
use Inc\Api\LeadsApi;
use Inc\Api\OpportunitiesApi;


class HelpersService
{

    private $email_body = [
        'subject',
        'to',
        'content'
    ];

    public function register()
    {
        add_action('wp_ajax_trash_item', array($this, 'delete_item'));
        add_action('wp_ajax_nopriv_trash_item', array($this, 'delete_item'));

        add_action('wp_ajax_get_emails', array($this, 'get_emails'));
        add_action('wp_ajax_nopriv_get_emails', array($this, 'get_emails'));

        add_action('wp_ajax_import_data', array($this, 'import_data'));
        add_action('wp_ajax_nopriv_import_data', array($this, 'import_data'));
    }


    public function get_emails()
    {
        $related = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
        $filters = $this->sanitize_fields($_POST['filters']);

        $row = isset($_POST['page']) && is_numeric($_POST['page']) ? intval($_POST['page']) : 1;
        $row_per_page = isset($_POST['perPage']) && is_numeric($_POST['perPage']) ? intval($_POST['perPage']) : 20;

        if (empty($related)) {
            return wp_send_json_error(array(
                'ok' => false,
                'msg' => 'relation is mandatory'
            ));
        }

        $limit = $row_per_page;
        $offset = ($row - 1) * $limit;

        // Primero obtener todos los submissions con el related_id
        $baseQuery = wpFluent()->table('fluentform_entry_details')
            ->select('submission_id')
            ->where('field_name', 'related_id')
            ->where('field_value', '=', $related)
            ->groupBy('submission_id');

        // Si hay filtros, usar subconsultas
        if (!empty($filters)) {



            foreach ($filters as $field_name => $field_value) {

                if ($field_name === 'created_at') {
                    // Manejar filtro de fecha por separado
                    continue;
                }

                if (!empty($field_value) && in_array($field_name, $this->email_body)) {
                    $baseQuery->whereIn('submission_id', function ($q) use ($field_name, $field_value) {
                        $q->select('submission_id')
                            ->from('fluentform_entry_details')
                            ->where('field_name', $field_name)
                            ->where('field_value', 'LIKE', "%{$field_value}%");
                    });
                }
            }
        }

        // MANEJAR FILTRO DE FECHA created_at
        if (isset($filters['created_at']) && is_array($filters['created_at'])) {
            $dateFilter = $filters['created_at'];

            // Verificar si tenemos start y end
            if (isset($dateFilter['start']) && isset($dateFilter['end'])) {
                $startDate = $this->formatDateFromFilter($dateFilter['start']);
                $endDate = $this->formatDateFromFilter($dateFilter['end']);

                if ($startDate && $endDate) {
                    // Agregar rango de fecha a la consulta
                    $baseQuery->whereIn('submission_id', function ($q) use ($startDate, $endDate) {
                        $q->select('id')
                            ->from('fluentform_submissions')
                            ->whereBetween('created_at', [$startDate, $endDate]);
                    });
                }
            }
        }

        // Clonar para contar
        $countQuery = clone $baseQuery;
        $totalRecords = count($countQuery->get());

        // Aplicar paginación
        $submissionIdsResult = $baseQuery->orderBy('submission_id', 'DESC')
            ->skip($offset)
            ->take($limit)
            ->get();

        // Extraer los IDs manualmente
        $submissionIds = [];
        foreach ($submissionIdsResult as $item) {
            $submissionIds[] = $item->submission_id;
        }

        $emails = [];

        if (!empty($submissionIds)) {
            // Obtener todos los datos de estos submissions
            $allDetails = wpFluent()->table('fluentform_entry_details')
                ->whereIn('submission_id', $submissionIds)
                ->get();

            // Obtener fechas de creación
            $submissions = wpFluent()->table('fluentform_submissions')
                ->select(['id', 'created_at'])
                ->whereIn('id', $submissionIds)
                ->get();

            $submissionDates = [];
            foreach ($submissions as $sub) {
                $submissionDates[$sub->id] = $sub->created_at;
            }

            // Organizar datos por submission_id
            $organized = [];
            foreach ($allDetails as $detail) {
                $subId = $detail->submission_id;
                if (!isset($organized[$subId])) {
                    $organized[$subId] = [];
                }
                $organized[$subId][$detail->field_name] = $detail->field_value;
            }

            // Formatear respuesta
            foreach ($organized as $subId => $data) {
                $email = ['submission_id' => $subId];

                // Agregar campos del email_body
                foreach ($this->email_body as $key) {
                    $email[$key] = isset($data[$key]) ? $data[$key] : '';
                }

                // Agregar fecha
                if (isset($submissionDates[$subId])) {
                    $date = new \DateTime($submissionDates[$subId]);
                    $email['created_at'] = $date->format('d/m/Y');
                } else {
                    $email['created_at'] = '';
                }

                $emails[] = $email;
            }
        }

        return wp_send_json([
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecords,
            'data' => $emails,
        ]);
    }

    public function import_data()
    {

        $data_arr = isset($_POST['data']) ? $this->sanitize_fields($_POST['data']) : [];
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';

        $error_messages = [];
        $imported = 0;
        $duplicated = 0;
        $failed = 0;

        if (!empty($data_arr) && $post_type) {

            $agents = get_users(array('role' => 'agent'));

            foreach ($data_arr as $data) {

                $data['assigned_to'] = $agents[0]->ID;
                $email = $data['email'];
                $exists = $this->check_existence_by_email($email, $post_type);

                if (!$exists) {
                    $args = array(
                        'post_title' => $post_type . '_' . wp_generate_uuid4(),
                        'post_status' => 'publish',
                        'post_type' => $post_type,
                        'post_author' => get_current_user_id()
                    );

                    $id = wp_insert_post($args);

                    if (is_wp_error($id)) {

                        $failed++;
                        $error_messages[] = [
                            'resp' => array(
                                'ok' => false,
                                'msg' => "error found - $post_type not created with email: $email"
                            ),
                            'code' => 400
                        ];

                    } else {

                        $select_taxonomies = $this->assign_right_taxonomies($post_type);

                        $this->save_custom_data($id, $data, $post_type, $select_taxonomies);

                        $imported++;
                    }

                } else {
                    $duplicated++;
                    $error_messages[] = [
                        'resp' => array(
                            'ok' => false,
                            'msg' => "$post_type duplicated - with email: $email"
                        ),
                        'code' => 400
                    ];
                }
            }

            $resp = [
                'ok' => true,
                'msg' => "import processed",
                'errors' => $error_messages,
                'duplicated' => $duplicated,
                'imported' => $imported,
                'failed' => $failed
            ];

            return wp_send_json($resp, 201);

        } else {
            return wp_send_json(array(
                'ok' => false,
                'msg' => "invalid data"
            ), 400);
        }


    }

    private function assign_right_taxonomies($post_type)
    {

        $taxonomies = [];


        switch ($post_type) {
            case 'lead':
                $taxonomies = LeadsApi::get_type_taxonomies();
                break;

            case 'contact':
                $taxonomies = ContactsApi::get_type_taxonomies();
                break;

            case 'opportunity':
                $taxonomies = OpportunitiesApi::get_type_taxonomies();
                break;

            default:
                # code...
                break;
        }


        return $taxonomies;

    }

    public function check_existence_by_email($email, $post_type)
    {

        $args = [
            'post_type' => $post_type,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => 'email',
            'meta_value' => $email,
            'no_found_rows' => true
        ];

        $query = new \WP_Query($args);
        $items = $query->posts;

        return !empty($items);
    }


    public function formatDateFromFilter($dateArray)
    {
        if (!is_array($dateArray) || empty($dateArray)) {
            return null;
        }

        $day = isset($dateArray['day']) ? intval($dateArray['day']) : 1;
        $month = isset($dateArray['month']) ? intval($dateArray['month']) : 1;
        $year = isset($dateArray['year']) ? intval($dateArray['year']) : date('Y');

        // Validar que los valores sean válidos
        if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || $year < 1900 || $year > 2100) {
            return null;
        }

        // Crear fecha en formato Y-m-d
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
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

    public function update_custom_data($id, $fields, $post_type, $specific_taxonomies = [], $append_taxonomies = false)
    {
        if (isset($fields['title'])) {
            $new_data = array(
                'ID' => $id,
                'post_title' => $fields['title'],
            );

            wp_update_post($new_data);
        }

        $this->save_custom_data($id, $fields, $post_type, $specific_taxonomies, $append_taxonomies);
    }

    public function save_custom_data($id, $fields, $post_type, $specific_taxonomies = [], $append_taxonomies = false)
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

        if (!empty($specific_taxonomies) && !$append_taxonomies) {
            foreach ($specific_taxonomies as $taxonomy) {
                $taxonomies_arr[] = $taxonomy;
            }
        } else {
            $taxonomies = get_taxonomies(['object_type' => [$post_type]]);

            foreach ($specific_taxonomies as $specific_taxonomy) {
                if (!in_array($specific_taxonomy, $taxonomies)) {
                    $taxonomies[] = $specific_taxonomy;
                }
            }

            foreach ($taxonomies as $taxonomy => $value) {
                $taxonomies_arr[] = $value;
            }
        }

        foreach ($fields as $item => $value) {
            if (in_array($item, $taxonomies_arr) && ($value && !empty($value))) {

                $value_to_save = $value;

                if (is_array($value) && !is_numeric($value[0])) {

                    $value_to_save = [];
                    foreach ($value as $item_value) {
                        $value_to_save[] = $this->get_tax_by_search($item_value, $item);
                    }

                } else if (!is_array($value) && !is_numeric($value)) {
                    $value_to_save = $this->get_tax_by_search($item_value, $item);
                }

                wp_set_post_terms($id, $value_to_save, $item);

            }

            if (in_array($item, $taxonomies_arr) && is_array($value) && empty($value)) {
                wp_set_post_terms($id, [], $item);
            }

            if (in_array($item, $acf_fields) && ($value && !empty($value))) {
                if ($item == 'from' || $item == 'to') {
                    $date_object = \DateTime::createFromFormat('d/m/Y H:i', $value);
                    if ($date_object)
                        $value = $date_object->format('Y-m-d H:i:s');
                } else if ($item == 'birthdate' || $item == 'close_date') {
                    $date_object = \DateTime::createFromFormat('Y-m-d', $value);
                    if ($date_object)
                        $value = $date_object->format('d/m/Y');
                }
                update_field($item, $value, $id);
            }

            if (in_array($item, $acf_fields) && is_array($value) && empty($value)) {
                update_field($item, [], $id);
            }
        }
    }

    private function get_tax_by_search($search_text, $taxonomy_slug = 'category')
    {
        $args = array(
            'taxonomy' => $taxonomy_slug, // Specify the taxonomy (e.g., 'category', 'post_tag', or custom)
            'orderby' => 'name',
            'order' => 'ASC',
            'hide_empty' => false,
            'fields' => 'ids',
            'name__like' => $search_text // Search for terms with names like the $search_text
        );

        $terms = get_terms($args);

        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0];
        } else {
            return 0;
        }
    }

    // Example usage with a search term:
// get_tax_by_search('fiction', 'genre');


    private function format_date($value)
    {
        $day = $value['day'];
        $year = $value['year'];
        $month = $value['month'];

        $dateObject = \DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day));

        if ($dateObject) {
            return $dateObject->format('Y-m-d');
        }
    }

    public function get_post_types_taxonomies_terms($post_type, $specific_taxonomies = [], $append = false)
    {
        $data = [];


        if (!empty($specific_taxonomies) && !$append) {
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

            if ($append) {
                foreach ($specific_taxonomies as $specific_taxonomy) {
                    if (!in_array($specific_taxonomy, $taxonomies)) {
                        $taxonomies[] = $specific_taxonomy;
                    }
                }
            }

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