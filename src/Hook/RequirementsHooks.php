<?php

declare(strict_types=1);

namespace Drupal\vallic_purge\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\vallic_purge\CdnPurger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Says on the status report whether purges are going anywhere, and why.
 *
 * With no CDN nothing is sent, which is the right answer and a silent one:
 * on the platform with the CDN off, or not on the platform at all.
 */
final class RequirementsHooks {

  use StringTranslationTrait;

  public function __construct(
    #[Autowire(service: 'vallic_purge.purger')]
    private readonly CdnPurger $purger,
    #[Autowire(service: 'queue')]
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * Read afresh on every call: the environment and the queue change.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   *
   * @phpstan-impure
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirement = ['title' => $this->t('Vallic Cloud CDN')];
    $endpoint = $this->purger->endpoint();

    if ($endpoint->isAvailable()) {
      $waiting = $this->queueFactory->get(CdnPurger::QUEUE)->numberOfItems();
      $requirement['value'] = $this->t('Purging');
      $requirement['description'] = $waiting === 0
        ? $this->t('Invalidated cache tags clear the pages carrying them.')
        : $this->t('Invalidated cache tags clear the pages carrying them. @count purge(s) wait for cron, because the platform could not be reached.', ['@count' => $waiting]);
      $requirement['severity'] = $waiting === 0 ? RequirementSeverity::OK : RequirementSeverity::Warning;
    }
    elseif ($endpoint->isOnPlatform()) {
      $requirement['value'] = $this->t('No CDN');
      $requirement['description'] = $this->t('This environment has no CDN, so there is nothing to clear and nothing is sent. Turn the CDN on for the project in the Vallic Cloud console.');
      $requirement['severity'] = RequirementSeverity::Warning;
    }
    else {
      $requirement['value'] = $this->t('Not on Vallic Cloud');
      $requirement['description'] = $this->t('Nothing is sent, and responses carry no CDN-Tag header.');
      $requirement['severity'] = RequirementSeverity::Info;
    }

    return ['vallic_purge' => $requirement];
  }

}
