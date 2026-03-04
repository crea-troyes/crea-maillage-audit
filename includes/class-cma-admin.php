<?php
if (!defined('ABSPATH')) exit;

final class CMA_Admin {

    private string $slug = 'cma-maillage';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_cma_run_scan', [$this, 'ajax_run_scan']);
        add_action('wp_ajax_cma_clear_scan', [$this, 'ajax_clear_scan']);
    }

    public function register_menu(): void {
        add_submenu_page(
            'tools.php',
            __('Internal Linking Audit', 'crea-maillage-audit'),
            __('Linking', 'crea-maillage-audit'),
            'manage_options',
            $this->slug,
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void {

        $expected = 'tools_page_' . $this->slug;
        if ($hook !== $expected) return;

        wp_enqueue_script(
            'vis-network',
            'https://unpkg.com/vis-network@9.1.2/dist/vis-network.min.js',
            [],
            null,
            true
        );

        wp_enqueue_script(
            'cma-admin',
            CMA_URL . 'assets/admin.js',
            ['jquery', 'vis-network'],
            CMA_VERSION,
            true
        );

        wp_enqueue_style('cma-admin', CMA_URL . 'assets/admin.css', [], CMA_VERSION);

        wp_localize_script('cma-admin', 'CMA', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cma_scan_nonce'),
        ]);
    }

    public function render_page(): void {

        if (!current_user_can('manage_options')) {
            wp_die(__('Access denied.', 'crea-maillage-audit'));
        }

        $data = get_option(CMA_OPTION_KEY);
        $has_data = is_array($data) && !empty($data['items']);

        $view   = isset($_GET['view']) ? sanitize_key((string)$_GET['view']) : 'table';
        $filter = isset($_GET['filter']) ? sanitize_key((string)$_GET['filter']) : 'both';

        echo '<div class="wrap cma-wrap">';
        echo '<h1>' . esc_html__('Internal Linking Audit', 'crea-maillage-audit') . '</h1>';

        echo '<p class="description">';
        echo esc_html__('Manual scan. Results are cached to avoid any front-end performance impact.', 'crea-maillage-audit');
        echo '</p>';

        echo '<div class="cma-controls">';
        echo '<div class="cma-block cma-filtre">';
        echo '<strong>' . esc_html__('Display filter:', 'crea-maillage-audit') . '</strong> ';

        echo $this->radio_link('filter', 'post', __('Posts', 'crea-maillage-audit'), $filter);
        echo $this->radio_link('filter', 'page', __('Pages', 'crea-maillage-audit'), $filter);
        echo $this->radio_link('filter', 'both', __('Posts + Pages', 'crea-maillage-audit'), $filter);

        echo '</div>';

        echo '<div class="cma-block">';
        echo '<button class="button button-primary" id="cma-run-scan">'
            . esc_html__('Run scan', 'crea-maillage-audit')
            . '</button> ';

        echo '<button class="button" id="cma-clear-scan">'
            . esc_html__('Clear cache', 'crea-maillage-audit')
            . '</button> ';

        echo '<span class="cma-status" id="cma-status"></span>';
        echo '</div>';

        echo '</div>';

        $base_url = menu_page_url($this->slug, false);

        $tab = static function(string $k, string $label) use ($base_url, $view, $filter) {
            $cls = ($view === $k) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url = add_query_arg(['view' => $k, 'filter' => $filter], $base_url);
            echo '<a class="'.esc_attr($cls).'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        };

        $analyzer = null;
        $table_count = 0;
        $orphans_count = 0;
        $isolated_count = 0;

        if ($has_data) {
            $analyzer = new CMA_Analyzer($data);

            $table_count    = count($analyzer->get_table_rows($filter));
            $orphans_count  = count($analyzer->get_orphans_global($filter));
            $isolated_count = count($analyzer->get_isolated_posts());
        }

        echo '<h2 class="nav-tab-wrapper">';

        $tab('table', sprintf(__('Table (%d)', 'crea-maillage-audit'), $table_count));
        $tab('orphans', sprintf(__('Global orphans (%d)', 'crea-maillage-audit'), $orphans_count));
        $tab('isolated_posts', sprintf(__('Isolated posts (%d)', 'crea-maillage-audit'), $isolated_count));
        $tab('graph', __('Graph view', 'crea-maillage-audit'));

        echo '</h2>';

        if (!$has_data) {
            echo '<div class="notice notice-warning"><p>'
                . sprintf(
                    __('No cached analysis found. Click %s.', 'crea-maillage-audit'),
                    '<strong>' . esc_html__('Run scan', 'crea-maillage-audit') . '</strong>'
                )
                . '</p></div>';

            echo '</div>';
            return;
        }

        $analyzer = new CMA_Analyzer($data);

        if ($view === 'orphans') {

            $rows = $analyzer->get_orphans_global($filter);
            $this->render_table($rows, __('Content without internal incoming links based on current filter.', 'crea-maillage-audit'));

        } elseif ($view === 'isolated_posts') {

            $rows = $analyzer->get_isolated_posts();
            $this->render_table($rows, __('Posts without post-to-post linking. Filter not applied on this tab.', 'crea-maillage-audit'));

        } elseif ($view === 'graph') {

            $graph = $analyzer->get_graph_payload($filter);

            echo "<p>" . esc_html__('You can zoom and drag the graph.', 'crea-maillage-audit') . "</p>";

            echo '<div id="cma-graph-wrapper" style="position:relative; height:600px; background:#fff; border:1px solid #ccc;">';
            echo '<div id="cma-graph" style="height:100%;" data-graph=\''.esc_attr(wp_json_encode($graph)).'\'></div>';
            echo '</div>';

        } else {

            $rows = $analyzer->get_table_rows($filter);
            $this->render_table($rows, __('Global overview based on current filter.', 'crea-maillage-audit'));
        }

        if (!empty($data['generated_at'])) {
            echo '<p class="cma-meta">'
                . sprintf(
                    __('Last scan: %s', 'crea-maillage-audit'),
                    '<strong>' . esc_html(date_i18n('d/m/Y H:i', (int)$data['generated_at'])) . '</strong>'
                )
                . '</p>';
        }

        echo '</div>';
    }

    private function radio_link(string $key, string $value, string $label, string $current): string {

        $base_url = menu_page_url($this->slug, false);

        $url = add_query_arg([
            'view' => isset($_GET['view']) ? sanitize_key((string)$_GET['view']) : 'table',
            $key   => $value,
        ], $base_url);

        $checked = ($current === $value) ? 'checked' : '';

        return '<label class="cma-radio">
            <input type="radio" name="'.esc_attr($key).'" '.$checked.' 
            onclick="window.location.href=\''.esc_url($url).'\'"> 
            '.esc_html($label).'
        </label> ';
    }

    private function render_table(array $rows, string $intro): void {

        $view = isset($_GET['view']) ? sanitize_key((string)$_GET['view']) : 'table';

        $col_in = ($view === "isolated_posts")
            ? "<th>" . esc_html__('Incoming links (posts)', 'crea-maillage-audit') . "</th>
               <th>" . esc_html__('Incoming links (pages)', 'crea-maillage-audit') . "</th>"
            : "<th>" . esc_html__('Incoming links (posts + pages)', 'crea-maillage-audit') . "</th>";

        echo '<p>'.esc_html($intro).'</p>';

        echo '<div class="cma-table-wrap">';
        echo '<table id="tableau" class="widefat cma-dynamic-table striped">';
        echo '<tr>';
        echo '<th>' . esc_html__('Type', 'crea-maillage-audit') . '</th>';
        echo '<th>' . esc_html__('Title', 'crea-maillage-audit') . '</th>';
        echo '<th>' . esc_html__('Internal outgoing links', 'crea-maillage-audit') . '</th>';
        echo $col_in;
        echo '<th>' . esc_html__('External outgoing links', 'crea-maillage-audit') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($rows)) {

            echo '<tr><td colspan="6"><em>'
                . esc_html__('No results found.', 'crea-maillage-audit')
                . '</em></td></tr>';

        } else {

            foreach ($rows as $r) {

                echo '<tr>';

                $type = $r['type'] === 'Article' ? 'post' : 'page';

                echo '<td><span class="cma-badge cma-badge-'.$type.'">'
                    . esc_html($r['type'])
                    . '</span></td>';

                $title_class = !empty($r['is_isolated']) ? 'cma-isolated' : '';

                echo '<td>';

                $site_url     = home_url();
                $relative_url = str_replace($site_url, '', $r['url']);

                echo '<strong class="'.esc_attr($title_class).'">'
                    . esc_html($r['title'])
                    . '</strong><br>';

                echo '<small><a class="link_post_cma" href="'
                    . esc_url($r['url'])
                    . '" target="_blank" rel="noopener">'
                    . esc_html($relative_url)
                    . '</a></small>';

                echo '</td>';

                echo '<td><span class="cma-badge out">'
                    . esc_html((string)$r['out_int'])
                    . '</span></td>';

                if ($view === "isolated_posts") {

                    echo '<td><span class="cma-badge in">'
                        . esc_html((string)$r['in_int'])
                        . '</span></td>';

                    echo '<td><span class="cma-badge inpage">'
                        . esc_html((string)$r['inpage_int'])
                        . '</span></td>';

                } else {

                    echo '<td><span class="cma-badge in">'
                        . esc_html((string)$r['in_int'])
                        . '</span></td>';
                }

                echo '<td><span class="cma-badge ext">'
                    . esc_html((string)$r['out_ext'])
                    . '</span></td>';

                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function ajax_run_scan(): void {

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Access denied.', 'crea-maillage-audit')
            ], 403);
        }

        check_ajax_referer('cma_scan_nonce', 'nonce');

        @set_time_limit(600);

        $scanner = new CMA_Scanner();
        $data    = $scanner->run_global_scan();

        update_option(CMA_OPTION_KEY, $data, false);

        wp_send_json_success([
            'message' => __('Scan completed successfully.', 'crea-maillage-audit'),
            'counts'  => [
                'items' => count($data['items'] ?? []),
                'edges' => count($data['edges'] ?? []),
            ]
        ]);
    }

    public function ajax_clear_scan(): void {

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Access denied.', 'crea-maillage-audit')
            ], 403);
        }

        check_ajax_referer('cma_scan_nonce', 'nonce');

        delete_option(CMA_OPTION_KEY);

        wp_send_json_success([
            'message' => __('Cache cleared successfully.', 'crea-maillage-audit')
        ]);
    }
}