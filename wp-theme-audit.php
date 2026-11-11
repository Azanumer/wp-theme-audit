<?php
/**
 * Plugin Name: WP Theme Audit
 * Plugin URI: https://github.com/Azanumer/wp-theme-audit
 * Description: Scans the active theme for deprecated WordPress functions, missing template files, text-domain mismatches, non-HTTPS URLs and other common mistakes — with a one-page report under Tools.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-theme-audit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WTA_VERSION', '1.0.0' );

/**
 * Deprecated functions to flag: function name => version it was deprecated in.
 * Filterable via 'wta_deprecated_functions'.
 *
 * @return array
 */
function wta_deprecated_functions() {
    $list = array(
        'wp_title'             => '4.4',
        'get_currentuserinfo'  => '4.5',
        'force_ssl_login'      => '4.4',
        'force_ssl_admin'      => '4.4',
        'wp_get_http'          => '4.4',
        'wp_remote_fopen'      => '2.7',
        'screen_icon'          => '3.8',
        'get_screen_icon'      => '3.8',
        'add_contextual_help'  => '3.3',
        'like_escape'          => '4.0',
        'comments_rss_link'    => '4.4',
        'permalink_single_rss' => '4.4',
        'create_function'      => 'PHP 7.2',
    );
    return apply_filters( 'wta_deprecated_functions', $list );
}

/**
 * Directories to skip while scanning (relative to the theme root).
 * Filterable via 'wta_exclude_dirs'.
 *
 * @return string[]
 */
function wta_exclude_dirs() {
    return apply_filters( 'wta_exclude_dirs', array( 'node_modules', 'vendor', '.git' ) );
}

/**
 * Read a theme's style.css headers.
 *
 * @param string $theme_dir Absolute theme directory.
 * @return array Header name => value.
 */
function wta_theme_headers( $theme_dir ) {
    $style = $theme_dir . '/style.css';
    if ( ! is_readable( $style ) ) {
        return array();
    }
    return get_file_data( $style, array(
        'Name'       => 'Theme Name',
        'Version'    => 'Version',
        'TextDomain' => 'Text Domain',
        'Author'     => 'Author',
    ) );
}

/**
 * Recursively collect the theme's PHP files, skipping excluded directories.
 *
 * @param string $theme_dir Absolute theme directory.
 * @return string[] Absolute file paths.
 */
function wta_collect_php_files( $theme_dir ) {
    $files   = array();
    $exclude = wta_exclude_dirs();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $theme_dir, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $iterator as $item ) {
        if ( $item->isDir() ) {
            continue;
        }
        $path = $item->getPathname();
        $rel  = str_replace( $theme_dir . DIRECTORY_SEPARATOR, '', $path );
        $parts = explode( DIRECTORY_SEPARATOR, $rel );
        if ( count( array_intersect( $parts, $exclude ) ) > 0 ) {
            continue;
        }
        if ( 'php' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
            $files[] = $path;
        }
    }

    return $files;
}

/**
 * Add one finding to the list.
 *
 * @param array  $findings Findings array (by reference).
 * @param string $severity 'error', 'warning' or 'info'.
 * @param string $file     Relative file path ('' for theme-level findings).
 * @param int    $line     Line number (0 when not applicable).
 * @param string $message  Human-readable message.
 */
function wta_add_finding( &$findings, $severity, $file, $line, $message ) {
    $findings[] = array(
        'severity' => $severity,
        'file'     => $file,
        'line'     => (int) $line,
        'message'  => $message,
    );
}

/**
 * Run the full audit on the active theme.
 *
 * @return array List of findings: [ 'severity', 'file', 'line', 'message' ].
 */
function wta_run_audit() {
    $theme_dir = get_stylesheet_directory();
    $findings  = array();

    // --- 1. style.css headers -------------------------------------------------
    $headers = wta_theme_headers( $theme_dir );
    if ( empty( $headers ) ) {
        wta_add_finding( $findings, 'error', 'style.css', 0, __( 'style.css is missing or unreadable — WordPress cannot load this theme without it.', 'wp-theme-audit' ) );
    } else {
        foreach ( array( 'Name' => 'Theme Name', 'Version' => 'Version', 'TextDomain' => 'Text Domain' ) as $key => $label ) {
            if ( empty( $headers[ $key ] ) ) {
                wta_add_finding( $findings, 'warning', 'style.css', 0, sprintf(
                    /* translators: %s: style.css header label. */
                    __( 'style.css is missing the "%s" header.', 'wp-theme-audit' ),
                    $label
                ) );
            }
        }
    }
    $text_domain = isset( $headers['TextDomain'] ) ? $headers['TextDomain'] : '';

    // --- 2. Required / recommended files --------------------------------------
    foreach ( array( 'index.php', 'functions.php' ) as $required ) {
        if ( ! file_exists( $theme_dir . '/' . $required ) ) {
            wta_add_finding( $findings, 'error', $required, 0, sprintf(
                /* translators: %s: file name. */
                __( 'Required file "%s" is missing from the theme.', 'wp-theme-audit' ),
                $required
            ) );
        }
    }
    if ( ! file_exists( $theme_dir . '/screenshot.png' ) ) {
        wta_add_finding( $findings, 'info', 'screenshot.png', 0, __( 'No screenshot.png — the theme shows a blank preview in Appearance → Themes.', 'wp-theme-audit' ) );
    }

    // --- 3. wp_head / wp_footer presence --------------------------------------
    $header_php = $theme_dir . '/header.php';
    if ( file_exists( $header_php ) && false === strpos( file_get_contents( $header_php ), 'wp_head' ) ) {
        wta_add_finding( $findings, 'error', 'header.php', 0, __( 'header.php never calls wp_head() — plugins, enqueued scripts/styles and the admin bar will break.', 'wp-theme-audit' ) );
    }
    $footer_php = $theme_dir . '/footer.php';
    if ( file_exists( $footer_php ) && false === strpos( file_get_contents( $footer_php ), 'wp_footer' ) ) {
        wta_add_finding( $findings, 'error', 'footer.php', 0, __( 'footer.php never calls wp_footer() — enqueued footer scripts will not print.', 'wp-theme-audit' ) );
    }

    // --- 4. Per-file scans ----------------------------------------------------
    $deprecated = wta_deprecated_functions();
    $dep_regex  = '/\b(' . implode( '|', array_map( 'preg_quote', array_keys( $deprecated ) ) ) . ')\s*\(/';
    // Translation functions whose 2nd argument is the text domain.
    $i18n_regex = '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,\s*([\'"])([^\'"]*)\1/';
    $url_regex  = '/http:\/\/[^\s\'")<>]+/i';

    foreach ( wta_collect_php_files( $theme_dir ) as $file ) {
        $rel   = str_replace( $theme_dir . DIRECTORY_SEPARATOR, '', $file );
        $lines = file( $file, FILE_IGNORE_NEW_LINES );
        if ( false === $lines ) {
            continue;
        }

        foreach ( $lines as $index => $line ) {
            $line_no = $index + 1;

            // Deprecated functions.
            if ( preg_match_all( $dep_regex, $line, $matches ) ) {
                foreach ( array_unique( $matches[1] ) as $fn ) {
                    wta_add_finding( $findings, 'error', $rel, $line_no, sprintf(
                        /* translators: 1: function name, 2: version. */
                        __( 'Deprecated function %1$s() used (deprecated in WordPress %2$s).', 'wp-theme-audit' ),
                        $fn,
                        $deprecated[ $fn ]
                    ) );
                }
            }

            // Removed mysql_* extension functions.
            if ( preg_match( '/\bmysql_(?:query|connect|fetch_array|fetch_assoc|fetch_row|select_db|real_escape_string|num_rows)\s*\(/', $line ) ) {
                wta_add_finding( $findings, 'error', $rel, $line_no, __( 'mysql_* functions were removed in PHP 7.0 — use $wpdb or PDO instead.', 'wp-theme-audit' ) );
            }

            // eval() — security risk.
            if ( preg_match( '/\beval\s*\(/', $line ) ) {
                wta_add_finding( $findings, 'error', $rel, $line_no, __( 'eval() found — executing strings as code is a security risk; remove it.', 'wp-theme-audit' ) );
            }

            // query_posts() — bad practice.
            if ( preg_match( '/\bquery_posts\s*\(/', $line ) ) {
                wta_add_finding( $findings, 'warning', $rel, $line_no, __( 'query_posts() used — it breaks the main query; use WP_Query or pre_get_posts instead.', 'wp-theme-audit' ) );
            }

            // Text-domain mismatches.
            if ( '' !== $text_domain && preg_match_all( $i18n_regex, $line, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    if ( '' !== $match[3] && $match[3] !== $text_domain ) {
                        wta_add_finding( $findings, 'warning', $rel, $line_no, sprintf(
                            /* translators: 1: domain found, 2: theme text domain. */
                            __( 'Translation uses text domain "%1$s" but the theme declares "%2$s".', 'wp-theme-audit' ),
                            $match[3],
                            $text_domain
                        ) );
                    }
                }
            }

            // Non-HTTPS URLs (excluding localhost, 127.0.0.1 and W3C namespaces).
            if ( preg_match_all( $url_regex, $line, $matches ) ) {
                foreach ( $matches[0] as $url ) {
                    $host = wp_parse_url( $url, PHP_URL_HOST );
                    if ( in_array( $host, array( 'localhost', '127.0.0.1', 'www.w3.org', 'schema.org' ), true ) ) {
                        continue;
                    }
                    wta_add_finding( $findings, 'warning', $rel, $line_no, sprintf(
                        /* translators: %s: URL. */
                        __( 'Non-HTTPS URL found: %s', 'wp-theme-audit' ),
                        $url
                    ) );
                }
            }
        }
    }

    return apply_filters( 'wta_findings', $findings );
}

/**
 * Tools page: run the audit and render the report.
 */
function wta_tools_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do that.', 'wp-theme-audit' ) );
    }

    $theme = wp_get_theme();
    $ran   = isset( $_GET['wta_run'] ) && '1' === $_GET['wta_run'];
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Theme Audit', 'wp-theme-audit' ); ?></h1>
        <p><?php printf(
            /* translators: %s: active theme name. */
            esc_html__( 'Audits the active theme (%s) for deprecated functions, missing files, text-domain mismatches and other common mistakes.', 'wp-theme-audit' ),
            '<strong>' . esc_html( $theme->get( 'Name' ) ) . '</strong>'
        ); ?></p>
        <p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wta_run', '1' ), 'wta_run' ) ); ?>"><?php esc_html_e( 'Run audit', 'wp-theme-audit' ); ?></a></p>
        <?php
        if ( $ran ) {
            check_admin_referer( 'wta_run' );
            $findings = wta_run_audit();

            $counts = array( 'error' => 0, 'warning' => 0, 'info' => 0 );
            foreach ( $findings as $finding ) {
                $counts[ $finding['severity'] ]++;
            }

            if ( empty( $findings ) ) {
                echo '<div class="notice notice-success"><p>' . esc_html__( 'Clean — no issues found in the active theme.', 'wp-theme-audit' ) . '</p></div>';
            } else {
                printf(
                    '<p><strong>%s</strong></p>',
                    esc_html( sprintf(
                        /* translators: 1: errors, 2: warnings, 3: info notes. */
                        _n( '%1$d error, %2$d warning, %3$d note.', '%1$d errors, %2$d warnings, %3$d notes.', array_sum( $counts ), 'wp-theme-audit' ),
                        $counts['error'],
                        $counts['warning'],
                        $counts['info']
                    ) )
                );
                echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Severity', 'wp-theme-audit' ) . '</th><th>' . esc_html__( 'File', 'wp-theme-audit' ) . '</th><th>' . esc_html__( 'Line', 'wp-theme-audit' ) . '</th><th>' . esc_html__( 'Issue', 'wp-theme-audit' ) . '</th></tr></thead><tbody>';
                foreach ( $findings as $finding ) {
                    printf(
                        '<tr><td><strong>%s</strong></td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
                        esc_html( ucfirst( $finding['severity'] ) ),
                        esc_html( $finding['file'] ),
                        $finding['line'] > 0 ? (int) $finding['line'] : '&mdash;',
                        esc_html( $finding['message'] )
                    );
                }
                echo '</tbody></table>';
            }
        }
        ?>
    </div>
    <?php
}

function wta_add_tools_page() {
    add_management_page(
        __( 'Theme Audit', 'wp-theme-audit' ),
        __( 'Theme Audit', 'wp-theme-audit' ),
        'manage_options',
        'wta-theme-audit',
        'wta_tools_page'
    );
}
add_action( 'admin_menu', 'wta_add_tools_page' );
