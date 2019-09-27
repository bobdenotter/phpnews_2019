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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\ItemInterface;
use Tightenco\Collect\Support\Collection;

class RssFetcherExtension extends BaseExtension
{
    /** @var TaxonomyRepository */
    private $taxonomyRepository;

    /** @var bool */
    private $verbose = false;

    private $amount;

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

    public function getFeed(array $feedDetails)
    {
        $config = $this->getConfig();

        $this->getStopwatch()->start('ext.fetch');

        $feedItems = $this->fetchFeed($feedDetails);

        $this->getStopwatch()->stop('ext.fetch');

        return array_slice($feedItems, 0, $this->amount);

    }

    private function fetchFeed(array $feedDetails)
    {

        $reader = new Reader();

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

    public function updateItems(string $name, array $feed, array $items)
    {
        $this->getStopwatch()->start('ext.storage');

        $om = $this->getObjectManager();
        $contentRepository = $om->getRepository(Content::class);
        $userRepository = $om->getRepository(User::class);
        $this->taxonomyRepository = $om->getRepository(Taxonomy::class);

        $user = $userRepository->findOneBy(['username' => 'admin']);
        $contentTypeDefinition = $this->getBoltConfig()->getContentType('feeditems');

        echo "## Feed: $name <small>{$feed['feed']}</small>\n";

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

            if ($this->verbose) {
                dump($item);
                dump($content);
            }

            $this->getStopwatch()->stop('ext.storage.persist');


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
        $feeds = $this->getConfig()->get('feeds');

        $request = Request::createFromGlobals();
        if ($this->getConfig()->get('itemAmount') < 6 && $request->get('verbose')) {
            $this->verbose = true;
        }

        if ($request->get('amount')) {
            $this->amount = $request->get('amount', $this->getConfig()->get('itemAmount'), 5);
        }

        if ($onlyFeed) {
            if (isset($feeds[$onlyFeed])) {
                $feeds = [$onlyFeed => $feeds[$onlyFeed]];
            } else {
                throw new \Exception('Pass in a valid feed name');
            }
        }

        foreach ($feeds as $name => $feed) {

            if (isset($feed['skip']) && $feed['skip'] == 'true') {
                continue;
            }

            $feedItems = $this->getFeed($feed);

            if ($feedItems) {
                $this->updateItems($name, $feed, $feedItems);
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

}