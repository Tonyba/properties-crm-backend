<?php

namespace Inc\Api;

use Inc\Service\HelpersService;


if (!defined('ABSPATH')) {
    die;
}

class DocumentsApi
{

    private $post_type = 'document';
    private $helperService;

    private $expected_body = array(
        array(
            'key' => 'title',
            'required' => true
        ),
        array(
            'key' => 'assigned_to',
            'required' => true,
        ),
        array(
            'key' => 'download_type',
            'required' => false
        )
    );

    public function __construct()
    {
        $this->helperService = new HelpersService();
    }

    public function register()
    {
        add_action('wp_ajax_create_document', array($this, 'create_document'));
        add_action('wp_ajax_nopriv_create_document', array($this, 'create_document'));

        add_action('wp_ajax_document_taxonomies', array($this, 'get_taxonomies'));
        add_action('wp_ajax_nopriv_document_taxonomies', array($this, 'get_taxonomies'));

        add_action('wp_ajax_related_documents', array($this, 'get_related_documents'));
        add_action('wp_ajax_nopriv_related_documents', array($this, 'get_related_documents'));

        add_action('wp_ajax_download_document', array($this, 'handle_document_download'));
        add_action('wp_ajax_nopriv_download_document', array($this, 'handle_document_download'));
    }

    public function get_related_documents()
    {
        $related = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        if (empty($related)) {
            return wp_send_json_error(array(
                'ok' => false,
                'msg' => 'relation is mandatory'
            ));
        }

        $docs = [];

        $args = [
            'post_type' => $this->post_type,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => 'relation',
            'meta_value' => $related
        ];

        $query = new \WP_Query($args);

        foreach ($query->posts as $id) {
            $docs[] = $this->construct_obj($id);
        }

        return wp_send_json($docs);

    }

    public function get_taxonomies()
    {
        return wp_send_json($this->helperService->get_post_types_taxonomies_terms($this->post_type));
    }

    public function create_document()
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
                'msg' => 'error found - document not created'
            ), 400);

        } else {

            $fields['action'] = 'linked';
            $fields['affected_other'] = $fields['relation'];
            do_action('pre_post_update', $id, $fields);

            $internal_id = 0;

            if (!isset($fields['external_url'])) {
                $result = $this->handle_document_upload($id);
                if (!is_numeric($result))
                    return $result;

                $internal_id = $result;

            }

            if (!isset($fields['external_url']))
                $fields['file'] = $internal_id;

            $this->helperService->save_custom_data($id, $fields, $this->post_type);

            $resp = [
                'ok' => true,
                'msg' => 'document created',
                'file' => !isset($fields['external_url']) ? wp_get_attachment_url($result) : $fields['external_url']
            ];


            return wp_send_json($resp, 201);

        }

    }


    private function handle_document_upload($id)
    {
        $upload_dir = wp_upload_dir();
        $protected_dir = $upload_dir['basedir'] . '/protected-documents/';

        // Crear directorio si no existe
        if (!file_exists($protected_dir)) {
            if (!wp_mkdir_p($protected_dir)) {
                return wp_send_json_error('No se pudo crear el directorio protegido');
            }
            // Agregar protección
            file_put_contents($protected_dir . '.htaccess', "Order Deny,Allow\nDeny from all");
            file_put_contents($protected_dir . 'index.html', '');
        }

        // Verificar y procesar la subida
        if (empty($_FILES['uploaded_file']) || $_FILES['uploaded_file']['error'] !== UPLOAD_ERR_OK) {
            return wp_send_json_error('Error uploading file');
        }

        $file = $_FILES['uploaded_file'];

        // ✅ Validar tamaño máximo (5 MB)
        $max_size = 5 * 1024 * 1024; // 5 MB en bytes
        if ($file['size'] > $max_size) {
            return wp_send_json_error('greather than max file size allowed.');
        }

        // Validaciones de seguridad
        $allowed_types = array(
            // Documentos
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

            // Imágenes
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',

            // Videos soportados por WordPress
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',

            // Archivos Excel y CSV
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv'
        );

        $file_info = wp_check_filetype($file['name']);
        if (!array_key_exists($file_info['ext'], $allowed_types)) {
            return wp_send_json_error('file unauthorized');
        }

        // Generar nombre único
        $filename = sanitize_file_name($file['name']);
        $filename = wp_unique_filename($protected_dir, $filename);
        $file_path = $protected_dir . $filename;

        // Verificar que el directorio es escribible
        if (!is_writable($protected_dir)) {
            return wp_send_json_error('El directorio de destino no tiene permisos de escritura');
        }

        // Mover archivo
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            // Crear entrada en la base de datos
            $attachment = array(
                'guid' => home_url('/protected-file/' . $filename),
                'post_mime_type' => $file_info['type'],
                'post_title' => preg_replace('/\.[^.]+$/', '', $file['name']),
                'post_content' => '',
                'post_status' => 'inherit'
            );

            $attach_id = wp_insert_attachment($attachment, $file_path, $id);

            if (is_wp_error($attach_id)) {
                // Si falla la inserción, eliminar el archivo
                unlink($file_path);
                return wp_send_json_error('Error creating file');
            }

            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
            wp_update_attachment_metadata($attach_id, $attach_data);

            return $attach_id;
        }

        return wp_send_json_error('Error moving file');
    }


    function handle_document_download()
    {
        $requested_file = isset($_POST['file_id']) ? sanitize_text_field($_POST['file_id']) : '';

        if (empty($requested_file)) {
            wp_die('File is mandatory', 'Error', ['response' => 400]);
        }


        // Normaliza la ruta y evita Directory Traversal
        $file_path = get_attached_file($requested_file);

        // Validación: el archivo debe existir y estar dentro del directorio base
        if (!$file_path)
            wp_die('not found or unauthorized.', 'Error', ['response' => 404]);


        // Obtiene el tipo MIME
        $file_mime = mime_content_type($file_path);
        $file_name = basename($file_path);

        // Encabezados de descarga
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $file_mime);
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        flush();

        // Envía el archivo
        readfile($file_path);
        exit;
    }

    private function construct_obj($id)
    {
        $obj = [];
        $obj = $this->helperService->get_data($id, $this->post_type);
        $obj['title'] = get_the_title($id);
        $obj['file_id'] = $obj['file'];
        $obj['file'] = wp_get_attachment_url($obj['file']);
        $obj['filename'] = basename(parse_url($obj['file'], PHP_URL_PATH));

        return $obj;
    }
}