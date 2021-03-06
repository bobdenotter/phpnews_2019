<?php


namespace App;

use Bolt\Extension\ExtensionRegistry;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


/**
 * @Security("is_granted('ROLE_ADMIN')")
 */
class RssFetchController extends AbstractController
{
    /** @var ExtensionRegistry */
    private $extensionRegistry;

    public function __construct(ExtensionRegistry $extensionRegistry)
    {
        $this->extensionRegistry = $extensionRegistry;
    }

    /**
     * @Route("/extension/fetch", name="rss_fetch")
     */
    public function index(Request $request): Response
    {
        $rss = $this->extensionRegistry->getExtension(RssFetcherExtension::class);

        $feed = $request->get('feed');

        ob_start();

        $rss->fetchAllFeeds($feed, null);

        $output = ob_get_clean();

        $twigvars = [
            'title' => "RSS Fetcher",
            'mainMarkdown' => $output
        ];

        return $this->render('@bolt/pages/barebones.html.twig', $twigvars);
    }
}