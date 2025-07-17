<?php

namespace JMS\JobQueueBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'jms_cron_jobs')]
class CronJob
{
    #[ORM\Id]
    private $id;

    #[ORM\Column(type: 'string', length: 200, unique: true)]
    private $command;

    #[ORM\Column(type: 'datetime', name: 'lastRunAt')]
    private \DateTime $lastRunAt;

    public function __construct($command)
    {
        $this->command = $command;
        $this->lastRunAt = new \DateTime();
    }

    public function getCommand()
    {
        return $this->command;
    }

    public function getLastRunAt(): \DateTime
    {
        return $this->lastRunAt;
    }
}