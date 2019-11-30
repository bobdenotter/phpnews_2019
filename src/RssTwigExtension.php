<?php


namespace App;


use Bolt\Entity\Content;
use Bolt\Extension\ExtensionRegistry;
use Bolt\Repository\ContentRepository;
use Doctrine\Common\Persistence\ObjectManager;
use Tightenco\Collect\Support\Collection;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class RssTwigExtension extends AbstractExtension
{
    /** @var array */
    private $config;

    /** @var ExtensionRegistry */
    private $extensionRegistry;

    /** @var ContentRepository */
    private $contentRepository;

    /** @var ObjectManager */
    private $manager;

    /** @var Collection */
    private $feeds;

    public function __construct(ExtensionRegistry $extensionRegistry, ContentRepository $contentRepository, ObjectManager $manager)
    {
        $this->extensionRegistry = $extensionRegistry;
        $this->contentRepository = $contentRepository;
        $this->manager = $manager;
    }

    /**
     * Register Twig functions.
     */
    public function getFunctions(): array
    {
        $safe = [
            'is_safe' => ['html'],
        ];
        return [
            new TwigFunction('getFeedsConfig', [$this, 'getFeedsConfig']),
        ];
    }

    public function getConfig()
    {
        $ext = $this->extensionRegistry->getExtension('App\RssFetcherExtension');
        $this->config = $ext->getConfig();

        return $this->config;
    }

    public function getFeedsConfig(?Content $record = null): ?array
    {
        $feeds = $this->getFeedsConfigAll();

        if (!$record) {
            return $feeds;
        }

        if (!$record->hasField('author')) {
            return null;
        }

        $author = (string) $record->getField('author');

        if (array_key_exists($author, $feeds)) {
            return $feeds[$author];
        }

        return null;
    }

    public function getFeedsConfigAll(string $sortBy = 'last_updated'): array
    {
        $ext = $this->extensionRegistry->getExtension('App\RssFetcherExtension');
        $this->feeds = $ext->getFeedsConfigAll();

        return $this->feeds;
    }

}