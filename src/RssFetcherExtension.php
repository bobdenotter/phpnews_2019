<?php

namespace App;

use Bolt\Common\Json;
use Bolt\Entity\Content;
use Bolt\Entity\Field;
use Bolt\Entity\Taxonomy;
use Bolt\Entity\User;
use Bolt\Extension\BaseExtension;
use Bolt\Repository\TaxonomyRepository;
use Bolt\Repository\UserRepository;
use PicoFeed\Parser\Item;
use PicoFeed\Reader\Reader;
use Tightenco\Collect\Support\Collection;

class RssFetcherExtension extends BaseExtension
{
    /** @var TaxonomyRepository */
    private $taxonomyRepository;

    public function getName(): string
    {
        return "RSS Fetcher extension";
    }

    public function initialize(): void
    {
//        $ext = new RssTwigExtension();
//        $ext->setConfig($this->getConfig());
//        $this->registerTwigExtension($ext);
    }

    public function fetchFeed($feed)
    {
        $config = $this->getConfig();

        $reader = new Reader();

        try {
            $resource = $reader->download($feed['feed']);
        } catch (\Exception $e) {
            echo "### Error: couldn't download " . $feed['feed'] . "\n";
            return null;
        }

        try {
            $parser = $reader->getParser(
                $resource->getUrl(),
                $resource->getContent(),
                $resource->getEncoding()
            );

            // Return a Feed object
            $feed = $parser->execute();

        } catch (\Exception $e) {
            echo "### Error: couldn't parse " . $feed['feed'] . "\n";
            return null;
        }

        return array_slice($feed->getItems(), 0, $config->get('itemAmount', 3));

    }

    public function updateItems(string $name, array $feed, array $items)
    {
        $contentRepository = $this->objectManager->getRepository(Content::class);
        $userRepository = $this->objectManager->getRepository(User::class);
        $this->taxonomyRepository = $this->objectManager->getRepository(Taxonomy::class);

        $user = $userRepository->findOneBy(['username' => 'admin']);
        $contentTypeDefinition = $this->boltConfig->getContentType('feeditems');

        echo "## Feed: $name\n";

        /**
        fields:

        taxonomy: [ tags, authors ]
        */

        /** @var Item $item */
        foreach($items as $item) {

            $content = $contentRepository->findOneByFieldValue('itemid', $item->getId());

            if (!$content) {
                echo " - [new] ". $item->getTitle() . "\n";
                $content = new Content($contentTypeDefinition);
                $content->setStatus('published');
                $content->setAuthor($user);
            } else {
                echo " - [upd] ". $content->getId() . " - " . $item->getTitle() . "\n";
            }

            $content->setFieldValue('title', $item->getTitle());
            $content->setFieldValue('slug', $item->getTitle());
            $content->setFieldValue('itemid', $item->getId());
            $content->setFieldValue('content', $item->getContent());
            $content->setFieldValue('raw', $item->getXml());
            $content->setFieldValue('source', $item->getUrl());
            $content->setFieldValue('author', $name);
//            $content->setFieldValue('image', $item->getTitle());
            $content->setFieldValue('sitetitle', $item->getAuthor());
            $content->setFieldValue('sitesource', $feed['url']);

            $content->setCreatedAt($item->getDate());
            $content->setPublishedAt($item->getPublishedDate());
            $content->setModifiedAt($item->getUpdatedDate());

            $tags = [];
            foreach($item->getCategories() as $tag) {
                $tags[] = ['slug' => $tag, 'name' => $name];
            }

            $this->updateTaxonomy($content, 'authors', [['slug' => $name, 'name' => $name]]);
            $this->updateTaxonomy($content, 'tags', $tags);

            $this->objectManager->persist($content);

        }

        $this->objectManager->flush();
    }

    private function updateTaxonomy(Content $content, string $key, $taxonomies): void
    {
        $taxonomies = (new Collection(Json::findArray($taxonomies)))->filter();

        // Remove old ones
        foreach ($content->getTaxonomies($key) as $current) {
            $content->removeTaxonomy($current);
        }

        // Then (re-) add selected ones
        foreach ($taxonomies as $taxo) {
            $taxonomy = $this->taxonomyRepository->findOneBy([
                'type' => $key,
                'slug' => $taxo['slug'],
            ]);

            if ($taxonomy === null) {
                $taxonomy = Taxonomy::factory($key, $taxo['slug'], $taxo['name']);
            }

            $content->addTaxonomy($taxonomy);
        }
    }

    public function fetchAllFeeds(?string $onlyFeed = null)
    {
        $feeds = $this->getConfig()->get('feeds');

        if ($onlyFeed && isset($feeds[$onlyFeed])) {
            $feeds = [$onlyFeed => $feeds[$onlyFeed]];
        }

        foreach ($feeds as $name => $feed) {

            if (isset($feed['skip']) && $feed['skip'] == 'true') {
                continue;
            }

            $feedItems = $this->fetchFeed($feed);

            if ($feedItems) {
                $this->updateItems($name, $feed, $feedItems);
            }
        }
    }

}