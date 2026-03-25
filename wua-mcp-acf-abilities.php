<?php
/**
 * WUA MCP ACF Abilities
 *
 * @package     wua-mcp-abilities
 * Description: Advanced Custom Fields abilities for the WUA MCP plugin.
 *              Requires ACF Pro to be installed and active.
 */

# Table of Contents
# ---------------------------------------------------------
# 1. Register ACF Category
# 2. Register ACF Abilities
#       3.1  acf-create-field-group - Create an ACF field group with fields and location rules
#       3.2  acf-get-field-groups   - List all registered ACF field groups
#       3.3  acf-get-fields         - Get all fields in a specific field group
#       3.4  acf-get-field-value    - Read an ACF field value from a post
#       3.5  acf-update-field-value          - Write an ACF field value to a post
#       3.6  acf-update-local-json-field-group - Add or update fields in an existing ACF Local JSON field group
# ---------------------------------------------------------


# Register ACF Category
add_action( 'wp_abilities_api_categories_init', function() {

    wp_register_ability_category( 'wua-mcp-acf', [
        'label'       => 'WUA MCP ACF Abilities',
        'description' => 'Advanced Custom Fields abilities for WUA MCP integration',
    ]);

});


# Register ACF Abilities
add_action( 'wp_abilities_api_init', function() {

    // Bail early if ACF is not active
    if ( ! function_exists( 'acf_add_local_field_group' ) ) {
        return;
    }

    // -------------------------------------------------------------------------
    // TOOL 3.1: Create ACF Field Group
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-create-field-group', [
        'label'       => 'ACF Create Field Group',
        'description' => 'Create an ACF field group with fields and location rules. Supports all ACF Pro field types.',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'title'    => [ 'type' => 'string', 'description' => 'Field group title' ],
                'key'      => [ 'type' => 'string', 'description' => 'Unique key. Must start with "group_".' ],
                'fields'   => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'location' => [ 'type' => 'array', 'items' => [ 'type' => 'array' ] ],
                'position'        => [ 'type' => 'string' ],
                'style'           => [ 'type' => 'string' ],
                'label_placement' => [ 'type' => 'string' ],
                'menu_order'      => [ 'type' => 'integer' ],
                'active'          => [ 'type' => 'integer' ],
            ],
            'required' => [ 'title', 'key', 'fields', 'location' ],
        ],
        'execute_callback' => function( $input ) {
            if ( ! function_exists( 'acf_add_local_field_group' ) ) {
                return new WP_Error( 'acf_missing', 'ACF Pro is not active.' );
            }
            $field_group = [
                'key'             => sanitize_text_field( $input['key'] ),
                'title'           => sanitize_text_field( $input['title'] ),
                'fields'          => $input['fields'] ?? [],
                'location'        => $input['location'] ?? [],
                'position'        => $input['position'] ?? 'normal',
                'style'           => $input['style'] ?? 'default',
                'label_placement' => $input['label_placement'] ?? 'top',
                'menu_order'      => $input['menu_order'] ?? 0,
                'active'          => $input['active'] ?? 1,
            ];
            $result = acf_update_field_group( $field_group );
            if ( ! $result ) return new WP_Error( 'acf_save_failed', 'Failed to save the ACF field group.' );
            return [ 'created' => true, 'key' => $field_group['key'], 'title' => $field_group['title'], 'field_count' => count( $field_group['fields'] ) ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // TOOL 3.2: Get ACF Field Groups
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-get-field-groups', [
        'label'       => 'ACF Get Field Groups',
        'description' => 'List all registered ACF field groups',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [ 'type' => 'object', 'properties' => [ 'active' => [ 'type' => 'integer' ] ] ],
        'execute_callback' => function( $input ) {
            $args = [];
            if ( isset( $input['active'] ) ) $args['active'] = (int) $input['active'];
            $groups = acf_get_field_groups( $args );
            return array_map( fn( $g ) => [ 'key' => $g['key'], 'title' => $g['title'], 'active' => $g['active'], 'position' => $g['position'], 'menu_order' => $g['menu_order'], 'location' => $g['location'] ], $groups );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // TOOL 3.3: Get ACF Fields
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-get-fields', [
        'label'       => 'ACF Get Fields',
        'description' => 'Get all fields in a specific ACF field group',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [ 'type' => 'object', 'properties' => [ 'group_key' => [ 'type' => 'string' ] ], 'required' => [ 'group_key' ] ],
        'execute_callback' => function( $input ) {
            $fields = acf_get_fields( sanitize_text_field( $input['group_key'] ) );
            if ( ! $fields ) return new WP_Error( 'not_found', 'No fields found for this field group key.' );
            return array_map( fn( $f ) => [ 'key' => $f['key'], 'label' => $f['label'], 'name' => $f['name'], 'type' => $f['type'], 'required' => $f['required'], 'default_value' => $f['default_value'] ?? '', 'instructions' => $f['instructions'] ?? '' ], $fields );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // TOOL 3.4: Get ACF Field Value
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-get-field-value', [
        'label'       => 'ACF Get Field Value',
        'description' => 'Read an ACF field value from a post, page, user, term, or options page',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [ 'type' => 'object', 'properties' => [ 'field_name' => [ 'type' => 'string' ], 'post_id' => [ 'type' => 'string' ] ], 'required' => [ 'field_name', 'post_id' ] ],
        'execute_callback' => function( $input ) {
            $field_name = sanitize_text_field( $input['field_name'] );
            $post_id    = sanitize_text_field( $input['post_id'] );
            $value = get_field( $field_name, $post_id );
            return [ 'field_name' => $field_name, 'post_id' => $post_id, 'value' => $value ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // TOOL 3.5: Update ACF Field Value
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-update-field-value', [
        'label'       => 'ACF Update Field Value',
        'description' => 'Write an ACF field value to a post, page, user, term, or options page. For image fields, pass the attachment ID (integer) as the value. Use search-media to find attachment IDs for images already in the media library.',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [ 'type' => 'object', 'properties' => [ 'field_name' => [ 'type' => 'string' ], 'value' => [ 'type' => 'string' ], 'post_id' => [ 'type' => 'string' ], 'field_type' => [ 'type' => 'string' ] ], 'required' => [ 'field_name', 'value', 'post_id' ] ],
        'execute_callback' => function( $input ) {
            $field_name = sanitize_text_field( $input['field_name'] );
            $post_id    = sanitize_text_field( $input['post_id'] );
            $value      = $input['value'];
            if ( ! empty( $input['field_type'] ) && $input['field_type'] === 'image' ) {
                $value = (int) $value;
                $attachment = get_post( $value );
                if ( ! $attachment || $attachment->post_type !== 'attachment' ) return new WP_Error( 'attachment_not_found', 'Attachment ID ' . $value . ' not found in the media library.' );
            }
            $result = update_field( $field_name, $value, $post_id );
            return [ 'updated' => (bool) $result, 'field_name' => $field_name, 'post_id' => $post_id ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // TOOL 3.6: Update ACF Local JSON Field Group
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-update-local-json-field-group', [
        'label'       => 'ACF Update Local JSON Field Group',
        'description' => 'Add or update fields in an existing ACF field group that is saved as Local JSON. Reads the existing JSON file, merges in new or updated fields, and saves it back. Use acf-get-fields first to retrieve existing fields so they can be preserved.',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [ 'type' => 'object', 'properties' => [ 'group_key' => [ 'type' => 'string' ], 'new_fields' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ] ], 'required' => [ 'group_key', 'new_fields' ] ],
        'execute_callback' => function( $input ) {
            $group_key = sanitize_text_field( $input['group_key'] );
            $stylesheet = get_option( 'stylesheet' );
            $template   = get_option( 'template' );
            $possible_paths = [
                WP_CONTENT_DIR . '/themes/' . $stylesheet . '/acf-json',
                WP_CONTENT_DIR . '/themes/' . $template . '/acf-json',
                WP_CONTENT_DIR . '/themes/' . $stylesheet,
                WP_CONTENT_DIR . '/themes/' . $template,
            ];
            $acf_load_paths = apply_filters( 'acf/settings/load_json', [] );
            if ( is_array( $acf_load_paths ) ) $possible_paths = array_merge( $acf_load_paths, $possible_paths );
            $json_file = null;
            foreach ( $possible_paths as $path ) {
                $candidate = trailingslashit( $path ) . $group_key . '.json';
                if ( file_exists( $candidate ) ) { $json_file = $candidate; break; }
            }
            if ( ! $json_file ) return new WP_Error( 'json_not_found', 'ACF Local JSON file not found.' );
            $json_contents = file_get_contents( $json_file );
            $group = json_decode( $json_contents, true );
            if ( ! $group ) return new WP_Error( 'json_parse_error', 'Failed to parse ACF JSON file.' );
            $existing_keys = array_column( $group['fields'] ?? [], 'key' );
            foreach ( $input['new_fields'] as $new_field ) {
                $existing_index = array_search( $new_field['key'], $existing_keys );
                if ( $existing_index !== false ) { $group['fields'][ $existing_index ] = $new_field; } else { $group['fields'][] = $new_field; }
            }
            $group['modified'] = time();
            $result = file_put_contents( $json_file, json_encode( $group, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
            if ( $result === false ) return new WP_Error( 'json_write_error', 'Failed to write ACF JSON file.' );
            if ( function_exists( 'acf_update_field_group' ) ) {
                acf_update_field_group( $group );
                $sync_fields = function( $fields, $parent_id ) use ( &$sync_fields ) {
                    foreach ( $fields as $field ) {
                        $field['parent'] = $parent_id;
                        acf_update_field( $field );
                        if ( ! empty( $field['sub_fields'] ) ) $sync_fields( $field['sub_fields'], $field['key'] );
                        if ( ! empty( $field['layouts'] ) ) { foreach ( $field['layouts'] as $layout ) { if ( ! empty( $layout['sub_fields'] ) ) $sync_fields( $layout['sub_fields'], $layout['key'] ); } }
                    }
                };
                $sync_fields( $group['fields'], $group['key'] );
            }
            return [ 'updated' => true, 'group_key' => $group_key, 'group_title' => $group['title'], 'total_fields' => count( $group['fields'] ), 'new_fields' => count( $input['new_fields'] ), 'json_file' => $json_file ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

});
