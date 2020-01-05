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
use PicoFeed\Config\Config;
use PicoFeed\Parser\Item;
use PicoFeed\Reader\Reader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\ItemInterface;
use Tightenco\Collect\Support\Collection;

class RssFetcherExtension extends BaseExtension
{
    /** @var TaxonomyRepository */
    private $taxonomyRepository;

    /** @var bool */
    private $verbose = false;

    private $amountOfItems;

    /** @var Collection */
    private $feeds;

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

    public function getFeed(array $feedDetails, ?bool $feedOnly): array
    {
        $this->getStopwatch()->start('ext.fetch');

        $feedItems = $this->fetchFeed($feedDetails, $feedOnly);

        $this->getStopwatch()->stop('ext.fetch');

        if ($feedOnly) {
            return $feedItems;
        }

        return array_slice($feedItems, 0, $this->amountOfItems);
    }

    private function fetchFeed(array $feedDetails): array
    {
        $config = new Config();
        $config->setFilterWhitelistedTags($this->allowedTags());

        $reader = new Reader($config);

        try {
            $resource = $reader->download($feedDetails['feed']);
        } catch (\Exception $e) {
            echo "### Error: couldn't download " . $feedDetails['feed'] . "\n";
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
            echo "### Error: couldn't parse " . $feedDetails['feed'] . "\n";
            return null;
        }

        return $feed->getItems();
    }

    public function updateItems(string $name, array $feed, array $items, ?string $onlyFeed = null)
    {
        $this->getStopwatch()->start('ext.storage');

        $om = $this->getObjectManager();
        $contentRepository = $om->getRepository(Content::class);
        $userRepository = $om->getRepository(User::class);
        $this->taxonomyRepository = $om->getRepository(Taxonomy::class);

        $user = $userRepository->findOneBy(['username' => 'admin']);
        $contentTypeDefinition = $this->getBoltConfig()->getContentType('feeditems');
        $updated = $feed['last_fetched'] ?? 'never';

        echo "\n\n## Feed: $name <small>{$feed['feed']} / $updated</small>\n\n";

        /** @var Item $item */
        foreach($items as $item) {

            $this->getStopwatch()->start('ext.storage.fetch');

            $content = $contentRepository->findOneByFieldValue('itemid', $item->getId());

            $this->getStopwatch()->stop('ext.storage.fetch');

            if (!$content) {
                echo " - [new] ". $item->getTitle() . "\n";
                $content = new Content($contentTypeDefinition);
                $content->setStatus('published');
                $content->setAuthor($user);
                $new = true;
                $this->getLogger()->notice(
                    "[RSS feed] New item added: " . $item->getTitle(),
                    ['source' => $item->getUrl()]
                );
            } else {
                printf(" - [upd] <a href='/bolt/edit/%d'>%d</a> - %s\n",
                    $content->getId(),
                    $content->getId(),
                    $item->getTitle()
                );
                $new = false;
            }

            $image = $item->getEnclosureUrl() ?: $this->findImage($item, $content, $feed['url']);

            $content->setFieldValue('title', $item->getTitle());
            $content->setFieldValue('slug', $item->getTitle());
            $content->setFieldValue('itemid', $item->getId());
            $content->setFieldValue('content', $item->getContent());
            $content->setFieldValue('raw', $item->getXml());
            $content->setFieldValue('source', $item->getUrl());
            $content->setFieldValue('author', $name);
            $content->setFieldValue('image', $image);
            $content->setFieldValue('sitetitle', $item->getAuthor());
            $content->setFieldValue('sitesource', $feed['url']);

            if ($item->getDate() < new \DateTime('-1 minute')) {
                $content->setCreatedAt($item->getDate());
                $content->setPublishedAt($item->getPublishedDate());
                $content->setModifiedAt($item->getUpdatedDate());
            } elseif ($new) {
                $content->setCreatedAt(new \DateTime('now'));
                $content->setPublishedAt(new \DateTime('now'));
                $content->setModifiedAt(new \DateTime('now'));
            }

            $tags = [];
            foreach($item->getCategories() as $tag) {
                $tags[] = ['slug' => $tag, 'name' => $name];
            }

            $this->getStopwatch()->start('ext.storage.taxo');

            $this->updateTaxonomy($content, 'authors', [['slug' => $name, 'name' => $name]]);
            $this->updateTaxonomy($content, 'tags', $tags);

            $this->getStopwatch()->stop('ext.storage.taxo');

            $this->getStopwatch()->start('ext.storage.persist');

            $om->persist($content);

            // if ($this->verbose) {
            //     dump($item);
            //     dump($content);
            // }

            $this->getStopwatch()->stop('ext.storage.persist');

            // If this item is stale, let's assume the rest are too.
            if (!$new && !$onlyFeed) {
                break;
            }

        }
        $this->getStopwatch()->stop('ext.storage');

        $om->flush();
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
        $feeds = (new Collection($this->getFeedsConfigAll()))->sortBy('last_fetched')->all();

        $request = Request::createFromGlobals();
        if ($this->getConfig()->get('itemAmount') < 6 && $request->get('verbose')) {
            $this->verbose = true;
        }

        $this->amountOfItems = $request->get('amount', $this->getConfig()->get('itemAmount'), 5);
        $this->amountOfFeeds = $this->getConfig()->get('feedsAmount', 6);

        if ($onlyFeed) {
            if (isset($feeds[$onlyFeed])) {
                $feeds = [$onlyFeed => $feeds[$onlyFeed]];
            } else {
                throw new \Exception('Pass in a valid feed name');
            }
        }

        foreach ($feeds as $name => $feed) {

            if (isset($feed['active']) && $feed['active'] == false) {
                continue;
            }

            if ($this->amountOfFeeds-- <= 0) {
                break;
            }

            $feedItems = $this->getFeed($feed, (bool) $onlyFeed);

            if ($feedItems) {
                $this->updateItems($name, $feed, $feedItems, (bool) $onlyFeed);
            }
        }
    }


    /**
     * First see if we van get it from some non-standard tag,
     *   <media:thumbnail url=""> — Youtube feed
     *   <media:content url=""> — paper.li feed
     *   <featuredImage> or <youtubeImage> — Cupfighter
     *   <enclosureUrl> — Bolt RSS feed
     *
     * @param Item   $item
     * @param string $html
     * @param string $baseUrl
     *
     * @return string
     */
    private function findImage(Item $item, $html, $baseUrl)
    {
        if ($item->hasNamespace('media')) {
            $value = $item->getTag('media:thumbnail', 'url');
            if ($image = $this->fixImageLink(current($value), $baseUrl)) {
                return $image;
            }
            $value = $item->getTag('media:content', 'url');
            if ($image = $this->fixImageLink(current($value), $baseUrl)) {
                return $image;
            }
        }
        if ($item->xml->featuredImage) {
            $value = $item->xml->featuredImage;
            if ($image = $this->fixImageLink(current($value), $baseUrl)) {
                return $image;
            }
        }
        if ($item->xml->youtubeImage) {
            $value = $item->xml->youtubeImage;
            if ($image = $this->fixImageLink(current($value), $baseUrl)) {
                return $image;
            }
        }
        if ($item->hasNamespace('image')) {
            $value = $item->getTag('image');
            if ($image = $this->fixImageLink(current($value), $baseUrl)) {
                return $image;
            }
        }
        if ($item->hasNamespace('enclosure')) {
            $value = $item->getTag('enclosure', 'url');
            if ($image = $this->fixImageLink(current($value), $baseUrl)) {
                return $image;
            }
        }

        // Find one in the parsed RSS item, perhaps?
        if ($item->getContent() != '') {
            $doc = new \DOMDocument();
            $doc->loadHTML($item->getContent());
            /** @var \DOMNodeList $tags */
            $tags = $doc->getElementsByTagName('img');
            /** @var \DOMElement $tag */
            foreach ($tags as $tag) {
                $image = $tag->getAttribute('src');
                if ($image = $this->fixImageLink($image, $baseUrl)) {
                    return $image;
                }
            }
        }

        return '';
    }

    /**
     * Hack a valid link
     *
     * @param string $image
     * @param string $baseUrl
     *
     * @return string
     */
    private function fixImageLink($image, $baseUrl)
    {
        if (empty($image)) {
            return null;
        }
        if (strpos($image, 'http') === false) {
            $baseUrl = parse_url($baseUrl);
            $image = $baseUrl['scheme'] . '://' . $baseUrl['host'] . $image;
        }
        // skip Feedburner, Gravatar, Medium pixels, WP emoji, etc.
        $skippartials = ['feedburner.com', 'flattr.com', 'stat?event', 's.w.org', 'gravatar', 'placeholder'];
        foreach($skippartials as $partial) {
            if (strpos($image, $partial) > 0) {
                return null;
            }
        }
        return $image;
    }

    public function getFeedsConfigAll(): array
    {
        if ($this->feeds) {
            return $this->feeds;
        }

        $feeds = $this->getConfig()->get('feeds');

        $query = 'select MAX(c.created_at) as last_updated, MAX(c.modified_at) as last_fetched, f.value from bolt_content as c, bolt_field as f WHERE f.content_id = c.id and f.name = \'author\' GROUP BY f.value';

        $connection = $this->getObjectManager()->getConnection();
        $statement = $connection->prepare($query);
        $statement->execute();
        $this->queryResult = $statement->fetchAll();

        foreach($this->queryResult as $result) {
            $name = current(json_decode($result['value']));

            if (!empty($name) && isset($feeds[$name])) {
                $feeds[$name]['last_updated'] = $result['last_updated'];
                $feeds[$name]['last_fetched'] = $result['last_fetched'];
            }
        }

        $this->feeds = (new Collection($feeds))->sortByDesc('last_updated')->all();

        return $this->feeds;
    }

    public function allowedTags(): array
    {
        return [
            'a' => [ 'href', 'name', 'target' ],
            'b' => [],
            'blockquote' => ['cite'],
            'br' => [],
            'caption' => [],
            'code' => ['class'],
            'div',
            'em' => [],
            'h1' => [],
            'h2' => [],
            'h3' => [],
            'h4' => [],
            'h5' => [],
            'h6' => [],
            'hr' => [],
            'i' => [],
            'img' => ['src', 'title'],
            'iframe' => ['src'],
            'li' => [],
            'nl' => [],
            'p' => [],
            'pre' => ['class'],
            'strike' => [],
            'strong' => [],
            'table' => [],
            'tbody' => [],
            'td' => [],
            'th' => [],
            'thead' => [],
            'tr' => ['rowspan', 'colspan'],
            'ul' => []
        ];
    }
}