<?php

namespace AppBundle\Command;

use AppBundle\Entity\Version;
use Swarrot\Broker\Message;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command send contents to opt-in Messenger users.
 * It can send one content or many.
 *
 * Options priority is build this way:
 *     - one content
 *     - many contents
 */
class SyncVersionsCommand extends ContainerAwareCommand
{
    private $repoRepository;
    private $publisher;
    private $syncVersions;

    protected function configure()
    {
        $this
            ->setName('banditore:sync:versions')
            ->setDescription('Sync new version for each repository')
            ->addOption(
                'repo_id',
                null,
                InputOption::VALUE_REQUIRED,
                'Retrieve version only for that repository (using its id)'
            )
            ->addOption(
                'repo_name',
                null,
                InputOption::VALUE_REQUIRED,
                'Retrieve version only for that repository (using it full name: username/repo)'
            )
            ->addOption(
                'use_queue',
                null,
                InputOption::VALUE_NONE,
                'Push each repo into a queue instead of fetching it right away'
            )
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // define services for a later use
        $this->repoRepository = $this->getContainer()->get('banditore.repository.repo');
        $this->publisher = $this->getContainer()->get('swarrot.publisher');
        $this->syncVersions = $this->getContainer()->get('banditore.consumer.sync_versions');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $repos = [];

        if ($input->getOption('repo_id')) {
            $repos = [$input->getOption('repo_id')];
        } elseif ($input->getOption('repo_name')) {
            $repo = $this->repoRepository->findOneByFullName($input->getOption('repo_name'));

            if ($repo) {
                $repos = [$repo->getId()];
            }
        } else {
            $repos = $this->repoRepository->findAllForRelease();
        }

        if (count(array_filter($repos)) <= 0) {
            $output->writeln('<error>No repos found</error>');

            return 1;
        }

        $repoChecked = 0;
        $totalRepos = count($repos);

        foreach ($repos as $repoId) {
            ++$repoChecked;

            $output->writeln('[' . $repoChecked . '/' . $totalRepos . '] Check <info>' . $repoId . '</info> … ');

            $message = new Message(json_encode([
                'repo_id' => $repoId,
            ]));

            if ($input->getOption('use_queue')) {
                $this->publisher->publish(
                    'banditore.sync_versions.publisher',
                    $message
                );
            } else {
                $this->syncVersions->process(
                    $message,
                    []
                );
            }
        }

        $output->writeln('<info>Repo checked: ' . $repoChecked . '</info>');

        return 0;
    }
}