<?php
/**
 * Plugin Name: EMA SEO Meta Importer
 * Description: Bulk-sets Yoast SEO titles and meta descriptions from a CSV (url,new_title,new_description). Dry run first, backs up old values, purges LiteSpeed per post.
 * Version: 1.0.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

const EMA_SMI_MAX_TITLE = 54;
const EMA_SMI_MAX_DESC  = 154;

add_action('admin_menu', function () {
    add_management_page('EMA SEO Meta Importer', 'EMA SEO Meta Importer', 'manage_options', 'ema-seo-meta-importer', 'ema_smi_page');
});

function ema_smi_resolve($url) {
    $url = trim($url);
    $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
    if ($path === '') {
        $front = (int) get_option('page_on_front');
        return $front ?: 0;
    }
    $id = url_to_postid(home_url('/' . $path . '/'));
    if (!$id) {
        $p = get_page_by_path($path, OBJECT, array('page', 'post'));
        $id = $p ? $p->ID : 0;
    }
    return (int) $id;
}

function ema_smi_page() {
    if (!current_user_can('manage_options')) wp_die('Not allowed');
    echo '<div class="wrap"><h1>EMA SEO Meta Importer</h1>';
    $rows = null; $apply = false;
    if (!empty($_POST['ema_smi_nonce']) && wp_verify_nonce($_POST['ema_smi_nonce'], 'ema_smi') && !empty($_FILES['csv']['tmp_name'])) {
        $apply = isset($_POST['apply']) && $_POST['apply'] === '1';
        $limit = isset($_POST['limit']) ? max(0, (int) $_POST['limit']) : 0; // 0 = all
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        $head = fgetcsv($fh);
        if ($head) { $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]); }
        $idx = $head ? array_flip($head) : array();
        if (!isset($idx['url'], $idx['new_title'], $idx['new_description'])) {
            echo '<div class="notice notice-error"><p>CSV needs columns: url, new_title, new_description</p></div>';
        } else {
            $rows = array(); $n = 0;
            while (($r = fgetcsv($fh)) !== false) {
                if ($limit && $n >= $limit) break;
                $n++;
                $url = $r[$idx['url']]; $t = trim($r[$idx['new_title']]); $d = trim($r[$idx['new_description']]);
                $id = ema_smi_resolve($url); $status = 'OK';
                if (!$id) $status = 'NOT FOUND';
                elseif (mb_strlen($t) > EMA_SMI_MAX_TITLE) $status = 'TITLE TOO LONG';
                elseif (mb_strlen($d) > EMA_SMI_MAX_DESC) $status = 'DESC TOO LONG';
                elseif ($t === '' || $d === '') $status = 'EMPTY';
                if ($apply && $status === 'OK') {
                    $old_t = get_post_meta($id, '_yoast_wpseo_title', true);
                    $old_d = get_post_meta($id, '_yoast_wpseo_metadesc', true);
                    if (!metadata_exists('post', $id, '_ema_smi_backup')) {
                        update_post_meta($id, '_ema_smi_backup', array('title' => $old_t, 'desc' => $old_d, 'time' => current_time('mysql')));
                    }
                    update_post_meta($id, '_yoast_wpseo_title', wp_slash($t));
                    update_post_meta($id, '_yoast_wpseo_metadesc', wp_slash($d));
                    do_action('litespeed_purge_post', $id);
                    $status = 'UPDATED';
                }
                $rows[] = array($url, $id, $t, $d, $status);
            }
        }
        fclose($fh);
    }
    if ($rows !== null) {
        $ok = count(array_filter($rows, function ($r) { return in_array($r[4], array('OK', 'UPDATED'), true); }));
        echo '<h2>' . ($apply ? 'Applied' : 'Dry run (nothing changed)') . ': ' . $ok . ' of ' . count($rows) . ' rows OK</h2>';
        echo '<table class="widefat striped"><thead><tr><th>URL</th><th>Post ID</th><th>Title</th><th>Description</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . esc_html($r[0]) . '</td><td>' . (int) $r[1] . '</td><td>' . esc_html($r[2]) . ' (' . mb_strlen($r[2]) . ')</td><td>' . esc_html($r[3]) . ' (' . mb_strlen($r[3]) . ')</td><td><b>' . esc_html($r[4]) . '</b></td></tr>';
        }
        echo '</tbody></table><hr>';
    }
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('ema_smi', 'ema_smi_nonce');
    echo '<p><input type="file" name="csv" accept=".csv" required></p>';
    echo '<p><label>Only first N rows (0 = all; use 1 to test): <input type="number" name="limit" value="1" min="0" style="width:80px"></label></p>';
    echo '<p><label><input type="radio" name="apply" value="0" checked> Dry run</label> &nbsp; <label><input type="radio" name="apply" value="1"> Apply changes</label></p>';
    echo '<p><button class="button button-primary">Run</button></p></form>';
    echo '<p>Old values are saved per post in the <code>_ema_smi_backup</code> custom field. Purge Cloudflare cache manually afterwards.</p></div>';
}
