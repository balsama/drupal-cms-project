<?php

declare(strict_types=1);

namespace Drupal\drupal_cms_helper;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageCopyTrait;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Exports the current site as a recipe.
 *
 * @api
 *   This is part of Drupal CMS's developer-facing API and may be relied upon.
 *   You may also take advantage of the public helper methods `loadAllContent()`
 *   and `getExtensionRequirements()`.
 */
final class SiteExporter implements LoggerAwareInterface {

  use LoggerAwareTrait;
  use StorageCopyTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly ModuleExtensionList $moduleList,
    private readonly ThemeExtensionList $themeList,
    private readonly FileSystemInterface $fileSystem,
    #[Autowire(service: 'config.storage.export')] private readonly StorageInterface $storage,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ConfigManagerInterface $configManager,
    private readonly Exporter $contentExporter,
    #[Autowire(param: 'app.root')] private readonly string $appRoot,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ClassResolverInterface $classResolver,
  ) {}

  /**
   * Exports the current site's configuration and content into a recipe.
   *
   * @param string $destination
   *   The path where the recipe should be created.
   * @param string|null $base
   *   (optional) The path of a recipe to use as the base for the export, or
   *   NULL to not use a base recipe at all.
   */
  public function export(string $destination, ?string $base = NULL): void {
    if ($base && is_dir($base)) {
      $this->copyBaseRecipe($base, $destination);
    }
    else {
      if ($base) {
        $this->logger?->warning('Base recipe %path was not found. Exporting the site anyway, but this may produce unintended results.', [
          '%path' => $base,
        ]);
      }
      $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }

    $listener = $this->classResolver->getInstanceFromDefinition(GenericConfigurationListener::class);
    assert($listener instanceof GenericConfigurationListener);
    $listener->convertFrontPagePathToAlias = TRUE;
    $this->eventDispatcher->addListener(ConfigEvents::STORAGE_TRANSFORM_EXPORT, $listener);

    // Initially, just export all config as files. Then we'll convert certain
    // items to config actions.
    // @todo Use a plain FileStorage object when
    //   https://www.drupal.org/i/3002532 is released.
    $storage = new class ($destination . '/config') extends FileStorage {

      /**
       * {@inheritdoc}
       */
      public function write($name, array $data): bool {
        if (preg_match('/^language\.entity\.(?!und|zxx)/', $name)) {
          $data['dependencies']['config'][] = 'language.entity.und';
          $data['dependencies']['config'][] = 'language.entity.zxx';
        }
        return parent::write($name, $data);
      }

    };
    self::replaceStorageContents($this->storage, $storage);
    // From here on out, we're only going to modify the default collection. Any
    // other collections probably just contain translations, and config actions
    // are not translatable (yet).
    $storage = $storage->createCollection(StorageInterface::DEFAULT_COLLECTION);
    // The core.extension config should never be included in a recipe.
    $storage->delete('core.extension');
    // We're exporting a site template, which is meant to be shared, so we don't
    // need to protect the configuration.
    $this->fileSystem->delete($destination . '/config/.htaccess');

    $actions = [];
    foreach ($storage->listAll() as $name) {
      if ($this->isAction($name)) {
        $actions[$name] = $this->toAction($name, $storage->read($name));
        $storage->delete($name);
      }
    }
    // The site name and mail are almost always collected during the install
    // process and shouldn't be exported.
    unset(
      $actions['system.site']['simpleConfigUpdate']['name'],
      $actions['system.site']['simpleConfigUpdate']['mail'],
    );

    $extensions = $this->getInstalledExtensions();
    $recipes = $this->updateRecipe($destination, array_keys($extensions), $actions);
    $this->updateComposerJson($destination, $extensions, $recipes);

    // Export all content, with its dependencies, as files.
    $loader = $this->classResolver->getInstanceFromDefinition(ContentLoader::class);
    foreach ($loader as $entity) {
      $this->contentExporter->exportWithDependencies($entity, $destination . '/content');
    }
  }

  /**
   * Copies a base recipe into the destination directory.
   *
   * Everything from the base recipe will be copied, except for its content.
   * Any `*.example` files in the base recipe will have the `.example` suffix
   * stripped.
   *
   * @param string $base
   *   The path of the base recipe.
   * @param string $destination
   *   The destination directory.
   */
  private function copyBaseRecipe(string $base, string $destination): void {
    $finder = Finder::create()
      ->in($base)
      ->files()
      ->ignoreVCS(TRUE)
      ->ignoreDotFiles(FALSE)
      ->notPath('content');

    $file_system = new Filesystem();
    $file_system->mirror($base, $destination, $finder, [
      'override' => TRUE,
      'delete' => TRUE,
    ]);

    // Rename any `*.example` files to remove the `.example` suffix, so that
    // the files will actually be used.
    $finder = Finder::create()
      ->in($destination)
      ->files()
      ->ignoreDotFiles(FALSE)
      ->name(['*.example', '.*.example']);

    foreach ($finder as $file) {
      $path = $file->getPathname();
      $file_system->rename($path, substr($path, 0, -8));
    }
  }

  /**
   * Finds all installed modules and themes.
   *
   * @return \Drupal\Core\Extension\Extension[]
   *   All installed extensions, keyed by machine name.
   */
  private function getInstalledExtensions(): array {
    $modules = array_intersect_key(
      $this->moduleList->getList(),
      $this->moduleList->getAllInstalledInfo(),
    );
    $themes = array_intersect_key(
      $this->themeList->getList(),
      $this->themeList->getAllInstalledInfo(),
    );
    return array_filter(
      [...$modules, ...$themes],
      // Install profiles should always be excluded from recipes.
      fn (Extension $e): bool => $e->getType() !== 'profile',
    );
  }

  /**
   * Generates Composer version constraints for a set of extensions.
   *
   * @param \Drupal\Core\Extension\Extension[] $extensions
   *   A set of extensions.
   *
   * @return array<string, string>
   *   An array of Composer version constraints, keyed by package name.
   */
  public function getExtensionRequirements(array $extensions): array {
    $requirements = [];

    foreach ($extensions as $name => $extension) {
      $package_name = str_starts_with($extension->getPath(), 'core/')
        ? 'drupal/core'
        : 'drupal/' . ($extension->info['project'] ?? $name);

      try {
        $version = InstalledVersions::getPrettyVersion($package_name);
        $stability = VersionParser::parseStability($version);
        $stability = VersionParser::normalizeStability($stability);
      }
      catch (\OutOfBoundsException) {
        $message = $this->t('Cannot determine a version constraint for @type @name because the package @package does not appear to be installed. Falling back to an allow-all (*) constraint for now, but it is strongly recommended that you adjust this.', [
          '@type' => $extension->getType(),
          '@name' => $name,
          '@package' => $package_name,
        ]);
        $this->logger?->warning((string) $message);

        $version = '*';
        $stability = 'dev';
      }
      $requirements[$package_name] = $stability === 'dev' ? $version : "^$version";

      if ($stability !== 'stable') {
        $message = $this->t('Package @package has a @stability version constraint, which may prevent the recipe from being installed into projects that require stable dependencies.', [
          '@stability' => $stability,
          '@package' => $package_name,
        ]);
        $this->logger?->warning((string) $message);
      }
    }
    return $requirements;
  }

  /**
   * Alters `composer.json` to match the site being exported.
   *
   * - The `type` key is always set to `drupal-recipe`.
   * - Version constraints are generated for all installed extensions and added
   *   to the `require` section; existing constraints are preserved.
   * - If not already defined, the `name` key is automatically generated from
   *   the destination directory name.
   * - If not already defined, the `version` key is set to `1.0.0`.
   *
   * @param string $destination
   *   The directory where the site is being exported.
   * @param \Drupal\Core\Extension\Extension[] $extensions
   *   All installed extensions.
   * @param list<string> $recipes
   *   The list of recipes that was extracted from `recipe.yml`. These will all
   *   be removed from the Composer requirements.
   */
  private function updateComposerJson(string $destination, array $extensions, array $recipes): void {
    $data = [];

    $destination .= '/composer.json';
    if (file_exists($destination)) {
      $data = file_get_contents($destination);
      $data = Json::decode($data);
    }

    // Rebuild the list of requirements, excluding any recipes that were found
    // in `recipe.yml`, since we don't need them if we're exporting a monolithic
    // site template.
    $requirements = array_merge(
      $this->getExtensionRequirements($extensions),
      $data['require'] ?? [],
    );
    foreach (array_keys($requirements) as $package) {
      if (in_array(basename($package), $recipes, TRUE)) {
        unset($requirements[$package]);
      }
    }
    $data['require'] = $requirements;

    $data['type'] = Recipe::COMPOSER_PROJECT_TYPE;
    // The site template is not installable without a name, so set a sensible
    // default.
    $data['name'] = 'drupal/' . basename(dirname($destination));
    // Remove development-only stuff from drupal_cms_site_template_base (and
    // possibly others).
    unset(
      $data['require']['drupal/site_template_helper'],
      $data['extra']['drupal-site-template'],
    );

    $data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($destination, $data);
  }

  /**
   * Alters `recipe.yml` to match the site being exported.
   *
   * - The `name` key, if defined in recipe.yml, is preserved. Otherwise, it
   *   defaults to the site name.
   * - The `type` key is always set to `Site`.
   * - Any extensions which are not already in the `install` list will be
   *   appended to it.
   * - The given config actions will be deep-merged into the config actions
   *   already in `recipe.yml`, with the given config actions "winning" any
   *   conflicts.
   *
   * @param string $destination
   *   The directory where the site is being exported.
   * @param string[] $extensions
   *   Machine names names of the installed extensions.
   * @param array $actions
   *   Config actions to be merged into the recipe.
   *
   * @return list<string>
   *   The `recipes` key from `recipe.yml`, which is a list of recipes that need
   *   to be removed from `composer.json`.
   */
  private function updateRecipe(string $destination, array $extensions, array $actions): array {
    $data = [];

    $destination .= '/recipe.yml';
    if (file_exists($destination)) {
      $data = file_get_contents($destination);
      $data = Yaml::decode($data);
    }
    $data['name'] ??= $this->configFactory->get('system.site')->get('name');
    $data['type'] = 'Site';

    // Add any new extensions to the recipe's install list, preserving the order
    // of the extant list (if there is one).
    $data['install'] ??= [];
    array_push($data['install'], ...array_diff($extensions, $data['install']));

    // The passed-in config actions overwrite the ones in the recipe.
    $data['config']['actions'] = $actions + ($data['config']['actions'] ?? []);

    // Do a lenient comparison against extant config. In the early installer,
    // the active config storage will be an InstallStorage object that has
    // loaded and enumerated all available simple config as shipped by the
    // providing modules. This is very likely to differ from the simple config
    // shipped with the recipe, so strict mode will very likely fail. If this
    // recipe is a site template being applied at install time (which is the
    // main to export a site as a recipe), lenient mode doesn't make much
    // difference; during the actual install process, only the config shipped
    // with required modules (e.g., System and User) will be present when the
    // recipe is applied, and that stuff is exported as config actions. We use
    // the null-coalescing assignment here in case we're updating a recipe that
    // has, for whatever reason, explicitly opted in to strict mode.
    $data['config']['strict'] ??= FALSE;

    // We are exporting a monolithic site template. Almost by definition, then,
    // we do not need to apply any other recipes beforehand, since they would
    // just be overridden by the site template anyway.
    $recipes = $data['recipes'] ?? [];
    unset($data['recipes']);

    file_put_contents($destination, Yaml::encode($data));
    return $recipes;
  }

  /**
   * Exports a config item as a config action.
   *
   * @param string $name
   *   The name of the config item.
   * @param array $data
   *   The config item's data.
   *
   * @return array
   *   The config item, represented as a config action.
   */
  private function toAction(string $name, array $data): array {
    $entity_keys = $this->configManager->loadConfigEntityByName($name)
      ?->getEntityType()
      ->getKeys();

    // If we have an array of entity keys, then this is a config entity.
    if (is_array($entity_keys)) {
      // The `id` and `uuid` keys cannot be changed by the `setProperties`
      // config action, so delete them. In fact, we don't need to touch ANY
      // entity keys.
      // @see \Drupal\Core\Config\Action\Plugin\ConfigAction\SetProperties
      foreach ($entity_keys as $key) {
        unset($data[$key]);
      }
      // Let dependencies be recalculated on save.
      unset($data['dependencies']);

      return ['setProperties' => $data];
    }
    return ['simpleConfigUpdate' => $data];
  }

  /**
   * Determines if a config object needs to be exported in config actions.
   *
   * This is true for all default config shipped by core itself, as well as the
   * System and User modules, because those are guaranteed to be installed
   * before anything else is.
   *
   * @param string $name
   *   The name of a config object.
   *
   * @return bool
   *   Whether the config object can be exported as a file, or needs to be
   *   represented as a config action.
   */
  private function isAction(string $name): bool {
    static $list;
    if ($list === NULL) {
      $list = [];

      $directory = InstallStorage::CONFIG_INSTALL_DIRECTORY;
      $storages = [
        new FileStorage($this->appRoot . "/core/$directory"),
        new FileStorage($this->moduleList->getPath('system') . "/$directory"),
        new FileStorage($this->moduleList->getPath('user') . "/$directory"),
      ];
      foreach ($storages as $storage) {
        $list = array_merge($list, $storage->listAll());
      }
    }
    return in_array($name, $list, TRUE);
  }

}
