<?php


namespace App;


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

    public function getFeedsConfig()
    {
        $feeds = $this->getConfig()->get('feeds');

        $query = 'select MAX(c.created_at) as last_updated, f.value from bolt_content as C, bolt_field as F WHERE f.content_id = c.id and f.name = \'author\' GROUP BY f.value';

        $connection = $this->manager->getConnection();
        $statement = $connection->prepare($query);
        $statement->execute();
        $results = $statement->fetchAll();

        foreach($results as $result) {
            $name = current(json_decode($result['value']));

            if (!empty($name) && isset($feeds[$name])) {
                $feeds[$name]['last_updated'] = $result['last_updated'];
            }
        }

        $feeds = (new Collection($feeds))->sortByDesc('last_updated')->all();

        return $feeds;
    }

}