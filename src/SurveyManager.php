<?php 
namespace MakeAndShip\Mend;

include_once( WP_PLUGIN_DIR . '/wpml-multilingual-cms/inc/wpml-api.php' );

/**
 * Base class that provides a template for loading surveys into a wordpress CMS
 *
 * Sub-classes should define a name via get_name
 * Sub-classes should define a survey array per language and question
 */
abstract class SurveyManager {

  function __construct() {
    $this->fields_by_parent = $this->get_acf_field_mappings_by_parent();
    $this->language_by_group = $this->get_acf_field_group_language_mappings(); 

    $this->language_mappings = $this->get_language_by_field(
      $this->fields_by_parent, 
      $this->language_by_group);
  }

  /**
   * Return the survey name
   * @return name
   */ 
  abstract protected function get_name();


  /**
   * Return the survey definition
   * @return survey definition
   */
  abstract protected function get_surveys();

  /**
   * Activate the plugin
   * - load the survey definition
   * - load each language translation
   */
  public function activate() {
    error_log('Activate ' . $this->get_name());

    $surveys = $this->get_surveys();
    if ($surveys) {
      $default_translation_id = null;
      foreach ($surveys as $survey) {
        if (
          array_key_exists('language', $survey) && 
          array_key_exists('sections', $survey)
        ) {
          global $sitepress;

          $language_code = $survey['language'];
          $sections = $survey['sections'];
          $title = $survey['title'];
          $name = $survey['name'];
          $default = array_key_exists('default', $survey) ? $survey['default'] : false;
      
          // create the survey post type
          $args = array(
            'post_title' => $title,
            'post_name' => $name,
            'post_type' => 'survey',
            'post_status' => 'publish');

          $post_id = wp_insert_post( $args );
          if ($default) {
            $default_translation_id = wpml_get_content_trid('post_survey', $post_id);
          }
          else {
            $sitepress->set_element_language_details(
              $post_id, 
              'post_survey', 
              $default_translation_id, 
              $language_code
            );
          }

          // get language specific mappings
          $mappings = $this->get_acf_field_mappings($language_code);

          // create the associated questions
          if (array_key_exists('sections', $mappings)) {
            $sections_key = $mappings['sections'];

            foreach($sections as $section) {
              $mapped = $this->get_mapped_section($section, $mappings);

              add_row(
                  $sections_key,
                  $section,
                  $post_id);
            }
          }
        }
      }
    }
  }

  /**
   * Deactivate the plugin
   * - load the survey definition
   * - remove each language definiton
   */
  public function deactivate() {
    error_log('Deactivate ' . $this->get_name());
    
    $surveys = $this->get_surveys();

    foreach($surveys as $survey) {
      if (array_key_exists('name', $survey)) {
        $name = $survey['name'];
        // remove the survey post type
        $args = array(
          'name' => $name,
          'post_type' => 'survey',
          );

        $posts = get_posts( $args );
        foreach ($posts as $post ) {
          $post_id = $post->ID;
          wp_delete_post($post_id);

          // delete post fields
          global $wpdb;
          $wpdb->delete(
            $wpdb->postmeta,
            array(
              'post_id' => $post_id
            )
          );
        }
      }
    }
  }

  private function get_mapped_section( $section, $mappings ) {
    $mapped = $section;
    
    if ($mapped) {
      
      foreach( $mapped as $key => $item) {
        if (array_key_exists($key, $mappings)) {
          $mapped_key = $mappings[$key];

          if (is_array($item)) {
            if ($this->is_array_sequential($item)) {
              $arr = [];

              foreach($item as $sub_item) {
                $mapped_sub_item = $this->get_mapped_section($sub_item, $mappings);
                $arr[] = $mapped_sub_item;
              }

              $mapped[$mapped_key] = $arr;
            }
            else {
              $mapped_item = $this->get_mapped_section($item);
              $mapped[$mapped_key] = $mapped_item;
            }
          }
          else {
            $mapped[$mapped_key] = $item;
          }

          unset($mapped[$key]);
        }
        else {
          // exclude unmapped items
          //unset($mapped[$key]);
        }
      }
    }
    return $mapped;
  }

  private function get_acf_field_mappings($language) {
        $mappings = array();

    if ($language) {
      // fields
      $args = array(
        'post_type' => 'acf-field',
        'numberposts' => -1
      );

      $posts = get_posts($args);

      if ($posts) {
        foreach($posts as $post) {
          $post_id = $post->ID;
          $field = _acf_get_field_by_id($post->ID, true);
          if ( $field['type'] !== 'tab') {
            if (array_key_exists($post_id, $this->language_mappings)) {
              $group_language = $this->language_mappings[$post_id];
              if ($group_language == $language) {
                $key = $post->post_name;
                $name = $post->post_excerpt;

                $mappings[$name] = $key;
              }
            }
          }
        }
      }
    }

        return $mappings;
    }

  private function get_language_by_field( $fields, $groups ) {
    $mappings = array();

    if ($fields && $groups) {
      foreach($fields as $field_id => $field) {
        // get the field 
        $parent_id = $field['parent'];
        $exists = array_key_exists($parent_id, $fields);
        while ($exists) {
          $parent_id = $fields[$parent_id]['parent'];
          $exists = array_key_exists($parent_id, $fields);
        }

        // get the get the parent 
        if (array_key_exists($parent_id, $groups)) {
          $group = $groups[$parent_id];
          if ($group && array_key_exists('language', $group)) {
            $language = $group['language'];
            $mappings[$field_id] = $language;
          }
        }
      }
    }

    return $mappings;
  }

  private function get_acf_field_mappings_by_parent() {
    $mappings = array();

    // fields
    $args = array(
      'post_type' => 'acf-field',
      'numberposts' => -1
    );

    $posts = get_posts($args);

    if ($posts) {
      foreach($posts as $post) {
        $post_id = $post->ID;
        $field = _acf_get_field_by_id($post_id, true);
        if ( $field['type'] !== 'tab') {
          
          $mappings[$post_id] = array(
            'parent' => $post->post_parent,
            'key' => $post->post_name,
            'name' => $post->post_excerpt
          );
          
        }
      }
    }
    
    return $mappings;
  }

  private function get_acf_field_group_language_mappings() {
    $mappings = array();

    // field groups
    $args = array(
      'post_type' => 'acf-field-group',
      'numberposts' => -1
    );

    $posts = get_posts($args);

    if ($posts) {
      foreach($posts as $post) {
        $post_id = $post->ID;
        
        $language = wpml_get_language_information(null, $post_id);
        if (array_key_exists('locale', $language)) {
          $language_code = $language['language_code'];
          $mappings[$post_id] = array(
            'parent' => 0,
            'language' => $language_code,
            'key' => $post->post_name,
            'name' => $post->post_excerpt
          );
        }
      }
    }

    return $mappings;
  }

  private function is_array_associative( $array ) {
    return count(array_filter(array_keys($array), 'is_string')) > 0;
  }
  
  private function is_array_sequential( $array ) {
    return !self::is_array_associative($array);
  }
}