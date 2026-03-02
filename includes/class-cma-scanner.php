<?php
if (!defined('ABSPATH')) exit;

final class CMA_Scanner {

    /**
     * Lance le scan global du maillage interne.
     * Optimisé pour Gutenberg, les shortcodes et les liens relatifs.
     */
    public function run_global_scan(): array {
        
        // 1. Récupération de tous les contenus concernés
        $all_posts = get_posts([
            'post_type'      => ['post', 'page'],
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ]);

        $home_url = home_url();
        $items = [];
        $out_internal = [];
        $incoming_global = [];
        $out_external_count = [];
        $edges = [];
        
        // Listes spécifiques pour les articles (posts_only)
        $post_ids_only = [];
        $posts_only_out = [];
        $posts_only_in = [];

        // 2. Initialisation des structures de données
        foreach ($all_posts as $p) {
            $items[$p->ID] = [
                'id'    => $p->ID,
                'type'  => $p->post_type,
                'title' => $p->post_title,
                'url'   => get_permalink($p->ID),
            ];
            $out_internal[$p->ID] = [];
            $incoming_global[$p->ID] = 0;
            $out_external_count[$p->ID] = 0;

            if ($p->post_type === 'post') {
                $post_ids_only[] = $p->ID;
                $posts_only_out[$p->ID] = [];
                $posts_only_in[$p->ID] = 0;
            }
        }

        // 3. Analyse du contenu de chaque page/article
        foreach ($all_posts as $post) {

            // CRUCIAL : On applique les filtres pour rendre les blocs Gutenberg et Shortcodes
            // Sans cela, un lien dans un bouton Gutenberg est invisible pour le scanner.
            $content = apply_filters('the_content', $post->post_content);
            if (empty($content)) continue;

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            
            // On force l'encodage UTF-8 pour éviter les problèmes avec les accents français
            $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $content;
            @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            $xpath = new DOMXPath($dom);
            $hrefs = $xpath->evaluate("//a/@href");

            foreach ($hrefs as $href) {
                $url = trim($href->nodeValue);
                
                // Ignorer les ancres vides, les mails et les numéros de téléphone
                if (empty($url) || strpos($url, '#') === 0 || strpos($url, 'mailto:') === 0 || strpos($url, 'tel:') === 0) {
                    continue;
                }

                // Vérifier si le lien est interne (commence par l'URL du site ou par '/')
                $is_relative = (strpos($url, '/') === 0 && strpos($url, '//') !== 0);
                $is_absolute_internal = (strpos($url, $home_url) !== false);

                if ($is_absolute_internal || $is_relative) {
                    
                    // Conversion de l'URL en ID de post
                    $target_id = url_to_postid($url);

                    // Si url_to_postid échoue (souvent avec les liens relatifs), on tente de reconstruire l'URL
                    if (!$target_id && $is_relative) {
                        $target_id = url_to_postid(user_trailingslashit($home_url . $url));
                    }

                    if ($target_id && isset($items[$target_id]) && $target_id != $post->ID) {
                        
                        // Enregistrement pour le maillage global
                        $out_internal[$post->ID][] = $target_id;
                        $edges[] = ['from' => $post->ID, 'to' => $target_id];

                        // Enregistrement spécifique si c'est un lien entre deux ARTICLES (post_type = post)
                        if (in_array($post->ID, $post_ids_only) && in_array($target_id, $post_ids_only)) {
                            $posts_only_out[$post->ID][] = $target_id;
                        }
                    }

                } else {
                    // C'est un lien externe
                    if (strpos($url, 'http') === 0) {
                        $out_external_count[$post->ID]++;
                    }
                }
            }

            // Nettoyage des doublons pour ce post
            $out_internal[$post->ID] = array_unique($out_internal[$post->ID]);
            if (isset($posts_only_out[$post->ID])) {
                $posts_only_out[$post->ID] = array_unique($posts_only_out[$post->ID]);
            }
            
            // Libérer la mémoire
            unset($dom, $xpath);
        }

        // 4. Calcul final des compteurs de liens entrants (In-links)
        // Global
        foreach ($out_internal as $from_id => $targets) {
            foreach ($targets as $target_id) {
                if (isset($incoming_global[$target_id])) {
                    $incoming_global[$target_id]++;
                }
            }
        }
        // Articles uniquement
        foreach ($posts_only_out as $from_id => $targets) {
            foreach ($targets as $target_id) {
                if (isset($posts_only_in[$target_id])) {
                    $posts_only_in[$target_id]++;
                }
            }
        }

        return [
            'generated_at'       => time(),
            'items'              => $items,
            'out_internal'       => $out_internal,
            'incoming_global'    => $incoming_global,
            'out_external_count' => $out_external_count,
            'edges'              => $edges,
            'posts_only_out'     => $posts_only_out,
            'posts_only_in'      => $posts_only_in,
        ];
    }
}