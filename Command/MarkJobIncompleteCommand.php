<?php

namespace JMS\JobQueueBundle\Command;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Entity\Repository\JobManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MarkJobIncompleteCommand extends Command
{
    protected static $defaultName = 'jms-job-queue:mark-incomplete';

    private ManagerRegistry $registry;
    private JobManager $jobManager;

    public function __construct(ManagerRegistry $managerRegistry, JobManager $jobManager)
    {
        parent::__construct();

        $this->registry = $managerRegistry;
        $this->jobManager = $jobManager;
    }

    protected function configure()
    {
        $this->setName(self::$defaultName);
        $this
            ->setDescription('Internal command (do not use). It marks jobs as incomplete.')
            ->addArgument('job-id', InputArgument::REQUIRED, 'The ID of the Job.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var EntityManager $em */
        $em = $this->registry->getManagerForClass(Job::class);

        /** @var Job|null $job */
        $job = $em->createQuery("SELECT j FROM " . Job::class . " j WHERE j.id = :id")
            ->setParameter('id', $input->getArgument('job-id'))
            ->getOneOrNullResult();

        if ($job === null) {
            $output->writeln('<error>Job was not found.</error>');

            return 1;
        }

        $this->jobManager->closeJob($job, Job::STATE_INCOMPLETE);

        return 0;
    }
}
