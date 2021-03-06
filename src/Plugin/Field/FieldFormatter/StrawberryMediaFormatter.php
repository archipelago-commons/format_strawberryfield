<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\webform_strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;

/**
 * Simplistic Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_media_formatter",
 *   label = @Translation("Strawberry Field Media Formatter for IIIF media"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMediaFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryMediaFormatter extends FormatterBase {
  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'iiif_base_url' => 'http://localhost:8183/iiif/2/',
      'iiif_base_url_internal' => 'http://esmero-cantaloupe:8182/iiif/2/',
      'iiif_group' => TRUE,
      'json_key_source' => 'as:image',
      'max_width' => 720,
      'max_height' => 480,

    ];
  }
  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    //@TODO validate IIIF server responses, first one via AJAX, second via CURL.
    //@TODO document that 2 base urls are just needed when developing (localhost syndrom)
    return [
      'iiif_base_url' => [
        '#type' => 'url',
        '#title' => $this->t('Base URL of your IIIF Media Server public accesible from the Outside World'),
        '#default_value' => $this->getSetting('iiif_base_url'),
        '#required' => TRUE,
      ],
      'iiif_base_url_internal' => [
        '#type' => 'url',
        '#title' => $this->t('Base URL of your IIIF Media Server accesible from inside this Webserver'),
        '#default_value' => $this->getSetting('iiif_base_url_internal'),
        '#required' => TRUE,
      ],
      'iiif_group' => [
        '#type' => 'checkbox',
        '#title' => t('Group all Media files in a single viewer?'),
        '#default_value' => $this->getSetting('iiif_group'),
      ],
      'json_key_source' => [
        '#type' => 'textfield',
        '#title' => t('JSON Key from where to fetch Media URLs'),
        '#default_value' => $this->getSetting('json_key_source'),
      ],
      'max_width' => [
        '#type' => 'number',
        '#title' => $this->t('Maximum width'),
        '#default_value' => $this->getSetting('max_width'),
        '#size' => 5,
        '#maxlength' => 5,
        '#field_suffix' => $this->t('pixels'),
        '#min' => 0,
      ],
      'max_height' => [
        '#type' => 'number',
        '#title' => $this->t('Maximum height'),
        '#default_value' => $this->getSetting('max_height'),
        '#size' => 5,
        '#maxlength' => 5,
        '#field_suffix' => $this->t('pixels'),
        '#min' => 0,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Displays Zoomable Media from JSON using a IIIF server and the OpenSeadragon viewer.');
    if ($this->getSetting('iiif_base_url')) {
      $summary[] = $this->t('External IIIF Media Server base URI: %iiif_base_url', [
        '%iiif_base_url' => $this->getSetting('iiif_base_url'),
      ]);
    }
    if ($this->getSetting('iiif_base_url_internal')) {
      $summary[] = $this->t('Internal IIIF Media Server base URI: %iiif_base_url', [
        '%iiif_base_url' => $this->getSetting('iiif_base_url_internal'),
      ]);
    }
    if ($this->getSetting('iiif_group')) {
      $summary[] = $this->t('Use a single Viewer for multiple media: %iiif_group', [
        '%iiif_group' => $this->getSetting('iiif_group'),
      ]);
    }
    if ($this->getSetting('json_key_source')) {
      $summary[] = $this->t('Media fetched from JSON "%json_key_source" key', [
        '%json_key_source' => $this->getSetting('json_key_source'),
      ]);
    }
    if ($this->getSetting('max_width') && $this->getSetting('max_height')) {
      $summary[] = $this->t('Maximum size: %max_width x %max_height pixels', [
        '%max_width' => $this->getSetting('max_width'),
        '%max_height' => $this->getSetting('max_height'),
      ]);
    }
    elseif ($this->getSetting('max_width')) {
      $summary[] = $this->t('Maximum width: %max_width pixels', [
        '%max_width' => $this->getSetting('max_width'),
      ]);
    }
    elseif ($this->getSetting('max_height')) {
      $summary[] = $this->t('Maximum height: %max_height pixels', [
        '%max_height' => $this->getSetting('max_height'),
      ]);
    }

    return $summary;
  }


  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $max_width = $this->getSetting('max_width');
    $max_height = $this->getSetting('max_height');
    $grouped = $this->getSetting('iiif_group');

    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'
    $key = $this->getSetting('json_key_source');
    $baseiiifserveruri = $this->getSetting('iiif_base_url');
    $baseiiifserveruri_internal =  $this->getSetting('iiif_base_url_internal');

    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
    $fieldname = $items->getName();

    foreach ($items as $delta => $item) {
      $main_property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
      $value = $item->{$main_property};
      if (empty($value)) {
        continue;
      }

      $jsondata = json_decode($item->value, true);
      // @TODO use future flatversion precomputed at field level as a property
      $json_error = json_last_error();
      if ($json_error != JSON_ERROR_NONE) {
        return $elements[$delta] = ['#markup' => $this->t('ERROR')];
      }
      /* Expected structure of an Media item inside JSON
      "as:images": {
         "s3:\/\/f23\/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.jpg": {
         "fid": 32, // Drupal's FID
         "for": "add_some_master_images", // The webform element key that generated this one
         "url": "s3:\/\/f23\/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.jpg",
         "name": "new-metadata-en-image-a8d0090cbd2cd3ca2ab16e3699577538f3049941.jpg",
         "type": "Image",
         "checksum": "f231aed5ae8c2e02ef0c5df6fe38a99b"
         }
      }*/
      $i = 0;

      // We need to load main Library on each page for views to see it.
      $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/iiif_openseadragon_strawberry';

      if (isset($jsondata[$key])) {
        foreach ($jsondata[$key] as $mediaitem) {
          $i++;
          if (isset($mediaitem['type']) && $mediaitem['type'] == 'Image') {
            if (isset($mediaitem['dr:fid'])) {
              // @TODO check if loading the entity is really needed to check access.
              $file = OcflHelper::resolvetoFIDtoURI(
                $mediaitem['dr:fid']
              );

              //@TODO if no media key to file loading was possible
              // means we have a broken/missing media reference
              // we should inform to logs and continue
              if (!$file) {
                continue;
              }
              if ($this->checkAccess($file)) {
                $iiifidentifier = urlencode(
                  file_uri_target($file->getFileUri())
                );
                if ($iiifidentifier == NULL || empty($iiifidentifier)) {
                  continue;
                }
                // ImageToolKit use the $file->getFileUri(), we don't want that yet
                // @see https://github.com/esmero/format_strawberry/issues/1


                //@ TODO recheck cache tags here, since we are not really using the file itself.
                $filecachetags = $file->getCacheTags();

                // @TODO move the IIIF server baser URL to a global config and add local fieldformatter override.
                $iiifserver = "{$baseiiifserveruri}{$iiifidentifier}/info.json";


                $groupid = 'iiif-'.$items->getName(
                  ).'-'.$nodeuuid.'-'.$delta.'-media';
                $uniqueid =  $groupid.$i;

                $elements[$delta]['media'.$i] = [
                  '#type' => 'container',
                  '#default_value' => $uniqueid,
                  '#attributes' => [
                    'id' => $uniqueid,
                    'class' => ['strawberry-media-item','field-iiif','container'],
                    'data-iiif-infojson' => $iiifserver,
                    'data-iiif-group' => $grouped ? $groupid : $uniqueid,
                    'width' => $max_width,
                    'height' => $max_height,
                  ],
                ];
                if (isset($item->_attributes)) {
                  $elements[$delta] += ['#attributes' => []];
                  $elements[$delta]['#attributes'] += $item->_attributes;
                  // Unset field item attributes since they have been included in the
                  // formatter output and should not be rendered in the field template.
                  unset($item->_attributes);
                }
                // @TODO deal with a lot of Media single strawberryfield
                // Idea would be to allow a setting that says, A) all same viewer(aggregate)
                // B) individual viewers for each?
                // C) only first one?
                // We will assign a group based on the UUID of the node containing this
                // to idenfity all the divs that we will create. And only first one will be the container in case of many?
                // so a jquery selector that uses that group as filter for a search.
                // Drupal JS settings get accumulated. So in a single search results site we will have for each
                // Formatter one passed. Reason we use 'innode' array key using our $uniqueid
                // @TODO probably better to use uuid() or the node id() instead of $uniqueid
                $elements[$delta]['media'.$i]['#attributes']['data-iiif-infojson'] = $iiifserver;
                $elements[$delta]['media'.$i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon']['innode'][$uniqueid] = $nodeuuid;
              }
            } elseif (isset($mediaitem['url'])) {
              $elements[$delta]['media'.$i] = [
                '#markup' => 'Non managed '.$mediaitem['url'],
                '#prefix' => '<pre>',
                '#suffix' => '</pre>',
              ];
            }

          }
        }
      }

    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function view(FieldItemListInterface $items, $langcode = NULL) {

    $elements = parent::view($items, $langcode);
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity) {
    // Only check access if the current file access control handler explicitly
    // opts in by implementing FileAccessFormatterControlHandlerInterface.
    $access_handler_class = $entity->getEntityType()->getHandlerClass('access');
    if (is_subclass_of($access_handler_class, '\Drupal\file\FileAccessFormatterControlHandlerInterface')) {
      return $entity->access('view', NULL, FALSE);
    }
    else {
      return AccessResult::allowed();
    }
  }
}