<?php

declare(strict_types=1);

namespace Drupal\drupal_cms_helper\Drush\Commands;

use Composer\InstalledVersions;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Recipe\Recipe;
use Drupal\drupal_cms_helper\SiteExporter;
use Drush\Commands\AutowireTrait;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @api
 *   The `site:export` command is part of Drupal CMS's developer-facing API and
 *   may be relied upon.
 *
 * @internal
 *   This is an internal part of Drupal CMS and may be changed or removed at any
 *   time without warning. External code should not interact with this class.
 */
#[AsCommand(
  name: 'site:export',
  description: "Exports the site's configuration and content as a recipe.",
  aliases: ['siex', 'six'],
)]
final class SiteExportCommand extends Command {

  use AutowireTrait;

  public function __construct(private readonly SiteExporter $exporter) {
    parent::__construct();
  }

  #[\Override]
  protected function configure(): void {
    $this->addOption('destination', NULL, InputOption::VALUE_REQUIRED, 'The destination directory where the site should be exported. Defaults to the location where Composer installs recipes.');
    $this->addOption('base', NULL, InputOption::VALUE_REQUIRED, 'The path of a recipe to use as a base for the export.');
  }

  #[\Override]
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new DrushStyle($input, $output);
    $destination = $input->getOption('destination') ?? $this->findDestination();
    if (is_dir($destination)) {
      $io->error("The destination directory $destination already exists.");
      return self::FAILURE;
    }
    $this->exporter->export($destination, $input->getOption('base'));

    $io->success("Recipe created at $destination");
    return self::SUCCESS;
  }

  private function findDestination(): string {
    ['install_path' => $project_root] = InstalledVersions::getRootPackage();
    // There's no need to use the realpath() method of FileSystemInterface; its
    // purpose is to handle stream wrappers, which are irrelevant here.
    $project_root = realpath($project_root);
    $data = file_get_contents($project_root . DIRECTORY_SEPARATOR . 'composer.json');
    $data = Json::decode($data);

    $installer_paths = $data['extra']['installer-paths'] ?? [];
    foreach ($installer_paths as $path => $criteria) {
      if (in_array('type:' . Recipe::COMPOSER_PROJECT_TYPE, $criteria, TRUE)) {
        $path = ltrim($path, '.' . DIRECTORY_SEPARATOR);
        return str_replace('{$name}', 'site_export', $project_root . DIRECTORY_SEPARATOR . $path);
      }
    }
    throw new \RuntimeException('Could not determine where Composer installs recipes.');
  }

}
