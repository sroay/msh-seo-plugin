<?php
/**
 * MSH Redirects - 301/302 redirect manager with 404 logging.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Redirects {

    /**
     * Create the plugin's tables.
     *
     * WHY THIS IS TWO dbDelta CALLS AND HAS NO "IF NOT EXISTS".
     *
     * It used to be one call with both statements, each written as
     * CREATE TABLE IF NOT EXISTS. dbDelta keys the queries it is given by table
     * name, and it finds that name with:
     *
     *     preg_match( '|CREATE TABLE ([^ ]*)|', $qry, $matches )
     *
     * Against "CREATE TABLE IF NOT EXISTS wp_msh_redirects" that captures IF,
     * not the table. Both statements therefore keyed on "IF", the second
     * overwrote the first in the array, and only the 404 log was ever created.
     * Silently: dbDelta returns normally and reports nothing.
     *
     * The consequence ran for the life of the plugin. wp_msh_redirects did not
     * exist, so no redirect could be stored, so 2,173 hits piled up in the 404
     * log with the tool to fix them sitting one table away. dbDelta handles
     * "already exists" by itself, which is what IF NOT EXISTS was reaching for.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$wpdb->prefix}msh_redirects (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url VARCHAR(500) NOT NULL,
            target_url VARCHAR(500) NOT NULL,
            redirect_type INT(3) NOT NULL DEFAULT 301,
            hits BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            note VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_url (source_url(191))
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}msh_404_log (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            url VARCHAR(500) NOT NULL,
            referrer VARCHAR(500) DEFAULT '',
            user_agent VARCHAR(500) DEFAULT '',
            hits BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            last_hit DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY url (url(191))
        ) {$charset_collate};" );
    }

    /**
     * Repair the schema if it is missing, without waiting for a reactivation.
     *
     * create_tables() runs on register_activation_hook only, so a table added
     * or fixed in a later release never appears on a site that merely updated
     * the plugin — and nobody deactivates a live plugin to find out.
     *
     * This checks the thing itself rather than a version stamp. A stamp would
     * not have helped here: these sites already record 1.0.5, so a version
     * comparison sees nothing to do while the table is still absent. Asking the
     * database what exists is the only check that catches a state the version
     * number lies about.
     *
     * Guarded by a transient so it costs one SHOW TABLES a day, and re-checked
     * immediately whenever the plugin version changes.
     */
    public static function ensure_schema() {
        global $wpdb;

        if ( get_transient( 'msh_seo_schema_ok' ) === MSH_SEO_VERSION ) {
            return;
        }

        $missing = array();
        foreach ( array( 'msh_redirects', 'msh_404_log' ) as $suffix ) {
            $table = $wpdb->prefix . $suffix;
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- name from $wpdb->prefix
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $found !== $table ) {
                $missing[] = $table;
            }
        }

        // Run dbDelta on EVERY version change, not only when a whole table is
        // absent. A release that adds a COLUMN — as 1.1.0 does, for the note
        // that makes auto-created rules revertible — leaves the table present
        // but out of date, which the missing-table check above cannot see.
        // dbDelta is idempotent, so on an already-correct schema this costs one
        // DESCRIBE per table, once per release.
        if ( $missing || get_option( 'msh_seo_schema_version' ) !== MSH_SEO_VERSION ) {
            self::create_tables();
            update_option( 'msh_seo_schema_version', MSH_SEO_VERSION, false );

            // Leave a trace. A repair that happens invisibly is indistinguishable
            // from a bug that was never there, and the next person debugging
            // this deserves to know the table was rebuilt and when.
            $history   = get_option( 'msh_seo_schema_repairs', array() );
            $history[] = array(
                'at'      => current_time( 'mysql', true ),
                'version' => MSH_SEO_VERSION,
                'created' => $missing,
            );
            update_option( 'msh_seo_schema_repairs', array_slice( $history, -20 ), false );
        }

        set_transient( 'msh_seo_schema_ok', MSH_SEO_VERSION, DAY_IN_SECONDS );
    }

    public static function init() {
        /*
         * Priority 9: BEFORE core's redirect_canonical, which sits at 10.
         *
         * At the default priority this ran after core, and core got there
         * first. redirect_guess_404_permalink() fuzzy-matches an unknown path
         * against post slugs and redirects to whatever it likes the look of,
         * so an explicit rule for that exact URL never got a chance. Measured
         * on marketingsohigh.com/blog: 26 of 27 rules had never fired once,
         * including after every one of them was requested directly.
         *
         * It also cost a hop on every redirect that did resolve. Core guesses
         * without a trailing slash, so its guess then needs a second canonical
         * redirect to add one — 301, 301, 200 where 301, 200 was available.
         *
         * An exact source match is a deliberate statement about where a URL
         * should go. It outranks a guess.
         */
        add_action( 'template_redirect', array( __CLASS__, 'process_redirects' ), 9 );
        // Cheap (one transient read) on almost every request, and the only
        // thing that gets a broken install back on its feet unattended.
        add_action( 'admin_init', array( __CLASS__, 'ensure_schema' ) );
    }

    public static function process_redirects() {
        if ( is_admin() ) {
            return;
        }
        global $wpdb;
        $table   = $wpdb->prefix . 'msh_redirects';
        $request = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
        if ( empty( $request ) ) return;

        $path  = (string) wp_parse_url( $request, PHP_URL_PATH );
        $query = (string) wp_parse_url( $request, PHP_URL_QUERY );

        /*
         * On a subfolder install, try the path with the install prefix removed
         * as well.
         *
         * REQUEST_URI always carries it — /blog/growth-tactics — while a rule
         * may perfectly reasonably be written as /growth-tactics, which is how
         * the URL reads to anyone thinking in terms of the blog rather than
         * the domain. Those two never met, so on marketingsohigh.com/blog 26
         * of 27 rules had never fired once, including after every one of them
         * was requested directly. The 404 log itself holds both forms, 40
         * prefixed and 95 not, so this was never going to be fixable by being
         * stricter at the point rules are written.
         *
         * A subfolder install is an ordinary customer layout, so this was not
         * an edge case — it was the redirect engine not working on a whole
         * class of site.
         */
        $relative = '';
        $home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
        $home_path = rtrim( (string) $home_path, '/' );
        if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
            $relative = substr( $path, strlen( $home_path ) );
        }

        /*
         * Match the exact URI first, then the path alone.
         *
         * REQUEST_URI carries the query string, so a rule stored as
         * /blog/audit never matched /blog/audit?utm_source=newsletter. That is
         * not an edge case here: MSH appends UTM parameters to every link it
         * distributes, so the shared links most likely to be followed were
         * exactly the ones bypassing every redirect. Trying the full URI first
         * keeps it possible to author a rule that depends on the query.
         */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix
        // Most specific first: the full URI, then the path, then the path with
        // the subfolder prefix removed. '' never matches a stored source, so
        // the third slot is inert on a root install.
        $redirect = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, source_url, target_url, redirect_type FROM {$table}
              WHERE source_url IN ( %s, %s, %s )
           ORDER BY CASE
                      WHEN source_url = %s THEN 0
                      WHEN source_url = %s THEN 1
                      ELSE 2
                    END
              LIMIT 1",
            $request,
            $path,
            '' !== $relative ? $relative : '\0no-match',
            $request,
            $path
        ) );

        if ( $redirect ) {
            $target = $redirect->target_url;

            // Carry the query across when the match was on path alone.
            // Dropping it would silently destroy campaign attribution on every
            // redirected visit — the reader arrives, but the visit stops being
            // traceable to whatever sent them.
            // The relative form counts as "matched on path alone" too. Without
            // it, every redirect the subfolder fix just brought to life would
            // drop its query string — which is the same attribution loss this
            // block exists to prevent, reintroduced through the side door.
            if ( '' !== $query
                && ( $redirect->source_url === $path
                    || ( '' !== $relative && $redirect->source_url === $relative ) )
                && false === strpos( $target, '?' ) ) {
                $target .= '?' . $query;
            }

            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1 WHERE id = %d", $redirect->id ) );
            // wp_redirect, not wp_safe_redirect, on purpose.
            //
            // wp_safe_redirect() refuses any host but this one, and sending an
            // old URL to somewhere else is precisely what a redirect engine is
            // for — a retired page pointing at a partner, a moved section on
            // another domain. Restricting it here would silently drop rules the
            // site owner deliberately created.
            //
            // The target is not attacker-controlled: it is a row in this
            // plugin's own table, writable only by an administrator, and it is
            // passed through esc_url_raw() on its way out. exit follows on the
            // next line so nothing runs after the header.
            wp_redirect( esc_url_raw( $target ), (int) $redirect->redirect_type ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- see above; admin-authored target, external destinations are the feature.
            exit;
        }

        if ( is_404() ) {
            $log_table = $wpdb->prefix . 'msh_404_log';
            $referrer  = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) );
            $ua        = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

            // Never count our own probe.
            //
            // The daily beacon fetches each dead URL to see whether it has
            // come back. Those requests 404 by definition -- that is what
            // makes them worth probing -- and every one was landing here as a
            // fresh hit on the very URL it was checking.
            //
            // The result was a loop that fed itself: the log ranks by hits,
            // the probe list is drawn from the top of that ranking, so the
            // same URLs gained a hit a day and stayed pinned at the top of
            // the queue a person reads. Measured on believele.com, /jobs,
            // /post-a-job and /mobile-app had accumulated 119, 125 and 93
            // hits almost entirely this way, and read as the site's worst
            // broken links when nobody had clicked them at all.
            if ( false !== strpos( $ua, 'MSH-SEO-WP/' ) ) {
                return;
            }

            // Log the PATH. Keyed on the full URI, a single dead link becomes
            // a separate row per campaign parameter, and the ranking by hits
            // that makes this log actionable falls apart.
            $logged = '' !== $path ? $path : $request;

            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$log_table} WHERE url = %s LIMIT 1", $logged ) );

            if ( $existing ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$log_table} SET hits = hits + 1, last_hit = NOW(), referrer = %s, user_agent = %s WHERE id = %d",
                    $referrer, $ua, $existing
                ) );
            } else {
                $wpdb->insert( $log_table, array(
                    'url' => $logged, 'referrer' => $referrer, 'user_agent' => $ua,
                    'hits' => 1, 'last_hit' => current_time( 'mysql' ),
                ), array( '%s', '%s', '%s', '%d', '%s' ) );
            }
        }
    }

    public static function render_admin_page() {
        global $wpdb;

        if ( isset( $_POST['msh_redirect_action'] ) && check_admin_referer( 'msh_redirects_nonce' ) ) {
            // Unslash BEFORE sanitising. WordPress adds slashes to every
            // superglobal, so sanitising first leaves the escapes baked into
            // the stored value — a URL with an apostrophe comes back wrong.
            $action = sanitize_text_field( wp_unslash( $_POST['msh_redirect_action'] ) );
            if ( 'add' === $action ) {
                $source = sanitize_text_field( wp_unslash( $_POST['source_url'] ?? '' ) );
                $target = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );
                $type   = in_array( (int) ( $_POST['redirect_type'] ?? 301 ), array( 301, 302 ), true ) ? (int) $_POST['redirect_type'] : 301;
                if ( $source && $target ) {
                    $wpdb->replace( $wpdb->prefix . 'msh_redirects', array(
                        'source_url' => $source, 'target_url' => $target, 'redirect_type' => $type,
                    ), array( '%s', '%s', '%d' ) );
                    echo '<div class="notice notice-success"><p>Redirect saved.</p></div>';
                }
            } elseif ( 'delete' === $action ) {
                $id = absint( $_POST['redirect_id'] ?? 0 );
                if ( $id ) {
                    $wpdb->delete( $wpdb->prefix . 'msh_redirects', array( 'id' => $id ), array( '%d' ) );
                    echo '<div class="notice notice-success"><p>Redirect deleted.</p></div>';
                }
            } elseif ( 'clear_log' === $action ) {
                // Table name is safe — built from $wpdb->prefix.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}msh_404_log" );
                echo '<div class="notice notice-success"><p>404 log cleared.</p></div>';
            }
        }

        // Table names are safe — built from $wpdb->prefix.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $redirects = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}msh_redirects ORDER BY created_at DESC LIMIT 100" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $log       = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}msh_404_log ORDER BY last_hit DESC LIMIT 50" );

        echo '<h2>Add Redirect</h2>';
        echo '<form method="post">';
        wp_nonce_field( 'msh_redirects_nonce' );
        echo '<input type="hidden" name="msh_redirect_action" value="add" />';
        echo '<table class="form-table">';
        echo '<tr><th><label for="source_url">Source URL</label></th><td><input type="text" id="source_url" name="source_url" class="regular-text" placeholder="/old-page" required /></td></tr>';
        echo '<tr><th><label for="target_url">Target URL</label></th><td><input type="text" id="target_url" name="target_url" class="regular-text" placeholder="/new-page" required /></td></tr>';
        echo '<tr><th><label for="redirect_type">Type</label></th><td><select id="redirect_type" name="redirect_type"><option value="301">301 Permanent</option><option value="302">302 Temporary</option></select></td></tr>';
        echo '</table>';
        submit_button( 'Add Redirect' );
        echo '</form>';

        echo '<h2>Active Redirects</h2>';
        if ( $redirects ) {
            echo '<table class="widefat fixed striped"><thead><tr><th>Source</th><th>Target</th><th>Type</th><th>Hits</th><th>Actions</th></tr></thead><tbody>';
            foreach ( $redirects as $r ) {
                echo '<tr>';
                echo '<td>' . esc_html( $r->source_url ) . '</td>';
                echo '<td>' . esc_html( $r->target_url ) . '</td>';
                echo '<td>' . esc_html( $r->redirect_type ) . '</td>';
                echo '<td>' . esc_html( $r->hits ) . '</td>';
                echo '<td><form method="post" style="display:inline;">';
                wp_nonce_field( 'msh_redirects_nonce' );
                echo '<input type="hidden" name="msh_redirect_action" value="delete" />';
                echo '<input type="hidden" name="redirect_id" value="' . esc_attr( $r->id ) . '" />';
                echo '<button type="submit" class="button button-small">Delete</button>';
                echo '</form></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No redirects configured.</p>';
        }

        echo '<h2>404 Log</h2>';
        if ( $log ) {
            echo '<form method="post" style="margin-bottom: 10px;">';
            wp_nonce_field( 'msh_redirects_nonce' );
            echo '<input type="hidden" name="msh_redirect_action" value="clear_log" />';
            echo '<button type="submit" class="button">Clear Log</button>';
            echo '</form>';
            echo '<table class="widefat fixed striped"><thead><tr><th>URL</th><th>Hits</th><th>Last Hit</th><th>Referrer</th></tr></thead><tbody>';
            foreach ( $log as $entry ) {
                echo '<tr>';
                echo '<td>' . esc_html( $entry->url ) . '</td>';
                echo '<td>' . esc_html( $entry->hits ) . '</td>';
                echo '<td>' . esc_html( $entry->last_hit ) . '</td>';
                echo '<td>' . esc_html( $entry->referrer ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No 404 errors logged.</p>';
        }
    }
}
