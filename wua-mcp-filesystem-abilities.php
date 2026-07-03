<?php
/**
 * WUA MCP Filesystem Abilities
 *
 * @package     wua-mcp-abilities
 * Description: Filesystem abilities for the WUA MCP plugin.
 *              Allows reading, writing, listing, and deleting files on the server.
 */

# =============================================================================
# Table of Contents
# =============================================================================
# 1. Register Filesystem Category
# 2. Register Filesystem Abilities
#       F.1  fs-read-file       - Read the contents of a file
#       F.2  fs-write-file      - Write content to a file
#       F.3  fs-list-directory  - List files in a directory
#       F.4  fs-delete-file     - Delete a file (within wp-content only)
# =============================================================================


# Register Filesystem Category
add_action( 'wp_abilities_api_categories_init', function() {

    wp_register_ability_category( 'wua-mcp-filesystem', [
        'label'       => 'WUA MCP Filesystem Abilities',
        'description' => 'Server filesystem abilities for WUA MCP integration',
    ]);

});


# Register Filesystem Abilities
add_action( 'wp_abilities_api_init', function() {

    // -------------------------------------------------------------------------
    // F.1 Read File
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-filesystem/fs-read-file', [
        'label'       => 'Read File',
        'description' => 'Read the contents of a file on the server. Path should be relative to wp-content (e.g. themes/my-theme/acf-json/group_abc.json) or an absolute path.',
        'category'    => 'wua-mcp-filesystem',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => 'File path — either absolute, or relative to wp-content (e.g. themes/my-theme/acf-json/group_64f71c2711ed9.json)',
                ],
            ],
            'required' => [ 'path' ],
        ],
        'execute_callback' => function( $input ) {
            $path = $input['path'];

            // If not absolute, treat as relative to WP_CONTENT_DIR
            if ( strpos( $path, '/' ) !== 0 ) {
                $path = trailingslashit( WP_CONTENT_DIR ) . ltrim( $path, '/' );
            }

            if ( ! file_exists( $path ) ) {
                return new WP_Error( 'file_not_found', 'File not found: ' . $path );
            }

            if ( ! is_readable( $path ) ) {
                return new WP_Error( 'file_not_readable', 'File is not readable: ' . $path );
            }

            $contents = file_get_contents( $path );

            if ( $contents === false ) {
                return new WP_Error( 'read_failed', 'Failed to read file: ' . $path );
            }

            return [
                'path'     => $path,
                'size'     => filesize( $path ),
                'modified' => date( 'Y-m-d H:i:s', filemtime( $path ) ),
                'contents' => $contents,
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);


    // -------------------------------------------------------------------------
    // F.2 Write File
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-filesystem/fs-write-file', [
        'label'       => 'Write File',
        'description' => 'Write content to a file on the server. Path should be relative to wp-content or absolute. Will overwrite existing files.',
        'category'    => 'wua-mcp-filesystem',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => 'File path — either absolute, or relative to wp-content (e.g. themes/my-theme/acf-json/group_64f71c2711ed9.json)',
                ],
                'contents' => [
                    'type'        => 'string',
                    'description' => 'The content to write to the file',
                ],
            ],
            'required' => [ 'path', 'contents' ],
        ],
        'execute_callback' => function( $input ) {
            $path = $input['path'];

            // Reject path traversal outright (v2.10).
            if ( strpos( $path, '..' ) !== false ) {
                return new WP_Error( 'path_not_allowed', 'Path traversal ("..") is not allowed.' );
            }

            // If not absolute, treat as relative to WP_CONTENT_DIR
            if ( strpos( $path, '/' ) !== 0 ) {
                $path = trailingslashit( WP_CONTENT_DIR ) . ltrim( $path, '/' );
            }

            $dir = dirname( $path );

            // Safety check — only allow writes within wp-content. Normalize both paths so the
            // comparison works on Windows (realpath() returns backslashes; WP_CONTENT_DIR may
            // use forward slashes). Use realpath() when the directory already exists (resolves
            // symlinks); otherwise fall back to the lexical path so a not-yet-existing
            // directory can be created below.
            $dir_for_check = is_dir( $dir ) ? realpath( $dir ) : $dir;
            if ( strpos( wp_normalize_path( $dir_for_check ), wp_normalize_path( WP_CONTENT_DIR ) ) === false ) {
                return new WP_Error( 'path_not_allowed', 'Writes are only allowed within the wp-content directory.' );
            }

            // v2.10: create the parent directory if it doesn't exist yet.
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            if ( ! is_writable( $dir ) ) {
                return new WP_Error( 'not_writable', 'Directory is not writable: ' . $dir );
            }

            $result = file_put_contents( $path, $input['contents'] );

            if ( $result === false ) {
                return new WP_Error( 'write_failed', 'Failed to write file: ' . $path );
            }

            return [
                'written'  => true,
                'path'     => $path,
                'size'     => $result,
                'modified' => date( 'Y-m-d H:i:s', filemtime( $path ) ),
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);


    // -------------------------------------------------------------------------
    // F.3 List Directory
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-filesystem/fs-list-directory', [
        'label'       => 'List Directory',
        'description' => 'List files and folders in a directory. Path should be relative to wp-content or absolute.',
        'category'    => 'wua-mcp-filesystem',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => 'Directory path — either absolute, or relative to wp-content (e.g. themes/my-theme/acf-json)',
                ],
                'extension' => [
                    'type'        => 'string',
                    'description' => 'Optional file extension filter (e.g. json, php). Omit to list all files.',
                ],
            ],
            'required' => [ 'path' ],
        ],
        'execute_callback' => function( $input ) {
            $path = $input['path'];

            // If not absolute, treat as relative to WP_CONTENT_DIR
            if ( strpos( $path, '/' ) !== 0 ) {
                $path = trailingslashit( WP_CONTENT_DIR ) . ltrim( $path, '/' );
            }

            if ( ! is_dir( $path ) ) {
                return new WP_Error( 'not_a_directory', 'Path is not a directory: ' . $path );
            }

            $items   = scandir( $path );
            $results = [];

            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) continue;

                $full_path = trailingslashit( $path ) . $item;
                $is_dir    = is_dir( $full_path );

                // Apply extension filter if provided
                if ( ! $is_dir && ! empty( $input['extension'] ) ) {
                    $ext = pathinfo( $item, PATHINFO_EXTENSION );
                    if ( $ext !== ltrim( $input['extension'], '.' ) ) continue;
                }

                $results[] = [
                    'name'     => $item,
                    'type'     => $is_dir ? 'directory' : 'file',
                    'size'     => $is_dir ? null : filesize( $full_path ),
                    'modified' => date( 'Y-m-d H:i:s', filemtime( $full_path ) ),
                    'path'     => $full_path,
                ];
            }

            return [
                'path'  => $path,
                'count' => count( $results ),
                'items' => $results,
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);


    // -------------------------------------------------------------------------
    // F.4 Delete File
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-filesystem/fs-delete-file', [
        'label'       => 'Delete File',
        'description' => 'Delete a file on the server. Path should be relative to wp-content or absolute. Only files within wp-content can be deleted.',
        'category'    => 'wua-mcp-filesystem',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => 'File path — either absolute, or relative to wp-content. Must resolve inside wp-content.',
                ],
            ],
            'required' => [ 'path' ],
        ],
        'execute_callback' => function( $input ) {
            $path = $input['path'];

            // If not absolute, treat as relative to WP_CONTENT_DIR
            if ( strpos( $path, '/' ) !== 0 ) {
                $path = trailingslashit( WP_CONTENT_DIR ) . ltrim( $path, '/' );
            }

            $real = realpath( $path );
            if ( $real === false ) {
                return new WP_Error( 'file_not_found', 'File not found: ' . $path );
            }
            if ( strpos( wp_normalize_path( $real ), wp_normalize_path( WP_CONTENT_DIR ) ) === false ) {
                return new WP_Error( 'path_not_allowed', 'Deletes are only allowed within the wp-content directory.' );
            }
            if ( is_dir( $real ) ) {
                return new WP_Error( 'is_directory', 'Path is a directory, not a file: ' . $real );
            }
            if ( ! is_writable( $real ) ) {
                return new WP_Error( 'not_writable', 'File is not writable/deletable: ' . $real );
            }

            $deleted = unlink( $real );
            if ( ! $deleted ) {
                return new WP_Error( 'delete_failed', 'Failed to delete file: ' . $real );
            }

            return [ 'deleted' => true, 'path' => $real ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

});
