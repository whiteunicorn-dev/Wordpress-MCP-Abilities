<?php
/**
 * WUA MCP Abilities
 *
 * @package     wua-mcp-abilities
 * Plugin Name: WUA MCP Abilities
 * Description: Exposes abilities to AI via MCP. Merged best-of from v2.2 and v2.6.
 * Version: 2.10
 *
 * Changelog:
 * 2.10 - acf-create-field-group now writes Local JSON (fields included) instead of calling acf_update_field_group() alone, which created the group but saved none of its fields. fs-write-file now creates missing parent directories (wp_mkdir_p) and rejects ".." path traversal.
 * 2.9 - acf-update-local-json-field-group now writes Local JSON only. Removed the acf_update_field_group()/acf_update_field() DB sync that (1) let ACF's save hook overwrite the just-written JSON file, dropping new fields, and (2) created duplicate acf-field-group DB records.
 * 2.8 - Fix filesystem write/delete path guard on Windows (normalize paths with wp_normalize_path).
 */

# =============================================================================
# Table of Contents
# =============================================================================
# 1. CORS & OPTIONS Headers
# 2. Required Files
# 3. Register Category
# 4. Register Abilities
#
#       CONTENT MANAGEMENT
#         4.1   create-post              - Create a new post (with optional ACF fields)
#         4.2   get-posts                - List recent posts (supports search + status)
#         4.3   get-post                 - Fetch a post by id or slug
#         4.4   update-post              - Edit title/content/status/ACF fields
#         4.5   delete-post              - Trash a post
#         4.6   get-pages                - List pages
#
#       POST META
#         4.7   get-post-meta            - Read a single key or all meta
#         4.8   update-post-meta         - Write a single meta key
#         4.9   bulk-update-post-meta    - Apply mapped meta updates across many posts
#         4.10  search-post-meta         - Search meta values for a string (find content)
#         4.11  update-post-meta-raw     - Raw DB write (bypasses WP serialization; ACF flex)
#         4.12  copy-post-meta           - Raw DB copy of meta from one post to another
#
#       USER MANAGEMENT
#         4.13  get-users                - List users
#         4.14  create-user              - Add a user
#         4.15  update-user-role         - Change a user's role
#
#       SITE HEALTH & INFO
#         4.16  get-site-info            - Site name, URL, WP version, active theme
#         4.17  get-active-plugins       - List active plugins
#         4.18  get-options              - Read WP options by key
#         4.19  get-post-types           - List registered post types
#
#       MEDIA LIBRARY
#         4.20  search-media             - Search media library
#         4.21  get-media-missing-alt    - Find images missing alt text
#         4.22  get-image-thumbnail      - Base64 thumbnail (jpg/png/webp/gif + svg)
#         4.23  set-featured-image       - Set featured image with validation
#         4.24  media-upload-from-url    - Sideload one or more images from URLs
#         4.25  media-upload-base64      - Upload a base64 image
#         4.26  generate-alt-text        - Generate alt text via Claude API
#
#       TAXONOMIES
#         4.27  create-term              - Create a taxonomy term (skips on duplicate slug)
#         4.28  get-terms                - List terms for a taxonomy
#         4.29  set-post-terms           - Assign terms to a post (ids or slugs)
#
#       MAINTENANCE
#         4.30  clear-cache              - Flush WP Rocket / W3TC / WP Super Cache / Object
#         4.31  run-wp-cron              - Trigger WP-Cron events
# =============================================================================


# =============================================================================
# 1. CORS & OPTIONS Headers
# =============================================================================

# Allow requests from local file:// origins
add_action( 'rest_api_init', function() {
    remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
    add_filter( 'rest_pre_serve_request', function( $value ) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ( $origin === 'null' || strpos( $origin, 'http://localhost' ) === 0 || strpos( $origin, 'https://localhost' ) === 0 ) {
            header( 'Access-Control-Allow-Origin: ' . ( $origin ?: 'null' ) );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, Content-Disposition' );
        } else {
            header( 'Access-Control-Allow-Origin: *' );
        }
        return $value;
    });
}, 15 );

add_action( 'init', function() {
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ( $origin === 'null' || strpos( $origin, 'http://localhost' ) === 0 ) {
            header( 'Access-Control-Allow-Origin: ' . ( $origin ?: 'null' ) );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, Content-Disposition' );
            header( 'HTTP/1.1 200 OK' );
            exit();
        }
    }
});


# =============================================================================
# 2. Required Files
# =============================================================================

require_once plugin_dir_path( __FILE__ ) . 'wua-mcp-acf-abilities.php';
require_once plugin_dir_path( __FILE__ ) . 'wua-mcp-filesystem-abilities.php';


# =============================================================================
# 3. Register Category
# =============================================================================

add_action( 'wp_abilities_api_categories_init', function() {
    wp_register_ability_category( 'wua-mcp-abilities', [
        'label'       => 'WUA MCP Abilities',
        'description' => 'Custom abilities for WUA MCP integration',
    ]);
});


# =============================================================================
# 4. Register Abilities
# =============================================================================

add_action( 'wp_abilities_api_init', function() {

    // -------------------------------------------------------------------------
    // 4.1 Create Post
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/create-post', [
        'label'       => 'Create Post',
        'description' => 'Create a new WordPress post, page, or custom post type entry. Optionally set ACF custom field values in the same call.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'title'     => [ 'type' => 'string' ],
                'content'   => [ 'type' => 'string' ],
                'status'    => [ 'type' => 'string', 'default' => 'draft' ],
                'post_type' => [ 'type' => 'string', 'default' => 'post' ],
                'fields'    => [ 'type' => 'object', 'description' => 'Optional map of ACF field_name => value pairs to set after insert.' ],
            ],
            'required' => [ 'title', 'content' ],
        ],
        'execute_callback' => function( $input ) {
            $post_id = wp_insert_post([
                'post_title'   => sanitize_text_field( $input['title'] ),
                'post_content' => wp_kses_post( $input['content'] ),
                'post_status'  => $input['status'] ?? 'draft',
                'post_type'    => ! empty( $input['post_type'] ) ? sanitize_text_field( $input['post_type'] ) : 'post',
            ]);
            if ( is_wp_error( $post_id ) ) return $post_id;
            $fields_updated = [];
            if ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) && function_exists( 'update_field' ) ) {
                foreach ( $input['fields'] as $field_name => $value ) {
                    update_field( sanitize_text_field( $field_name ), $value, $post_id );
                    $fields_updated[] = $field_name;
                }
            }
            return [ 'id' => $post_id, 'fields_updated' => $fields_updated ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.2 Get Posts
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-posts', [
        'label'       => 'Get Recent Posts',
        'description' => 'Retrieve a list of recent posts. Supports custom post types, search term, and status filter.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'count'       => [ 'type' => 'integer', 'default' => 100 ],
                'post_type'   => [ 'type' => 'string',  'default' => 'post' ],
                'search'      => [ 'type' => 'string',  'description' => 'Optional search term' ],
                'post_status' => [ 'type' => 'string',  'default' => 'any' ],
            ],
        ],
        'execute_callback' => function( $input ) {
            $args = [
                'numberposts' => $input['count'] ?? 100,
                'post_type'   => ! empty( $input['post_type'] ) ? sanitize_text_field( $input['post_type'] ) : 'post',
                'post_status' => ! empty( $input['post_status'] ) ? sanitize_text_field( $input['post_status'] ) : 'any',
            ];
            if ( ! empty( $input['search'] ) ) $args['s'] = sanitize_text_field( $input['search'] );
            $posts = get_posts( $args );
            return array_map( fn( $p ) => [
                'id'        => $p->ID,
                'title'     => $p->post_title,
                'status'    => $p->post_status,
                'post_type' => $p->post_type,
                'url'       => get_permalink( $p->ID ),
            ], $posts );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.3 Get Post
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-post', [
        'label'       => 'Get Post',
        'description' => 'Fetch a single post by ID or slug. Supports custom post types. Optionally include named ACF field values.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'        => [ 'type' => 'integer' ],
                'slug'      => [ 'type' => 'string' ],
                'post_type' => [ 'type' => 'string', 'default' => 'post' ],
                'fields'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Optional list of ACF field names to also return.' ],
            ],
        ],
        'execute_callback' => function( $input ) {
            if ( ! empty( $input['id'] ) ) {
                $post = get_post( $input['id'] );
            } elseif ( ! empty( $input['slug'] ) ) {
                $posts = get_posts([
                    'name'        => sanitize_title( $input['slug'] ),
                    'post_type'   => ! empty( $input['post_type'] ) ? sanitize_text_field( $input['post_type'] ) : 'post',
                    'post_status' => 'any',
                    'numberposts' => 1,
                ]);
                $post = $posts[0] ?? null;
            } else {
                return new WP_Error( 'missing_param', 'Provide either an id or a slug.' );
            }
            if ( ! $post ) return new WP_Error( 'not_found', 'Post not found.' );
            $result = [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'content' => $post->post_content,
                'status'  => $post->post_status,
                'type'    => $post->post_type,
                'url'     => get_permalink( $post->ID ),
                'date'    => $post->post_date,
            ];
            if ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) && function_exists( 'get_field' ) ) {
                $result['fields'] = [];
                foreach ( $input['fields'] as $field_name ) {
                    $result['fields'][ sanitize_text_field( $field_name ) ] = get_field( sanitize_text_field( $field_name ), $post->ID );
                }
            }
            return $result;
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.4 Update Post
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/update-post', [
        'label'       => 'Update Post',
        'description' => 'Edit the title, content, status, or ACF custom fields of an existing post.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'      => [ 'type' => 'integer' ],
                'title'   => [ 'type' => 'string' ],
                'content' => [ 'type' => 'string' ],
                'status'  => [ 'type' => 'string' ],
                'fields'  => [ 'type' => 'object', 'description' => 'Optional map of ACF field_name => value pairs to update.' ],
            ],
            'required' => [ 'id' ],
        ],
        'execute_callback' => function( $input ) {
            $args = [ 'ID' => $input['id'] ];
            if ( ! empty( $input['title'] ) )   $args['post_title']   = sanitize_text_field( $input['title'] );
            if ( ! empty( $input['content'] ) ) $args['post_content'] = wp_kses_post( $input['content'] );
            if ( ! empty( $input['status'] ) )  $args['post_status']  = sanitize_text_field( $input['status'] );
            $result = wp_update_post( $args, true );
            if ( is_wp_error( $result ) ) return $result;
            $fields_updated = [];
            if ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) && function_exists( 'update_field' ) ) {
                foreach ( $input['fields'] as $field_name => $value ) {
                    update_field( sanitize_text_field( $field_name ), $value, $result );
                    $fields_updated[] = $field_name;
                }
            }
            return [ 'updated' => true, 'id' => $result, 'fields_updated' => $fields_updated ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.5 Delete Post
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/delete-post', [
        'label'       => 'Delete Post',
        'description' => 'Move a post to trash.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'id' => [ 'type' => 'integer' ] ],
            'required'   => [ 'id' ],
        ],
        'execute_callback' => function( $input ) {
            $result = wp_trash_post( $input['id'] );
            if ( ! $result ) return new WP_Error( 'trash_failed', 'Could not trash the post.' );
            return [ 'trashed' => true, 'id' => $input['id'] ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.6 Get Pages
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-pages', [
        'label'       => 'Get Pages',
        'description' => 'List all WordPress pages.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'count' => [ 'type' => 'integer', 'default' => 100 ] ],
        ],
        'execute_callback' => function( $input ) {
            $pages = get_posts([
                'post_type'   => 'page',
                'numberposts' => $input['count'] ?? 100,
                'post_status' => 'any',
            ]);
            return array_map( fn( $p ) => [
                'id'     => $p->ID,
                'title'  => $p->post_title,
                'status' => $p->post_status,
                'url'    => get_permalink( $p->ID ),
            ], $pages );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.7 Get Post Meta
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-post-meta', [
        'label'       => 'Get Post Meta',
        'description' => 'Read custom fields/meta on a post. Omit the key to return all meta.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'  => [ 'type' => 'integer' ],
                'key' => [ 'type' => 'string' ],
            ],
            'required' => [ 'id' ],
        ],
        'execute_callback' => function( $input ) {
            $key  = ! empty( $input['key'] ) ? $input['key'] : '';
            $meta = get_post_meta( $input['id'], $key, true );
            return [ 'id' => $input['id'], 'key' => $key ?: 'all', 'value' => $meta ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.8 Update Post Meta
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/update-post-meta', [
        'label'       => 'Update Post Meta',
        'description' => 'Write a custom field/meta value on a post. Use update-post-meta-raw for ACF flexible content / repeater fields where you need to bypass WordPress serialization.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'    => [ 'type' => 'integer' ],
                'key'   => [ 'type' => 'string' ],
                'value' => [ 'type' => 'string' ],
            ],
            'required' => [ 'id', 'key', 'value' ],
        ],
        'execute_callback' => function( $input ) {
            $result = update_post_meta( $input['id'], sanitize_text_field( $input['key'] ), sanitize_text_field( $input['value'] ) );
            return [ 'updated' => (bool) $result, 'id' => $input['id'], 'key' => $input['key'] ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.9 Bulk Update Post Meta
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/bulk-update-post-meta', [
        'label'       => 'Bulk Update Post Meta',
        'description' => 'Update one or more meta keys across multiple posts in a single call. Optionally filter by post type and source meta key mapping. Returns a detailed per-post change log with reasons for skipped writes.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_type'   => [ 'type' => 'string',  'description' => 'Post type to target. If omitted, post_ids must be provided.' ],
                'post_status' => [ 'type' => 'string',  'default'     => 'publish' ],
                'post_ids'    => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Optional explicit list of post IDs.' ],
                'mappings'    => [
                    'type'        => 'array',
                    'description' => 'Array of meta update mappings.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'target_key'   => [ 'type' => 'string' ],
                            'source_key'   => [ 'type' => 'string', 'description' => 'Optional. Read value from this meta key on the same post.' ],
                            'static_value' => [ 'type' => 'string', 'description' => 'Optional. Static value to apply if no source_key.' ],
                            'skip_if_set'  => [ 'type' => 'boolean', 'description' => 'If true, leave target_key untouched when it already has a non-empty value.' ],
                        ],
                        'required' => [ 'target_key' ],
                    ],
                ],
            ],
            'required' => [ 'mappings' ],
        ],
        'execute_callback' => function( $input ) {
            $mappings    = $input['mappings'] ?? [];
            $post_status = sanitize_text_field( $input['post_status'] ?? 'publish' );
            if ( ! empty( $input['post_ids'] ) && is_array( $input['post_ids'] ) ) {
                $post_ids = array_map( 'intval', $input['post_ids'] );
            } elseif ( ! empty( $input['post_type'] ) ) {
                $post_ids = get_posts([
                    'post_type'   => sanitize_text_field( $input['post_type'] ),
                    'post_status' => $post_status,
                    'numberposts' => -1,
                    'fields'      => 'ids',
                ]);
            } else {
                return new WP_Error( 'missing_target', 'Provide either post_type or post_ids.' );
            }
            if ( empty( $post_ids ) ) {
                return [ 'updated' => 0, 'skipped' => 0, 'results' => [], 'message' => 'No posts found.' ];
            }
            $results = []; $updated = 0; $skipped = 0;
            foreach ( $post_ids as $post_id ) {
                $post_result = [ 'id' => $post_id, 'title' => get_the_title( $post_id ), 'changes' => [] ];
                foreach ( $mappings as $mapping ) {
                    $target_key   = sanitize_text_field( $mapping['target_key'] );
                    $source_key   = ! empty( $mapping['source_key'] ) ? sanitize_text_field( $mapping['source_key'] ) : null;
                    $static_value = $mapping['static_value'] ?? null;
                    $skip_if_set  = ! empty( $mapping['skip_if_set'] );

                    if ( $skip_if_set ) {
                        $existing = get_post_meta( $post_id, $target_key, true );
                        if ( ! empty( $existing ) ) {
                            $post_result['changes'][] = [ 'key' => $target_key, 'status' => 'skipped (already set)', 'value' => $existing ];
                            $skipped++;
                            continue;
                        }
                    }
                    if ( $source_key ) {
                        $value = trim( get_post_meta( $post_id, $source_key, true ) );
                    } elseif ( $static_value !== null ) {
                        $value = $static_value;
                    } else {
                        $post_result['changes'][] = [ 'key' => $target_key, 'status' => 'skipped (no source or value)' ];
                        $skipped++;
                        continue;
                    }
                    if ( $value === '' || $value === null ) {
                        $post_result['changes'][] = [ 'key' => $target_key, 'status' => 'skipped (empty value)' ];
                        $skipped++;
                        continue;
                    }
                    update_post_meta( $post_id, $target_key, sanitize_text_field( $value ) );
                    $post_result['changes'][] = [ 'key' => $target_key, 'status' => 'updated', 'value' => $value ];
                    $updated++;
                }
                $results[] = $post_result;
            }
            return [ 'total_posts' => count( $post_ids ), 'updated' => $updated, 'skipped' => $skipped, 'results' => $results ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.10 Search Post Meta
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/search-post-meta', [
        'label'       => 'Search Post Meta',
        'description' => 'Search across all post meta values for a string. Useful for finding which post/page contains specific ACF field content.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'search'      => [ 'type' => 'string', 'description' => 'The string to search for in post meta values.' ],
                'post_type'   => [ 'type' => 'string', 'description' => 'Optional. Limit to a specific post type (e.g. page, post, app_solution). Defaults to any.' ],
                'post_status' => [ 'type' => 'string', 'description' => 'Optional. Post status filter. Defaults to any.' ],
            ],
            'required' => [ 'search' ],
        ],
        'execute_callback' => function( $input ) {
            global $wpdb;
            $search      = sanitize_text_field( $input['search'] );
            $post_type   = ! empty( $input['post_type'] )   ? sanitize_text_field( $input['post_type'] )   : '';
            $post_status = ! empty( $input['post_status'] ) ? sanitize_text_field( $input['post_status'] ) : 'any';

            $meta_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
                '%' . $wpdb->esc_like( $search ) . '%'
            ) );

            if ( empty( $meta_rows ) ) return [ 'found' => 0, 'results' => [] ];

            $post_ids = array_unique( array_column( $meta_rows, 'post_id' ) );
            $posts    = get_posts([
                'post__in'    => $post_ids,
                'post_type'   => $post_type ?: 'any',
                'post_status' => $post_status,
                'numberposts' => -1,
            ]);

            if ( empty( $posts ) ) return [ 'found' => 0, 'results' => [] ];

            $results = [];
            foreach ( $posts as $post ) {
                $matching_keys = [];
                foreach ( $meta_rows as $row ) {
                    if ( (int) $row->post_id !== $post->ID ) continue;
                    if ( strpos( $row->meta_key, '_' ) === 0 ) continue;
                    $matching_keys[] = [ 'meta_key' => $row->meta_key, 'meta_value' => $row->meta_value ];
                }
                if ( empty( $matching_keys ) ) continue;
                $results[] = [
                    'id'        => $post->ID,
                    'title'     => $post->post_title,
                    'post_type' => $post->post_type,
                    'status'    => $post->post_status,
                    'url'       => get_permalink( $post->ID ),
                    'matches'   => $matching_keys,
                ];
            }
            return [ 'found' => count( $results ), 'results' => $results ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.11 Update Post Meta Raw
    // Bypasses WP serialization — use for ACF flexible content / repeater fields.
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/update-post-meta-raw', [
        'label'       => 'Update Post Meta Raw',
        'description' => 'Write a meta value directly to the DB bypassing WordPress serialization. Use for ACF flexible content or repeater fields where the value is already a serialized PHP string (e.g. app_sections). Pass the value exactly as it should be stored in the database.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'    => [ 'type' => 'integer', 'description' => 'Post ID to update.' ],
                'meta_key'   => [ 'type' => 'string',  'description' => 'The meta key to write.' ],
                'meta_value' => [ 'type' => 'string',  'description' => 'The raw meta value. For ACF flexible content pass the serialized PHP string exactly as stored in the DB.' ],
            ],
            'required' => [ 'post_id', 'meta_key', 'meta_value' ],
        ],
        'execute_callback' => function( $input ) {
            global $wpdb;
            $post_id    = (int) $input['post_id'];
            $meta_key   = sanitize_text_field( $input['meta_key'] );
            $meta_value = $input['meta_value']; // intentionally not sanitized — raw value

            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $post_id, $meta_key
            ) );

            if ( $existing ) {
                $result = $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s",
                    $meta_value, $post_id, $meta_key
                ) );
            } else {
                $result = $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                    $post_id, $meta_key, $meta_value
                ) );
            }

            return [
                'updated'  => $result !== false,
                'post_id'  => $post_id,
                'meta_key' => $meta_key,
                'action'   => $existing ? 'updated' : 'inserted',
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.12 Copy Post Meta
    // Raw DB copy — safe for ACF flexible content and repeater fields.
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/copy-post-meta', [
        'label'       => 'Copy Post Meta',
        'description' => 'Copy all post meta (including ACF flexible content and repeater fields) from one post to another using raw DB copy. Bypasses WordPress serialization to prevent double-serialization. Optionally filter to specific meta key prefixes.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'source_id'    => [ 'type' => 'integer', 'description' => 'Post ID to copy meta from.' ],
                'dest_id'      => [ 'type' => 'integer', 'description' => 'Post ID to copy meta to.' ],
                'key_prefixes' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Optional. Only copy meta keys starting with these prefixes (e.g. ["app_sections", "_app_sections"]). Omit to copy all meta.' ],
                'overwrite'    => [ 'type' => 'boolean', 'description' => 'If true (default), overwrite existing meta on dest. If false, skip keys already set.' ],
            ],
            'required' => [ 'source_id', 'dest_id' ],
        ],
        'execute_callback' => function( $input ) {
            global $wpdb;
            $source_id    = (int) $input['source_id'];
            $dest_id      = (int) $input['dest_id'];
            $key_prefixes = ! empty( $input['key_prefixes'] ) ? (array) $input['key_prefixes'] : [];
            $overwrite    = isset( $input['overwrite'] ) ? (bool) $input['overwrite'] : true;

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
                $source_id
            ), ARRAY_A );

            if ( empty( $rows ) ) return new WP_Error( 'no_meta', 'No meta found on source post.' );

            $copied = []; $skipped = [];

            foreach ( $rows as $row ) {
                $key = $row['meta_key']; $val = $row['meta_value'];

                if ( ! empty( $key_prefixes ) ) {
                    $match = false;
                    foreach ( $key_prefixes as $prefix ) {
                        if ( strpos( $key, $prefix ) === 0 ) { $match = true; break; }
                    }
                    if ( ! $match ) { $skipped[] = $key; continue; }
                }

                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                    $dest_id, $key
                ) );

                if ( $existing ) {
                    if ( ! $overwrite ) { $skipped[] = $key; continue; }
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s",
                        $val, $dest_id, $key
                    ) );
                } else {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                        $dest_id, $key, $val
                    ) );
                }

                $copied[] = $key;
            }

            return [
                'source_id' => $source_id,
                'dest_id'   => $dest_id,
                'copied'    => count( $copied ),
                'skipped'   => count( $skipped ),
                'keys'      => $copied,
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.13 Get Users
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-users', [
        'label'       => 'Get Users',
        'description' => 'List users with their roles.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'count' => [ 'type' => 'integer', 'default' => 100 ],
                'role'  => [ 'type' => 'string' ],
            ],
        ],
        'execute_callback' => function( $input ) {
            $args = [ 'number' => $input['count'] ?? 100 ];
            if ( ! empty( $input['role'] ) ) $args['role'] = sanitize_text_field( $input['role'] );
            $users = get_users( $args );
            return array_map( fn( $u ) => [
                'id'    => $u->ID,
                'name'  => $u->display_name,
                'email' => $u->user_email,
                'roles' => $u->roles,
            ], $users );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.14 Create User
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/create-user', [
        'label'       => 'Create User',
        'description' => 'Add a new WordPress user.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'username' => [ 'type' => 'string' ],
                'email'    => [ 'type' => 'string' ],
                'password' => [ 'type' => 'string' ],
                'role'     => [ 'type' => 'string', 'default' => 'subscriber' ],
            ],
            'required' => [ 'username', 'email', 'password' ],
        ],
        'execute_callback' => function( $input ) {
            $result = wp_create_user( sanitize_user( $input['username'] ), $input['password'], sanitize_email( $input['email'] ) );
            if ( is_wp_error( $result ) ) return $result;
            $role = ! empty( $input['role'] ) ? sanitize_text_field( $input['role'] ) : 'subscriber';
            wp_update_user([ 'ID' => $result, 'role' => $role ]);
            return [ 'created' => true, 'id' => $result, 'role' => $role ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.15 Update User Role
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/update-user-role', [
        'label'       => 'Update User Role',
        'description' => 'Change a user\'s role.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'   => [ 'type' => 'integer' ],
                'role' => [ 'type' => 'string' ],
            ],
            'required' => [ 'id', 'role' ],
        ],
        'execute_callback' => function( $input ) {
            $user = new WP_User( $input['id'] );
            if ( ! $user->exists() ) return new WP_Error( 'not_found', 'User not found.' );
            $user->set_role( sanitize_text_field( $input['role'] ) );
            return [ 'updated' => true, 'id' => $input['id'], 'role' => $input['role'] ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.16 Get Site Info
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-site-info', [
        'label'       => 'Get Site Info',
        'description' => 'Return site name, URL, WordPress version, and active theme.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [ 'type' => 'object', 'properties' => [] ],
        'execute_callback' => function( $input ) {
            $theme = wp_get_theme();
            return [
                'name'          => get_bloginfo( 'name' ),
                'description'   => get_bloginfo( 'description' ),
                'url'           => get_bloginfo( 'url' ),
                'admin_email'   => get_bloginfo( 'admin_email' ),
                'wp_version'    => get_bloginfo( 'version' ),
                'language'      => get_bloginfo( 'language' ),
                'active_theme'  => $theme->get( 'Name' ),
                'theme_version' => $theme->get( 'Version' ),
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.17 Get Active Plugins
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-active-plugins', [
        'label'       => 'Get Active Plugins',
        'description' => 'List all active plugins and their versions.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [ 'type' => 'object', 'properties' => [] ],
        'execute_callback' => function( $input ) {
            if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $all_plugins    = get_plugins();
            $active_plugins = get_option( 'active_plugins', [] );
            $result = [];
            foreach ( $active_plugins as $plugin_file ) {
                if ( isset( $all_plugins[ $plugin_file ] ) ) {
                    $p = $all_plugins[ $plugin_file ];
                    $result[] = [ 'name' => $p['Name'], 'version' => $p['Version'], 'author' => $p['Author'], 'file' => $plugin_file ];
                }
            }
            return $result;
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.18 Get Options
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-options', [
        'label'       => 'Get Options',
        'description' => 'Read specific WordPress options.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'keys' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ],
            'required'   => [ 'keys' ],
        ],
        'execute_callback' => function( $input ) {
            $result = [];
            foreach ( $input['keys'] as $key ) {
                $result[ sanitize_text_field( $key ) ] = get_option( sanitize_text_field( $key ) );
            }
            return $result;
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.19 Get Post Types
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-post-types', [
        'label'       => 'Get Post Types',
        'description' => 'List all registered WordPress post types, including custom post types.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'public' => [ 'type' => 'integer', 'description' => '1 to filter to public types only, 0 for non-public. Omit for all.' ] ],
        ],
        'execute_callback' => function( $input ) {
            $args = [];
            if ( isset( $input['public'] ) ) $args['public'] = (bool) $input['public'];
            $post_types = get_post_types( $args, 'objects' );
            return array_values( array_map( fn( $pt ) => [
                'name'         => $pt->name,
                'label'        => $pt->label,
                'public'       => $pt->public,
                'hierarchical' => $pt->hierarchical,
                'has_archive'  => $pt->has_archive,
                'rest_base'    => $pt->rest_base ?? $pt->name,
            ], $post_types ) );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.20 Search Media
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/search-media', [
        'label'       => 'Search Media Library',
        'description' => 'Search the WordPress media library by title or filename.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'search' => [ 'type' => 'string' ],
                'count'  => [ 'type' => 'integer', 'default' => 10 ],
            ],
            'required' => [ 'search' ],
        ],
        'execute_callback' => function( $input ) {
            $query = new WP_Query([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $input['count'] ?? 10,
                's'              => sanitize_text_field( $input['search'] ),
            ]);
            if ( empty( $query->posts ) ) return [];
            return array_map( fn( $p ) => [
                'id'       => $p->ID,
                'title'    => $p->post_title,
                'filename' => basename( get_attached_file( $p->ID ) ),
                'url'      => wp_get_attachment_url( $p->ID ),
                'mime'     => $p->post_mime_type,
            ], $query->posts );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.21 Get Media Missing Alt
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-media-missing-alt', [
        'label'       => 'Get Media Missing Alt Tags',
        'description' => 'Returns all images missing alt tags.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'count' => [ 'type' => 'integer', 'default' => 100 ] ],
        ],
        'execute_callback' => function( $input ) {
            $attachments = get_posts([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $input['count'] ?? 100,
                'post_mime_type' => 'image',
            ]);
            $missing = [];
            foreach ( $attachments as $attachment ) {
                $alt = get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
                if ( empty( $alt ) ) {
                    $missing[] = [ 'id' => $attachment->ID, 'title' => $attachment->post_title, 'url' => wp_get_attachment_url( $attachment->ID ) ];
                }
            }
            return [ 'total_checked' => count( $attachments ), 'missing_count' => count( $missing ), 'images' => $missing ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.22 Get Image Thumbnail
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-image-thumbnail', [
        'label'       => 'Get Image Thumbnail',
        'description' => 'Returns a small base64-encoded thumbnail of a media library image for visual analysis. Supports JPEG, PNG (alpha preserved), WebP, GIF, and SVG.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'attachment_id' => [ 'type' => 'integer' ],
                'max_size'      => [ 'type' => 'integer', 'default' => 400 ],
            ],
            'required' => [ 'attachment_id' ],
        ],
        'execute_callback' => function( $input ) {
            $attachment_id = (int) $input['attachment_id'];
            $max_size      = (int) ( $input['max_size'] ?? 400 );
            $file_path     = get_attached_file( $attachment_id );
            if ( ! $file_path || ! file_exists( $file_path ) ) return new WP_Error( 'file_not_found', 'Image file not found.' );
            $mime = get_post_mime_type( $attachment_id );
            if ( $mime === 'image/svg+xml' ) {
                return [ 'attachment_id' => $attachment_id, 'mime_type' => $mime, 'base64' => base64_encode( file_get_contents( $file_path ) ), 'note' => 'SVG' ];
            }
            $image = null;
            if ( $mime === 'image/jpeg' )     $image = @imagecreatefromjpeg( $file_path );
            elseif ( $mime === 'image/png' )  $image = @imagecreatefrompng( $file_path );
            elseif ( $mime === 'image/webp' ) $image = @imagecreatefromwebp( $file_path );
            elseif ( $mime === 'image/gif' )  $image = @imagecreatefromgif( $file_path );
            if ( ! $image ) return new WP_Error( 'gd_failed', 'Could not process image with GD library.' );
            $orig_w = imagesx( $image );
            $orig_h = imagesy( $image );
            if ( $orig_w > $orig_h ) {
                $new_w = min( $orig_w, $max_size );
                $new_h = (int) round( $orig_h * $new_w / $orig_w );
            } else {
                $new_h = min( $orig_h, $max_size );
                $new_w = (int) round( $orig_w * $new_h / $orig_h );
            }
            $thumb = imagecreatetruecolor( $new_w, $new_h );
            if ( $mime === 'image/png' ) {
                imagealphablending( $thumb, false );
                imagesavealpha( $thumb, true );
            }
            imagecopyresampled( $thumb, $image, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h );
            imagedestroy( $image );
            ob_start();
            imagejpeg( $thumb, null, 75 );
            $jpeg_data = ob_get_clean();
            imagedestroy( $thumb );
            return [ 'attachment_id' => $attachment_id, 'mime_type' => 'image/jpeg', 'width' => $new_w, 'height' => $new_h, 'base64' => base64_encode( $jpeg_data ) ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.23 Set Featured Image
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/set-featured-image', [
        'label'       => 'Set Featured Image',
        'description' => 'Set the featured image for a post using an attachment ID already in the media library. Validates that the post and attachment exist before writing.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'       => [ 'type' => 'integer' ],
                'attachment_id' => [ 'type' => 'integer' ],
            ],
            'required' => [ 'post_id', 'attachment_id' ],
        ],
        'execute_callback' => function( $input ) {
            $post = get_post( $input['post_id'] );
            if ( ! $post ) return new WP_Error( 'post_not_found', 'Post not found.' );
            $attachment = get_post( $input['attachment_id'] );
            if ( ! $attachment || $attachment->post_type !== 'attachment' ) return new WP_Error( 'attachment_not_found', 'Attachment not found.' );
            $result = set_post_thumbnail( $input['post_id'], $input['attachment_id'] );
            if ( ! $result ) return new WP_Error( 'thumbnail_failed', 'Failed to set featured image.' );
            return [
                'updated'       => true,
                'post_id'       => $input['post_id'],
                'attachment_id' => $input['attachment_id'],
                'image_url'     => wp_get_attachment_url( $input['attachment_id'] ),
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.24 Media Upload From URL
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/media-upload-from-url', [
        'label'       => 'Media Upload From URL',
        'description' => 'Sideload one or more images from URLs into the WordPress media library.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'images' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'url'      => [ 'type' => 'string' ],
                            'title'    => [ 'type' => 'string' ],
                            'alt_text' => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
            'required' => [ 'images' ],
        ],
        'execute_callback' => function( $input ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $results = [];
            foreach ( $input['images'] as $image ) {
                $url = $image['url'] ?? '';
                if ( empty( $url ) ) { $results[] = [ 'url' => $url, 'success' => false, 'error' => 'No URL provided.' ]; continue; }
                $tmp = download_url( $url );
                if ( is_wp_error( $tmp ) ) { $results[] = [ 'url' => $url, 'success' => false, 'error' => $tmp->get_error_message() ]; continue; }
                $file_array    = [ 'name' => basename( parse_url( $url, PHP_URL_PATH ) ), 'tmp_name' => $tmp ];
                $attachment_id = media_handle_sideload( $file_array, 0 );
                if ( is_wp_error( $attachment_id ) ) { @unlink( $tmp ); $results[] = [ 'url' => $url, 'success' => false, 'error' => $attachment_id->get_error_message() ]; continue; }
                if ( ! empty( $image['title'] ) )    wp_update_post([ 'ID' => $attachment_id, 'post_title' => sanitize_text_field( $image['title'] ) ]);
                if ( ! empty( $image['alt_text'] ) ) update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $image['alt_text'] ) );
                $results[] = [
                    'url'           => $url,
                    'success'       => true,
                    'attachment_id' => $attachment_id,
                    'media_url'     => wp_get_attachment_url( $attachment_id ),
                    'title'         => get_the_title( $attachment_id ),
                ];
            }
            return [
                'uploaded' => count( array_filter( $results, fn( $r ) => $r['success'] ) ),
                'failed'   => count( array_filter( $results, fn( $r ) => ! $r['success'] ) ),
                'results'  => $results,
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.25 Media Upload From Base64
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/media-upload-base64', [
        'label'       => 'Media Upload From Base64',
        'description' => 'Upload a base64-encoded image directly into the WordPress media library.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'filename'  => [ 'type' => 'string' ],
                'mime_type' => [ 'type' => 'string' ],
                'data'      => [ 'type' => 'string' ],
                'title'     => [ 'type' => 'string' ],
                'alt_text'  => [ 'type' => 'string' ],
            ],
            'required' => [ 'filename', 'mime_type', 'data' ],
        ],
        'execute_callback' => function( $input ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $image_data = base64_decode( $input['data'] );
            if ( ! $image_data ) return new WP_Error( 'decode_failed', 'Failed to decode base64 image data.' );
            $tmp_file = wp_tempnam( $input['filename'] );
            file_put_contents( $tmp_file, $image_data );
            $file_array    = [ 'name' => sanitize_file_name( $input['filename'] ), 'tmp_name' => $tmp_file, 'type' => $input['mime_type'] ];
            $attachment_id = media_handle_sideload( $file_array, 0 );
            if ( is_wp_error( $attachment_id ) ) { @unlink( $tmp_file ); return new WP_Error( 'upload_failed', $attachment_id->get_error_message() ); }
            if ( ! empty( $input['title'] ) )    wp_update_post([ 'ID' => $attachment_id, 'post_title' => sanitize_text_field( $input['title'] ) ]);
            if ( ! empty( $input['alt_text'] ) ) update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
            return [
                'success'       => true,
                'attachment_id' => $attachment_id,
                'filename'      => $input['filename'],
                'media_url'     => wp_get_attachment_url( $attachment_id ),
                'title'         => get_the_title( $attachment_id ),
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.26 Generate Alt Text
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/generate-alt-text', [
        'label'       => 'Generate Alt Text',
        'description' => 'Generate descriptive alt text and optionally a SEO-friendly filename for an image using the Anthropic Claude API.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'image_base64'      => [ 'type' => 'string' ],
                'mime_type'         => [ 'type' => 'string' ],
                'api_key'           => [ 'type' => 'string' ],
                'context'           => [ 'type' => 'string' ],
                'generate_filename' => [ 'type' => 'boolean' ],
                'file_extension'    => [ 'type' => 'string' ],
            ],
            'required' => [ 'image_base64', 'mime_type', 'api_key' ],
        ],
        'execute_callback' => function( $input ) {
            $gen_filename = isset( $input['generate_filename'] ) && $input['generate_filename'] === true;
            $context      = ! empty( $input['context'] ) ? ' Context: ' . sanitize_text_field( $input['context'] ) . '.' : '';
            $prompt       = $gen_filename
                ? 'Analyze this image and respond with a JSON object with exactly two keys: "alt_text" (concise descriptive alt text under 125 characters, not starting with Image of or Photo of) and "filename" (SEO-friendly filename, lowercase letters/numbers/hyphens only, no extension, max 60 chars). Return ONLY valid JSON.' . $context
                : 'Write a concise, descriptive alt text for this image in one sentence under 125 characters. Be specific and factual. Do not start with Image of or Photo of. Return only the alt text, nothing else.' . $context;
            $payload = [
                'model'      => 'claude-opus-4-5',
                'max_tokens' => $gen_filename ? 200 : 150,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        [ 'type' => 'image', 'source' => [ 'type' => 'base64', 'media_type' => $input['mime_type'], 'data' => $input['image_base64'] ] ],
                        [ 'type' => 'text',  'text' => $prompt ],
                    ],
                ]],
            ];
            $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json', 'x-api-key' => sanitize_text_field( $input['api_key'] ), 'anthropic-version' => '2023-06-01' ],
                'body'    => wp_json_encode( $payload ),
            ]);
            if ( is_wp_error( $response ) ) return new WP_Error( 'api_error', $response->get_error_message() );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! isset( $body['content'][0]['text'] ) ) return new WP_Error( 'api_error', 'Unexpected API response.' );
            $text     = trim( $body['content'][0]['text'] );
            $alt_text = '';
            $filename = '';
            if ( $gen_filename ) {
                $clean  = preg_replace( '/^```(?:json)?\s*/i', '', $text );
                $clean  = preg_replace( '/\s*```$/', '', trim( $clean ) );
                $parsed = json_decode( $clean, true );
                if ( $parsed && isset( $parsed['alt_text'] ) ) {
                    $alt_text = trim( $parsed['alt_text'] );
                    $ext      = ! empty( $input['file_extension'] ) ? '.' . ltrim( $input['file_extension'], '.' ) : '';
                    $filename = sanitize_title( $parsed['filename'] ?? '' ) . $ext;
                } else {
                    $alt_text = $text;
                }
            } else {
                $alt_text = $text;
            }
            return [ 'alt_text' => $alt_text, 'filename' => $filename ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => false ],
            'mcp'          => [ 'public' => true, 'type' => 'tool' ],
        ],
    ]);

    // -------------------------------------------------------------------------
    // 4.27 Create Term
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/create-term', [
        'label'       => 'Create Term',
        'description' => 'Create a taxonomy term. Skips creation if the slug already exists.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'taxonomy'    => [ 'type' => 'string',  'description' => 'Taxonomy slug (e.g. insight_type, category).' ],
                'name'        => [ 'type' => 'string',  'description' => 'Display name of the term.' ],
                'slug'        => [ 'type' => 'string',  'description' => 'Optional. URL slug. Defaults to sanitized name.' ],
                'description' => [ 'type' => 'string',  'description' => 'Optional. Term description.' ],
                'parent'      => [ 'type' => 'integer', 'description' => 'Optional. Parent term ID for hierarchical taxonomies.' ],
            ],
            'required' => [ 'taxonomy', 'name' ],
        ],
        'execute_callback' => function( $input ) {
            $taxonomy = sanitize_text_field( $input['taxonomy'] );
            $name     = sanitize_text_field( $input['name'] );
            $slug     = ! empty( $input['slug'] ) ? sanitize_title( $input['slug'] ) : sanitize_title( $name );

            if ( ! taxonomy_exists( $taxonomy ) ) {
                return new WP_Error( 'invalid_taxonomy', "Taxonomy '{$taxonomy}' does not exist." );
            }

            $existing = get_term_by( 'slug', $slug, $taxonomy );
            if ( $existing ) {
                return [
                    'created'  => false,
                    'skipped'  => true,
                    'reason'   => 'Term with this slug already exists.',
                    'term_id'  => $existing->term_id,
                    'name'     => $existing->name,
                    'slug'     => $existing->slug,
                    'taxonomy' => $taxonomy,
                ];
            }

            $args = [ 'slug' => $slug ];
            if ( ! empty( $input['description'] ) ) $args['description'] = sanitize_text_field( $input['description'] );
            if ( ! empty( $input['parent'] ) )      $args['parent']      = (int) $input['parent'];

            $result = wp_insert_term( $name, $taxonomy, $args );

            if ( is_wp_error( $result ) ) return $result;

            return [
                'created'  => true,
                'term_id'  => $result['term_id'],
                'name'     => $name,
                'slug'     => $slug,
                'taxonomy' => $taxonomy,
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.28 Get Terms
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-terms', [
        'label'       => 'Get Terms',
        'description' => 'List all terms for a given taxonomy.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'taxonomy'   => [ 'type' => 'string',  'description' => 'Taxonomy slug (e.g. insight_type, category).' ],
                'hide_empty' => [ 'type' => 'boolean', 'description' => 'Whether to hide terms with no posts. Defaults to false.' ],
            ],
            'required' => [ 'taxonomy' ],
        ],
        'execute_callback' => function( $input ) {
            $taxonomy   = sanitize_text_field( $input['taxonomy'] );
            $hide_empty = ! empty( $input['hide_empty'] );

            if ( ! taxonomy_exists( $taxonomy ) ) {
                return new WP_Error( 'invalid_taxonomy', "Taxonomy '{$taxonomy}' does not exist." );
            }

            $terms = get_terms([ 'taxonomy' => $taxonomy, 'hide_empty' => $hide_empty ]);

            if ( is_wp_error( $terms ) ) return $terms;
            if ( empty( $terms ) ) return [ 'count' => 0, 'terms' => [] ];

            return [
                'count' => count( $terms ),
                'terms' => array_map( fn( $t ) => [
                    'term_id'     => $t->term_id,
                    'name'        => $t->name,
                    'slug'        => $t->slug,
                    'description' => $t->description,
                    'count'       => $t->count,
                    'parent'      => $t->parent,
                ], $terms ),
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.29 Set Post Terms
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/set-post-terms', [
        'label'       => 'Set Post Terms',
        'description' => 'Assign taxonomy terms to a post by term ID or slug.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'    => [ 'type' => 'integer', 'description' => 'ID of the post to update.' ],
                'taxonomy'   => [ 'type' => 'string',  'description' => 'Taxonomy slug (e.g. insight_type).' ],
                'term_ids'   => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of term IDs to assign.' ],
                'term_slugs' => [ 'type' => 'array', 'items' => [ 'type' => 'string'  ], 'description' => 'Array of term slugs to assign (alternative to term_ids).' ],
                'append'     => [ 'type' => 'boolean', 'description' => 'If true, add to existing terms. If false (default), replace all.' ],
            ],
            'required' => [ 'post_id', 'taxonomy' ],
        ],
        'execute_callback' => function( $input ) {
            $post_id  = (int) $input['post_id'];
            $taxonomy = sanitize_text_field( $input['taxonomy'] );
            $append   = ! empty( $input['append'] );

            if ( ! taxonomy_exists( $taxonomy ) ) {
                return new WP_Error( 'invalid_taxonomy', "Taxonomy '{$taxonomy}' does not exist." );
            }

            if ( ! get_post( $post_id ) ) {
                return new WP_Error( 'invalid_post', "Post {$post_id} not found." );
            }

            $term_ids = [];
            if ( ! empty( $input['term_ids'] ) ) {
                $term_ids = array_map( 'intval', $input['term_ids'] );
            } elseif ( ! empty( $input['term_slugs'] ) ) {
                foreach ( $input['term_slugs'] as $slug ) {
                    $term = get_term_by( 'slug', sanitize_title( $slug ), $taxonomy );
                    if ( $term ) $term_ids[] = $term->term_id;
                }
            }

            if ( empty( $term_ids ) ) {
                return new WP_Error( 'no_terms', 'No valid term_ids or term_slugs provided.' );
            }

            $result = wp_set_post_terms( $post_id, $term_ids, $taxonomy, $append );

            if ( is_wp_error( $result ) ) return $result;

            return [
                'updated'  => true,
                'post_id'  => $post_id,
                'taxonomy' => $taxonomy,
                'term_ids' => $result,
                'appended' => $append,
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.30 Clear Cache
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/clear-cache', [
        'label'       => 'Clear Cache',
        'description' => 'Trigger a cache flush for common caching plugins (WP Rocket, W3 Total Cache, WP Super Cache) plus the WordPress object cache.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [ 'type' => 'object', 'properties' => [] ],
        'execute_callback' => function( $input ) {
            $cleared = [];
            if ( function_exists( 'rocket_clean_domain' ) )    { rocket_clean_domain();    $cleared[] = 'WP Rocket'; }
            if ( function_exists( 'w3tc_flush_all' ) )         { w3tc_flush_all();         $cleared[] = 'W3 Total Cache'; }
            if ( function_exists( 'wp_cache_clear_cache' ) )   { wp_cache_clear_cache();   $cleared[] = 'WP Super Cache'; }
            wp_cache_flush();
            $cleared[] = 'WordPress Object Cache';
            return [
                'cleared' => $cleared,
                'message' => empty( $cleared ) ? 'No supported cache plugins found.' : 'Cache cleared successfully.',
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.31 Run WP Cron
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/run-wp-cron', [
        'label'       => 'Run WP Cron',
        'description' => 'Manually trigger WP-Cron events. Pass a specific hook to fire only that one, or omit to run all due events.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'hook' => [ 'type' => 'string' ] ],
        ],
        'execute_callback' => function( $input ) {
            if ( ! empty( $input['hook'] ) ) {
                $hook = sanitize_text_field( $input['hook'] );
                do_action( $hook );
                return [ 'ran' => true, 'hook' => $hook ];
            }
            $crons = _get_cron_array();
            $ran   = [];
            $now   = time();
            foreach ( $crons as $timestamp => $hooks ) {
                if ( $timestamp > $now ) continue;
                foreach ( $hooks as $hook => $events ) {
                    foreach ( $events as $event ) {
                        do_action_ref_array( $hook, $event['args'] );
                        $ran[] = $hook;
                    }
                }
            }
            return [ 'ran' => $ran, 'count' => count( $ran ) ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

});
