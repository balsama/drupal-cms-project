<?php

namespace Drupal\drupal_cms_installer\Form;

use Composer\InstalledVersions;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\State\StateInterface;
use Drupal\drupal_cms_installer\RecipeHandler;
use GuzzleHttp\ClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Defines a form to choose a site template.
 *
 * @internal
 *   Everything in the Drupal CMS installer is internal and may be changed or
 *   removed at any time without warning. External code should not interact
 *   with this class.
 */
final class SiteTemplateForm extends FormBase {

  use AutowireTrait;

  /**
   * An identifier for this task, to mark it as completed.
   */
  public const string TASK_ID = 'template';

  public function __construct(
    private readonly ClientInterface $http,
    private readonly RecipeHandler $recipeHandler,
    #[Autowire(service: 'cache.default')] private readonly CacheBackendInterface $cache,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'installer_site_template_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?array $install_state = NULL): array {
    $map = function (Recipe $recipe): array {
      return [
        'name' => $recipe->name,
        'path' => $recipe->path,
        'description' => $recipe->description,
        'screenshot' => $recipe->path . DIRECTORY_SEPARATOR . 'screenshot.webp',
      ];
    };
    // @see drupal_cms_installer_choose_template()
    $all_choices = array_map($map, $install_state['recipes'] ?? []);

    // Load additional choices. If any of them are already in the code base, the
    // ones that are physically present will "win".
    $all_choices += $this->getCuratedList();

    foreach ($all_choices as $key => $choice) {
      // Each site template must either be present in the code base (in which
      // case the `path` key should be set) or we should know what its Composer
      // package name is (in which case the `package` key should be set).
      $locator = $choice['path'] ?? $choice['package'];

      $form['add_ons'][$key] = [
        '#theme_wrappers' => [
          'form_element__site_template' => [
            'screenshot' => $choice['screenshot'] ?? NULL,
          ],
        ],
        '#description' => $choice['description'],
        '#locator' => $locator,
        '#access' => isset($locator),
      ];
      $form['add_ons']['#options'][$key] = $choice['name'];
    }
    // Must be called `add_ons` to agree with the theme.
    $form['add_ons'] += [
      '#options' => [],
      '#type' => 'radios',
      '#required' => TRUE,
      '#default_value' => array_key_first($all_choices),
    ];
    $form['actions'] = [
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Next'),
        '#button_type' => 'primary',
        '#op' => 'submit',
      ],
      '#type' => 'actions',
    ];
    $form['#title'] = $this->t('Choose a site template');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $choice = $form_state->getValue('add_ons');
    $locator = $form['add_ons'][$choice]['#locator'];

    // If the site is exported from the UI, base it on the starter kit.
    // @see \Drupal\drupal_cms_helper\Controller\ExportController::exportArchive()
    if ($choice === 'drupal_cms_site_template_base') {
      $this->state->set('site_template_base', $locator);
    }
    $this->recipeHandler->enqueue($locator);
    // Mark the task as finished.
    $GLOBALS['install_state']['parameters'][self::TASK_ID] = INSTALL_TASK_SKIP;
  }

  /**
   * Returns a curated list of site template information.
   *
   * @return array<string, array>
   *   An array of information about site templates, keyed by machine name.
   */
  private function getCuratedList(): array {
    $messenger = $this->messenger();

    // First and foremost, ensure the file system is writable. If it's not, then
    // there's no point in showing the list of site templates because you
    // probably won't be able to install any of them anyway.
    ['install_path' => $project_root] = InstalledVersions::getRootPackage();
    if (!is_writable($project_root)) {
      $messenger->addWarning(
        $this->t('Only showing site templates that are already downloaded, because %dir is not writable.', [
          '%dir' => realpath($project_root),
        ]),
      );
      return [];
    }

    // If the original file exists, read it directly. It should not be included
    // in releases of the installer.
    // @see .gitattributes
    $file = dirname(__DIR__, 2) . '/site-templates.yml';
    if (file_exists($file)) {
      return Yaml::decode(file_get_contents($file));
    }

    // @see site-templates.yml
    $url = 'https://git.drupalcode.org/api/v4/projects/204857/repository/files/site-templates.yml/raw?ref=HEAD';
    $cid = hash('xxh32', $url);

    $cached = $this->cache->get($cid);
    if ($cached) {
      return $cached->data;
    }

    $list = [];
    try {
      $list = Yaml::decode(
        (string) $this->http->request('GET', $url)->getBody(),
      );
    }
    catch (ParseException | ClientExceptionInterface $e) {
      $messenger->addWarning($e->getMessage());
    }
    $this->cache->set($cid, $list);
    return $list;
  }

}
