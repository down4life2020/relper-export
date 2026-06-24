<?php
/**
 * Plugin Name: RELPER Export for RealHomes
 * Description: Generates a RELPER-compatible XML feed from RealHomes/Easy Real Estate property listings.
 * Version: 1.0.0
 * Author: Kreativa Nekretnine
 * Text Domain: relper-export
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Relper_Export_Plugin
{
    private const QUERY_VAR = 'relper_export';
    private const OPTION_NAME = 'relper_export_options';
    private const VERSION = '1.0.0';

    private static $instance = null;
    private array $location_rows = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'register_endpoint']);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'maybe_render_feed']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_links']);
    }

    public static function activate(): void
    {
        self::instance()->register_endpoint();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function register_endpoint(): void
    {
        add_rewrite_rule('^relper\.xml$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
    }

    public function register_query_var(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function maybe_render_feed(): void
    {
        $requested_by_query = isset($_GET['relper']) && sanitize_text_field(wp_unslash($_GET['relper'])) === '1';
        $requested_by_rewrite = get_query_var(self::QUERY_VAR) === '1';

        if (!$requested_by_query && !$requested_by_rewrite) {
            return;
        }

        $options = $this->get_options();
        $token = $options['access_token'];

        if ($token !== '') {
            $provided = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
            if (!hash_equals($token, $provided)) {
                status_header(403);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Forbidden';
                exit;
            }
        }

        nocache_headers();
        header('Content-Type: application/xml; charset=UTF-8');
        echo $this->build_xml();
        exit;
    }

    public function register_admin_page(): void
    {
        add_options_page(
            'RELPER Export',
            'RELPER Export',
            'manage_options',
            'relper-export',
            [$this, 'render_admin_page']
        );
    }

    public function register_settings(): void
    {
        register_setting('relper_export', self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_options'],
            'default' => $this->default_options(),
        ]);

        add_settings_section(
            'relper_export_main',
            'Feed settings',
            static function (): void {
                echo '<p>Configure the public XML feed that RELPER/Volvox imports.</p>';
            },
            'relper-export'
        );

        $fields = [
            'agency_name' => 'Agency name',
            'access_token' => 'Access token',
            'post_status' => 'Post status',
            'default_purpose_id' => 'Default transaction',
        ];

        foreach ($fields as $field => $label) {
            add_settings_field(
                $field,
                $label,
                [$this, 'render_field'],
                'relper-export',
                'relper_export_main',
                ['field' => $field]
            );
        }
    }

    public function sanitize_options($input): array
    {
        $defaults = $this->default_options();
        $input = is_array($input) ? $input : [];

        $post_status = sanitize_key($input['post_status'] ?? $defaults['post_status']);
        if (!in_array($post_status, ['publish', 'publish_trash'], true)) {
            $post_status = 'publish';
        }

        $default_purpose_id = sanitize_text_field($input['default_purpose_id'] ?? $defaults['default_purpose_id']);
        if (!in_array($default_purpose_id, ['1', '2'], true)) {
            $default_purpose_id = '2';
        }

        return [
            'agency_name' => sanitize_text_field($input['agency_name'] ?? $defaults['agency_name']),
            'access_token' => sanitize_text_field($input['access_token'] ?? ''),
            'post_status' => $post_status,
            'default_purpose_id' => $default_purpose_id,
        ];
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $feed_url = home_url('/relper.xml');
        $fallback_url = add_query_arg('relper', '1', home_url('/'));
        $options = $this->get_options();

        if ($options['access_token'] !== '') {
            $feed_url = add_query_arg('token', rawurlencode($options['access_token']), $feed_url);
            $fallback_url = add_query_arg('token', rawurlencode($options['access_token']), $fallback_url);
        }

        echo '<div class="wrap">';
        echo '<h1>RELPER Export</h1>';
        echo '<p><strong>Primary feed:</strong> <a href="' . esc_url($feed_url) . '" target="_blank" rel="noopener">' . esc_html($feed_url) . '</a></p>';
        echo '<p><strong>Fallback feed:</strong> <a href="' . esc_url($fallback_url) . '" target="_blank" rel="noopener">' . esc_html($fallback_url) . '</a></p>';
        echo '<form method="post" action="options.php">';
        settings_fields('relper_export');
        do_settings_sections('relper-export');
        submit_button();
        echo '</form>';
        echo '<p>Optional location mapping: upload RELPER CSV as <code>wp-content/plugins/relper-export/locations.csv</code>.</p>';
        echo '</div>';
    }

    public function render_field(array $args): void
    {
        $field = $args['field'];
        $options = $this->get_options();
        $name = self::OPTION_NAME . '[' . esc_attr($field) . ']';

        if ($field === 'post_status') {
            echo '<select name="' . esc_attr($name) . '">';
            foreach (['publish' => 'Published only', 'publish_trash' => 'Published + trash as deleted'] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '"' . selected($options[$field], $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">Use Published + trash only if RELPER needs deleted listings marked with <code>&lt;deleted&gt;1&lt;/deleted&gt;</code>.</p>';
            return;
        }

        if ($field === 'default_purpose_id') {
            echo '<select name="' . esc_attr($name) . '">';
            foreach (['2' => 'Prodaja', '1' => 'Izdavanje'] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '"' . selected($options[$field], $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">Used when a property status does not explicitly say Prodaja or Izdavanje.</p>';
            return;
        }

        $type = $field === 'access_token' ? 'password' : 'text';
        echo '<input type="' . esc_attr($type) . '" class="regular-text" name="' . esc_attr($name) . '" value="' . esc_attr($options[$field]) . '">';

        if ($field === 'access_token') {
            echo '<p class="description">Leave empty for a public feed. If set, RELPER URL must include <code>?token=...</code>.</p>';
        }
    }

    public function add_plugin_links(array $links): array
    {
        $settings_url = admin_url('options-general.php?page=relper-export');
        array_unshift($links, '<a href="' . esc_url($settings_url) . '">Settings</a>');
        return $links;
    }

    private function build_xml(): string
    {
        $options = $this->get_options();
        $properties = $this->get_properties($options['post_status']);

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('listings');
        $xml->writeAttribute('generated_at', gmdate('c'));
        $xml->writeAttribute('source', home_url('/'));
        $xml->writeAttribute('plugin_version', self::VERSION);

        $xml->startElement('agency');
        $this->write_text_element($xml, 'agency_name', $options['agency_name']);
        $xml->endElement();

        foreach ($properties as $property) {
            $this->write_listing($xml, $property);
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function write_listing(XMLWriter $xml, WP_Post $property): void
    {
        $data = $this->build_listing_data($property);
        $data = apply_filters('relper_export_listing_data', $data, $property);

        $xml->startElement('listing');

        foreach ($data as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if ($key === 'property_description') {
                $this->write_cdata_element($xml, $key, (string) $value);
                continue;
            }

            if (is_array($value) && in_array($key, ['heating', 'furniture', 'equipment', 'other', 'images'], true)) {
                $child_map = [
                    'heating' => 'heating_type',
                    'furniture' => 'furniture_element',
                    'equipment' => 'equipment_element',
                    'other' => 'other_element',
                    'images' => 'image',
                ];

                $xml->startElement($key);
                foreach ($value as $child_value) {
                    if ($child_value === null || $child_value === '') {
                        continue;
                    }

                    if ($key === 'images') {
                        $this->write_cdata_element($xml, $child_map[$key], (string) $child_value);
                    } else {
                        $this->write_text_element($xml, $child_map[$key], $child_value);
                    }
                }
                $xml->endElement();
                continue;
            }

            if (is_array($value)) {
                $xml->startElement($key);
                foreach ($value as $child_key => $child_value) {
                    if ($child_value !== null && $child_value !== '') {
                        $this->write_text_element($xml, is_string($child_key) ? $child_key : 'item', $child_value);
                    }
                }
                $xml->endElement();
                continue;
            }

            $this->write_text_element($xml, $key, $value);
        }

        $xml->endElement();
    }

    private function build_listing_data(WP_Post $property): array
    {
        $terms = $this->property_terms($property->ID);
        $location = $this->resolve_location($terms['locations']);
        $features = $this->feature_map($terms['features']);
        $options = $this->get_options();
        $price = $this->first_meta($property->ID, [
            'REAL_HOMES_property_price',
            'REAL_HOMES_property_price_prefix',
            'inspiry_property_price',
            'property_price',
        ]);

        $data = [
            'property_id' => $this->first_meta($property->ID, ['REAL_HOMES_property_id', 'inspiry_property_id', 'property_id']) ?: $property->ID,
            'purpose_id' => $this->purpose_id($terms['statuses'], $options['default_purpose_id']),
            'property_type' => $this->first_term_name($terms['types']),
            'structure' => $this->first_meta($property->ID, [
                'REAL_HOMES_property_bedrooms',
                'inspiry_property_bedrooms',
                'property_bedrooms',
            ]),
            'property_name' => get_the_title($property),
            'property_street' => $this->first_meta($property->ID, [
                'REAL_HOMES_property_address',
                'inspiry_property_address',
                'property_address',
            ]),
            'property_street_number' => $this->first_meta($property->ID, [
                'REAL_HOMES_property_street_number',
                'inspiry_property_street_number',
                'property_street_number',
            ]),
            'property_flat_number' => $this->first_meta($property->ID, [
                'REAL_HOMES_property_flat_number',
                'inspiry_property_flat_number',
                'property_flat_number',
            ]),
            'property_construction_year' => $this->first_meta($property->ID, [
                'REAL_HOMES_property_year_built',
                'inspiry_property_year_built',
                'property_year_built',
            ]),
            'property_floor' => $this->first_meta($property->ID, [
                'REAL_HOMES_property_floor',
                'inspiry_property_floor',
                'property_floor',
            ]),
            'property_floors' => $this->first_meta($property->ID, [
                'REAL_HOMES_property_floors',
                'inspiry_property_floors',
                'property_floors',
            ]),
            'property_city' => $location['city_name'],
            'property_hood' => $location['hood_name'],
            'property_hood_part' => $location['hood_part_name'],
            'property_description' => wp_strip_all_tags(apply_filters('the_content', $property->post_content), true),
            'property_surface' => $this->first_meta($property->ID, [
                'REAL_HOMES_property_size',
                'inspiry_property_size',
                'property_size',
            ]),
            'property_land_surface' => $this->first_meta($property->ID, [
                'REAL_HOMES_property_lot_size',
                'inspiry_property_lot_size',
                'property_lot_size',
            ]),
            'property_price' => $this->normalize_number($price),
            'furnished' => $features['furnished'],
            'deleted' => $property->post_status === 'trash' ? '1' : '0',
            'heating' => $features['heating'],
            'furniture' => $features['furniture'],
            'equipment' => $features['equipment'],
            'other' => $features['other'],
            'video' => $this->first_meta($property->ID, [
                'REAL_HOMES_tour_video_url',
                'REAL_HOMES_property_video_url',
                'inspiry_video_url',
                'property_video',
            ]),
            'presentation_3d' => $this->first_meta($property->ID, [
                'REAL_HOMES_360_virtual_tour',
                'REAL_HOMES_virtual_tour',
                'inspiry_property_360_virtual_tour',
                'property_3d_presentation',
            ]),
            'images' => $this->gallery_images($property->ID),
            'id_agent' => $this->agent_id($property),
        ];

        return array_filter($data, static function ($value): bool {
            return $value !== null && $value !== '' && $value !== [];
        });
    }

    private function get_properties(string $post_status): array
    {
        $query_status = $post_status === 'publish_trash' ? ['publish', 'trash'] : $post_status;

        $query_args = [
            'post_type' => apply_filters('relper_export_post_type', 'property'),
            'post_status' => $query_status,
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        ];

        $query_args = apply_filters('relper_export_query_args', $query_args);
        $query = new WP_Query($query_args);

        return $query->posts;
    }

    private function property_terms(int $post_id): array
    {
        return [
            'types' => $this->terms($post_id, ['property-type', 'property_type']),
            'statuses' => $this->terms($post_id, ['property-status', 'property_status']),
            'locations' => $this->terms($post_id, ['property-city', 'property_city', 'property-location', 'property_location']),
            'features' => $this->terms($post_id, ['property-feature', 'property_feature']),
        ];
    }

    private function terms(int $post_id, array $taxonomies): array
    {
        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_the_terms($post_id, $taxonomy);
            if (!is_wp_error($terms) && is_array($terms) && $terms !== []) {
                usort($terms, static function (WP_Term $a, WP_Term $b): int {
                    $parent_compare = $a->parent <=> $b->parent;
                    return $parent_compare !== 0 ? $parent_compare : $a->term_id <=> $b->term_id;
                });
                return $terms;
            }
        }

        return [];
    }

    private function resolve_location(array $terms): array
    {
        $names = $this->ordered_location_names($terms);
        $city = $names[0] ?? '';
        $hood = $names[1] ?? '';
        $hood_part = $names[2] ?? '';
        $csv_row = $this->find_location_row($city, $hood, $hood_part);

        if (count($names) === 1 && $csv_row === []) {
            $csv_row = $this->find_location_row_by_single_name($names[0]);
            if ($csv_row !== []) {
                $matched_column = $csv_row['_matched_column'] ?? '';
                $city = $csv_row['city_name'] ?? $city;
                $hood = $matched_column === 'city_name' ? '' : ($csv_row['hood_name'] ?? $hood);
                $hood_part = $matched_column === 'hood_part_name' ? ($csv_row['hood_part_name'] ?? $hood_part) : '';
                unset($csv_row['_matched_column']);
            }
        }

        return [
            'city_name' => $city,
            'hood_name' => $hood,
            'hood_part_name' => $hood_part,
            'id_city' => $csv_row['id_city'] ?? '',
            'id_hood' => $csv_row['id_hood'] ?? '',
            'id_hood_part' => $csv_row['id_hood_part'] ?? '',
        ];
    }

    private function ordered_location_names(array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        $by_id = [];
        foreach ($terms as $term) {
            $by_id[$term->term_id] = $term;
        }

        $deepest = $terms[0];
        foreach ($terms as $term) {
            if ($this->term_depth($term, $by_id) > $this->term_depth($deepest, $by_id)) {
                $deepest = $term;
            }
        }

        $chain = [];
        $current = $deepest;
        while ($current instanceof WP_Term) {
            array_unshift($chain, $current->name);
            if (!$current->parent) {
                break;
            }

            if (isset($by_id[$current->parent])) {
                $current = $by_id[$current->parent];
                continue;
            }

            $parent = get_term($current->parent, $current->taxonomy);
            if (!$parent instanceof WP_Term || is_wp_error($parent)) {
                break;
            }

            $current = $parent;
        }

        return array_values(array_unique($chain));
    }

    private function term_depth(WP_Term $term, array $by_id): int
    {
        $depth = 0;
        $current = $term;
        while ($current->parent) {
            $depth++;

            if (isset($by_id[$current->parent])) {
                $current = $by_id[$current->parent];
                continue;
            }

            $parent = get_term($current->parent, $current->taxonomy);
            if (!$parent instanceof WP_Term || is_wp_error($parent)) {
                break;
            }

            $current = $parent;
        }

        return $depth;
    }

    private function find_location_row(string $city, string $hood, string $hood_part): array
    {
        foreach ($this->location_rows() as $row) {
            if (!$this->same_text($row['city_name'] ?? '', $city)) {
                continue;
            }

            $hood_matches = $hood === '' || $this->same_text($row['hood_name'] ?? '', $hood);
            $part_matches = $hood_part === '' || $this->same_text($row['hood_part_name'] ?? '', $hood_part);

            if ($hood_matches && $part_matches) {
                return $row;
            }
        }

        return [];
    }

    private function find_location_row_by_single_name(string $name): array
    {
        foreach ($this->location_rows() as $row) {
            foreach (['city_name', 'hood_name', 'hood_part_name'] as $column) {
                if (isset($row[$column]) && $this->same_text($row[$column], $name)) {
                    $row['_matched_column'] = $column;
                    return $row;
                }
            }
        }

        return [];
    }

    private function location_rows(): array
    {
        if ($this->location_rows !== []) {
            return $this->location_rows;
        }

        $file = apply_filters('relper_export_locations_csv', plugin_dir_path(__FILE__) . 'locations.csv');
        if (!is_readable($file)) {
            return [];
        }

        $handle = fopen($file, 'rb');
        if (!$handle) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (!is_array($headers)) {
            fclose($handle);
            return [];
        }

        $headers = array_map(static function ($header): string {
            return trim((string) $header, "\xEF\xBB\xBF \t\n\r\0\x0B");
        }, $headers);

        while (($row = fgetcsv($handle)) !== false) {
            $assoc = [];
            foreach ($headers as $index => $header) {
                $assoc[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
            }
            $this->location_rows[] = $assoc;
        }

        fclose($handle);
        return $this->location_rows;
    }

    private function feature_map(array $terms): array
    {
        $allowed = [
            'furnished' => ['Polunamešten', 'Prazan', 'Namešten'],
            'heating' => ['EG', 'Gas', 'Podno', 'Mermerni radijatori', 'Toplotne pumpe', 'TA', 'Centralno', 'Čvrsta goriva', 'Kaljeva peć', 'Norveški radijatori', 'Električno', 'Kombinovano'],
            'furniture' => ['TV', 'Plakari/Ormani', 'Frižider', 'Sudopera', 'Šporet', 'Mašina za sudove', 'Kuhinjski elementi', 'Kreveti', 'Veš mašina'],
            'equipment' => ['Interfon', 'Video nadzor', 'Klima', 'Internet', 'Video interfon', 'Telefon', 'Kablovska'],
            'other' => ['Ostava', 'Garažno mesto', 'Terasa', 'Lođa', 'Podrum', 'Parking', 'Lift', 'Bazen', 'Topla voda', 'Gradska kanalizacija', 'Renoviran', 'Penthouse', 'U fazi knjiženja', 'Izvorno stanje', 'Može zamena', 'Lux', 'Može na kredit', 'Nije prizemlje', 'Uknjižen', 'U izgradnji', 'Novogradnja', 'Stara gradnja', 'Odmah useljiv', 'Duplex', 'Može kompenzacija', 'Nije poslednji sprat', 'Za adaptaciju', 'Vila', 'Neuknjiživ', 'Salonski'],
        ];

        $furnished = '';
        $heating = [];
        $furniture = [];
        $equipment = [];
        $other = [];

        foreach ($terms as $term) {
            $name = $term->name;

            $matched_furnished = $this->match_allowed_value($name, $allowed['furnished']);
            if ($furnished === '' && $matched_furnished !== '') {
                $furnished = $matched_furnished;
                continue;
            }

            $matched_heating = $this->match_allowed_value($name, $allowed['heating']);
            if ($matched_heating !== '') {
                $heating[] = $matched_heating;
                continue;
            }

            $matched_furniture = $this->match_allowed_value($name, $allowed['furniture']);
            if ($matched_furniture !== '') {
                $furniture[] = $matched_furniture;
                continue;
            }

            $matched_equipment = $this->match_allowed_value($name, $allowed['equipment']);
            if ($matched_equipment !== '') {
                $equipment[] = $matched_equipment;
                continue;
            }

            $matched_other = $this->match_allowed_value($name, $allowed['other']);
            if ($matched_other !== '') {
                $other[] = $matched_other;
            }
        }

        return [
            'furnished' => $furnished,
            'heating' => array_values(array_unique($heating)),
            'furniture' => array_values(array_unique($furniture)),
            'equipment' => array_values(array_unique($equipment)),
            'other' => array_values(array_unique($other)),
        ];
    }

    private function purpose_id(array $status_terms, string $default_purpose_id): string
    {
        $status = $this->normalize_text(implode(' ', wp_list_pluck($status_terms, 'name')));

        if (preg_match('/izdavanje|rent|najam/u', $status)) {
            return apply_filters('relper_export_purpose_id', '1', 'rent', $status_terms);
        }

        if (preg_match('/prodaja|sale|sell/u', $status)) {
            return apply_filters('relper_export_purpose_id', '2', 'sale', $status_terms);
        }

        return apply_filters('relper_export_purpose_id', $default_purpose_id, 'default', $status_terms);
    }

    private function first_term_name(array $terms): string
    {
        return $terms[0]->name ?? '';
    }

    private function match_allowed_value(string $value, array $allowed_values): string
    {
        $normalized_value = $this->normalize_text($value);
        $synonyms = [
            'dupleks' => 'Duplex',
            'centralno grejanje' => 'Centralno',
            'centralno grijanje' => 'Centralno',
            'el grejanje' => 'Električno',
            'elektricno grejanje' => 'Električno',
        ];

        if (isset($synonyms[$normalized_value])) {
            return $synonyms[$normalized_value];
        }

        foreach ($allowed_values as $allowed_value) {
            $normalized_allowed = $this->normalize_text($allowed_value);
            if ($normalized_value === $normalized_allowed || strpos($normalized_value, $normalized_allowed) !== false) {
                return $allowed_value;
            }
        }

        return '';
    }

    private function agent_id(WP_Post $property): string
    {
        $meta = $this->first_meta($property->ID, [
            'REAL_HOMES_agents',
            'REAL_HOMES_agent',
            'inspiry_property_agent',
            'property_agent',
        ]);

        if ($meta !== '') {
            return $meta;
        }

        $user = get_userdata((int) $property->post_author);
        if (!$user) {
            return '';
        }

        return $user->user_email ?: (string) $user->ID;
    }

    private function first_meta(int $post_id, array $keys): string
    {
        foreach ($keys as $key) {
            $value = get_post_meta($post_id, $key, true);

            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            }

            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalize_number(string $value): string
    {
        $value = preg_replace('/[^\d,.]/', '', $value);
        if ($value === null || $value === '') {
            return '';
        }

        if (substr_count($value, ',') === 1 && substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (substr_count($value, ',') === 1 && substr_count($value, '.') === 0) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        return $value;
    }

    private function featured_image(int $post_id): string
    {
        $url = get_the_post_thumbnail_url($post_id, 'full');
        return $url ? esc_url_raw($url) : '';
    }

    private function gallery_images(int $post_id): array
    {
        $images = [];
        $featured = $this->featured_image($post_id);

        if ($featured !== '') {
            $images[] = $featured;
        }

        $raw_values = [
            get_post_meta($post_id, 'REAL_HOMES_property_images', false),
            get_post_meta($post_id, 'inspiry_property_images', false),
            get_post_meta($post_id, 'property_images', false),
        ];

        foreach ($raw_values as $raw_group) {
            foreach ((array) $raw_group as $raw) {
                foreach ((array) $raw as $item) {
                    $url = $this->image_url($item);
                    if ($url !== '') {
                        $images[] = $url;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($images)));
    }

    private function image_url($value): string
    {
        if (is_numeric($value)) {
            $url = wp_get_attachment_url((int) $value);
            return $url ? esc_url_raw($url) : '';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return esc_url_raw($value);
        }

        return '';
    }

    private function write_text_element(XMLWriter $xml, string $name, $value): void
    {
        $xml->startElement($name);
        $xml->text((string) $value);
        $xml->endElement();
    }

    private function write_cdata_element(XMLWriter $xml, string $name, string $value): void
    {
        $xml->startElement($name);
        $xml->writeCdata($value);
        $xml->endElement();
    }

    private function get_options(): array
    {
        return wp_parse_args(get_option(self::OPTION_NAME, []), $this->default_options());
    }

    private function default_options(): array
    {
        return [
            'agency_name' => get_bloginfo('name') ?: 'Kreativa Nekretnine',
            'access_token' => '',
            'post_status' => 'publish',
            'default_purpose_id' => '2',
        ];
    }

    private function same_text(string $a, string $b): bool
    {
        return $this->normalize_text($a) === $this->normalize_text($b);
    }

    private function normalize_text(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
        $value = remove_accents($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return $value ?? '';
    }
}

register_activation_hook(__FILE__, ['Relper_Export_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Relper_Export_Plugin', 'deactivate']);
Relper_Export_Plugin::instance();
