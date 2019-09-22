<?php


namespace App;

use Bolt\Controller\Backend\BackendZone;
use Bolt\Extension\ExtensionRegistry;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


/**
 * @Security("is_granted('ROLE_ADMIN')")
 */
class RssFetchController extends AbstractController implements BackendZone
{
    /** @var ExtensionRegistry */
    private $extensionRegistry;

    public function __construct(ExtensionRegistry $extensionRegistry)
    {
        $this->extensionRegistry = $extensionRegistry;
    }

    /**
     * @Route("/fetch", name="rss_fetch")
     */
    public function index(): Response
    {
        $rss = $this->extensionRegistry->getExtension(RssFetcherExtension::class);

        ob_start();

        $rss->fetchAllFeeds();

        $output = ob_get_clean();

        dump($output);

        $twigvars = [
            'title' => "RSS Fetcher",
            'mainMarkdown' => $output
        ];

        return $this->render('@bolt/pages/barebones.html.twig', $twigvars);
    }
}