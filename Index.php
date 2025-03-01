<?php
/**
 * Plugin Name: Anime Importer
 * Description: Import anime details from MyAnimeList using Jikan API.
 * Version: 1.0
 * Author: fr0zen
 * Text Domain: anime-importer
 */

if (!defined('ABSPATH')) exit; // Prevent direct access

class Jikan_Anime_Importer {

    public function __construct() {
        add_action('init', [$this, 'register_anime_cpt']);
        add_action('init', [$this, 'register_anime_genre_taxonomy']);
        add_action('admin_menu', [$this, 'create_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('add_meta_boxes', [$this, 'add_anime_meta_box']);
        add_action('save_post_anime', [$this, 'fetch_anime_data'], 10, 2);
        add_action('wp_ajax_jikan_search_anime', [$this, 'search_anime']);
    }

    /** Register Anime Custom Post Type */
    public function register_anime_cpt() {
        $args = [
            'labels' => ['name' => 'Anime', 'singular_name' => 'Anime'],
            'public' => true,
            'menu_icon' => 'dashicons-admin-site',
            'supports' => ['title', 'editor', 'thumbnail'],
            'taxonomies' => ['anime_genre'],
            'show_in_rest' => true,
        ];
        register_post_type('anime', $args);
    }

    /** Register Anime Genre Taxonomy */
    public function register_anime_genre_taxonomy() {
        register_taxonomy('anime_genre', 'anime', [
            'label' => 'Genres',
            'hierarchical' => true,
            'show_admin_column' => true,
            'show_in_rest' => true,
        ]);
    }

    /** Create Admin Settings Page */
    public function create_settings_page() {
        add_options_page('Jikan API Settings', 'Jikan API', 'manage_options', 'jikan-anime-importer', [$this, 'settings_page_html']);
    }

    /** Register API Settings */
    public function register_settings() {
        register_setting('jikan_settings_group', 'jikan_api_url');
        add_settings_section('jikan_settings_section', 'Jikan API Settings', null, 'jikan-anime-importer');
        add_settings_field('jikan_api_url', 'Jikan API URL', [$this, 'api_url_field_html'], 'jikan-anime-importer', 'jikan_settings_section');
    }

    /** Settings Page HTML */
    public function settings_page_html() {
        ?>
        <div class="wrap">
            <h1>Jikan API Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('jikan_settings_group');
                do_settings_sections('jikan-anime-importer');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /** API URL Input Field */
    public function api_url_field_html() {
        $api_url = get_option('jikan_api_url', 'https://api.jikan.moe/v4');
        echo '<input type="text" name="jikan_api_url" value="' . esc_attr($api_url) . '" class="regular-text">';
    }

    /** Add Meta Box */
    public function add_anime_meta_box() {
        add_meta_box('anime_meta', 'Anime Importer', [$this, 'anime_meta_box_html'], 'anime', 'side');
    }

    /** Meta Box HTML */
    public function anime_meta_box_html($post) {
        $mal_id = get_post_meta($post->ID, '_mal_id', true);
        ?>
        <input type="text" id="mal_id" name="mal_id" value="<?php echo esc_attr($mal_id); ?>" placeholder="Enter MAL Anime ID">
        <button type="button" id="search_anime">Search Anime</button>
        <div id="anime_results"></div>

        <script>
        jQuery(document).ready(function($) {
            $('#search_anime').on('click', function() {
                let query = prompt("Enter anime title:");
                if (!query) return;
                
                $.post(ajaxurl, {action: 'jikan_search_anime', query: query}, function(response) {
                    $('#anime_results').html(response);
                });
            });
        });
        </script>
        <?php
    }

    /** Fetch Anime Data from Jikan API */
    public function fetch_anime_data($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['mal_id'])) return;
        if ($post->post_type !== 'anime') return;

        $mal_id = sanitize_text_field($_POST['mal_id']);
        update_post_meta($post_id, '_mal_id', $mal_id);

        $api_url = get_option('jikan_api_url', 'https://api.jikan.moe/v4');
        $response = wp_remote_get("$api_url/anime/$mal_id/full");
        if (is_wp_error($response)) return;

        $anime = json_decode(wp_remote_retrieve_body($response), true);
        if (!$anime) return;

        wp_update_post([
            'ID' => $post_id,
            'post_title' => sanitize_text_field($anime['data']['title']),
            'post_content' => sanitize_textarea_field($anime['data']['synopsis']),
        ]);

        update_post_meta($post_id, '_release_date', sanitize_text_field($anime['data']['aired']['string']));
        update_post_meta($post_id, '_rating', sanitize_text_field($anime['data']['score']));
        update_post_meta($post_id, '_episodes', sanitize_text_field($anime['data']['episodes']));
        update_post_meta($post_id, '_studio', sanitize_text_field($anime['data']['studios'][0]['name'] ?? ''));
        update_post_meta($post_id, '_type', sanitize_text_field($anime['data']['type']));

        $genres = array_map(fn($g) => sanitize_text_field($g['name']), $anime['data']['genres']);
        wp_set_object_terms($post_id, $genres, 'anime_genre');

        if (!empty($anime['data']['images']['jpg']['image_url'])) {
            $this->set_post_thumbnail_from_url($post_id, $anime['data']['images']['jpg']['image_url']);
        }
    }

    /** Search Anime by Title */
    public function search_anime() {
        if (!isset($_POST['query'])) wp_send_json_error();
        $query = sanitize_text_field($_POST['query']);

        $api_url = get_option('jikan_api_url', 'https://api.jikan.moe/v4');
        $response = wp_remote_get("$api_url/anime?q=$query");
        if (is_wp_error($response)) wp_send_json_error();

        $anime_list = json_decode(wp_remote_retrieve_body($response), true);
        if (!$anime_list) wp_send_json_error();

        foreach ($anime_list['data'] as $anime) {
            echo "<div><a href='#' onclick='document.getElementById(\"mal_id\").value = \"{$anime['mal_id']}\"; return false;'>{$anime['title']}</a></div>";
        }
        wp_die();
    }

    /** Set Featured Image */
    private function set_post_thumbnail_from_url($post_id, $image_url) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $image_id = media_sideload_image($image_url, $post_id, null, 'id');
        if ($image_id) set_post_thumbnail($post_id, $image_id);
    }
}

new Jikan_Anime_Importer();
