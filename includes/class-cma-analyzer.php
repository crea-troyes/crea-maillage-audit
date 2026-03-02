<?php
if (!defined('ABSPATH')) exit;

final class CMA_Analyzer {

    private array $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function get_table_rows(string $filter): array {
        return $this->build_rows($filter, 'table');
    }

    public function get_orphans_global(string $filter): array {
        return $this->build_rows($filter, 'orphans');
    }

    public function get_isolated_posts(): array {

        $items = $this->data['items'] ?? [];
        $post_in  = $this->data['posts_only_in'] ?? [];
        $out_external_count = $this->data['out_external_count'] ?? [];
        $edges = $this->data['edges'] ?? [];

        $rows = [];

        $pages_incoming = [];

        foreach ($edges as $e) {

            $from = (int)($e['from'] ?? 0);
            $to   = (int)($e['to'] ?? 0);

            if (($items[$from]['type'] ?? '') !== 'page') {
                continue;
            }

            if (!isset($pages_incoming[$to])) {
                $pages_incoming[$to] = 0;
            }

            $pages_incoming[$to]++;
        }

        foreach ($post_in as $id => $incoming_count) {

            if (($items[$id]['type'] ?? '') !== 'post') {
                continue;
            }

            if ($incoming_count == 0) {

                $rows[] = [
                    'type' => 'Article',
                    'title' => $items[$id]['title'] ?? '',
                    'url' => $items[$id]['url'] ?? '',
                    'out_int' => count($this->data['posts_only_out'][$id] ?? []),
                    'in_int' => $post_in[$id] ?? 0,
                    'inpage_int' => $pages_incoming[$id] ?? 0,
                    'out_ext' => $out_external_count[$id] ?? 0,
                ];
            }
        }

        return $rows;
    }

    public function get_graph_payload(string $filter): array {
        $items = $this->data['items'] ?? [];
        $edges = $this->data['edges'] ?? [];
        $posts_only_in = $this->data['posts_only_in'] ?? [];

        $nodes = [];
        foreach ($items as $id => $it) {
            $type = $it['type'] ?? '';
            if (!$this->match_filter($type, $filter)) continue;

            // Détection si l'article est isolé (0 lien entrant provenant d'articles)
            $is_isolated = ($type === 'post' && ($posts_only_in[$id] ?? 0) == 0);

            $nodes[] = [
                'id' => (int)$id,
                'label' => $it['title'] ?? '',
                'type' => $type,
                'url' => $it['url'] ?? '',
                'is_isolated' => $is_isolated, 
            ];
        }

        $allowed = array_flip(array_map(fn($n) => $n['id'], $nodes));
        $filtered_edges = [];
        foreach ($edges as $e) {
            $from = (int)($e['from'] ?? 0);
            $to = (int)($e['to'] ?? 0);
            if (isset($allowed[$from], $allowed[$to])) {
                $filtered_edges[] = ['from' => $from, 'to' => $to];
            }
        }

        return [
            'nodes' => $nodes,
            'edges' => $filtered_edges,
        ];
    }

    private function build_rows(string $filter, string $mode): array {
        $items = $this->data['items'] ?? [];
        $incoming_global = $this->data['incoming_global'] ?? [];
        $out_internal = $this->data['out_internal'] ?? [];
        $out_external_count = $this->data['out_external_count'] ?? [];

        $rows = [];
        foreach ($items as $id => $it) {
            $type = $it['type'] ?? '';
            if (!$this->match_filter($type, $filter)) continue;

            $in = (int)($incoming_global[$id] ?? 0);
            $out_int = count($out_internal[$id] ?? []);
            $out_ext = (int)($out_external_count[$id] ?? 0);

            if ($mode === 'orphans' && $in !== 0) continue;

            $is_isolated = false;

            if ($type === 'post') {
                $posts_only_in = $this->data['posts_only_in'] ?? [];
                if (($posts_only_in[$id] ?? 0) == 0) {
                    $is_isolated = true;
                }
            }

            $rows[] = [
                'type' => ($type === 'page') ? 'Page' : 'Article',
                'title' => $it['title'] ?? '',
                'url' => $it['url'] ?? '',
                'out_int' => $out_int,
                'in_int' => $in,
                'out_ext' => $out_ext,
                'is_isolated' => $is_isolated,
            ];
        }

        return $rows;
    }

    private function match_filter(string $type, string $filter): bool {
        if ($filter === 'both') return in_array($type, ['post','page'], true);
        if ($filter === 'post') return $type === 'post';
        if ($filter === 'page') return $type === 'page';
        return true;
    }
}