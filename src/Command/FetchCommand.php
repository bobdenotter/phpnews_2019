<?php

namespace App\Command;

use App\RssFetcherExtension;
use Bolt\Configuration\Config;
use Bolt\Extension\ExtensionRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class FetchCommand extends Command
{
    protected static $defaultName = 'app:fetch';

    /**
     * @var ExtensionRegistry
     */
    private $extensionRegistry;

    public function __construct(ExtensionRegistry $extensionRegistry, Config $config)
    {
        $this->extensionRegistry = $extensionRegistry;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Add a short description for your command')
            ->addArgument('onlyfeed', InputArgument::OPTIONAL, 'Fetch only this feed')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rss = $this->extensionRegistry->getExtension(RssFetcherExtension::class);

        $rss->fetchAllFeeds($input->getArgument('onlyfeed'));

        $io->success('Feed items fetched and imported');

        return 1;
    }
}
