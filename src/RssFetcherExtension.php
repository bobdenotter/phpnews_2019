<?php

namespace App;

use Bolt\Entity\Content;
use Bolt\Entity\Field;
use Bolt\Entity\User;
use Bolt\Extension\BaseExtension;
use Bolt\Repository\UserRepository;
use PicoFeed\Parser\Item;
use PicoFeed\Reader\Reader;

class RssFetcherExtension extends BaseExtension
{
    public function getName(): string
    {
        return "RSS Fetcher extension";
    }

    public function initialize(): void
    {

    }

    public function fetchFeed($feed)
    {
        dump($feed);

        $reader = new Reader();
        $resource = $reader->download($feed['feed']);
        $parser = $reader->getParser(
            $resource->getUrl(),
            $resource->getContent(),
            $resource->getEncoding()
        );

        // Return a Feed object
        $feed = $parser->execute();

        return $feed->getItems();

    }

    public function updateItems(string $name, array $feed, array $items)
    {
        $contentRepository = $this->objectManager->getRepository(Content::class);
        $userRepository = $this->objectManager->getRepository(User::class);

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
                echo " - [upd] ". $item->getTitle() . "\n";
            }

            $content->setFieldValue('title', $item->getTitle());
            $content->setFieldValue('slug', $item->getTitle());
            $content->setFieldValue('itemid', $item->getId());
            $content->setFieldValue('content', $item->getContent());
            $content->setFieldValue('raw', $item->getXml());
            $content->setFieldValue('source', $item->getUrl());
            $content->setFieldValue('author', $item->getAuthor());
//            $content->setFieldValue('image', $item->getTitle());
            $content->setFieldValue('sitetitle', $name);
            $content->setFieldValue('sitesource', $feed['url']);

            $content->setCreatedAt($item->getDate());
            $content->setPublishedAt($item->getPublishedDate());
            $content->setModifiedAt($item->getUpdatedDate());

            dump($item);

            $this->objectManager->persist($content);

        }

        $this->objectManager->persist($content);
        $this->objectManager->flush();
    }

    public function fetchAllFeeds()
    {
        foreach ($this->getConfig()->get('feeds') as $name => $feed) {
            $feedItems = $this->fetchFeed($feed);
            $this->updateItems($name, $feed, $feedItems);
        }
    }

}