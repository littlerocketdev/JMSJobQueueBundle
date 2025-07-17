<?php

/*
 * Copyright 2012 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\JobQueueBundle\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use JMS\JobQueueBundle\Exception\InvalidStateTransitionException;
use JMS\JobQueueBundle\Exception\LogicException;
use RuntimeException;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

/**
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'jms_jobs')]
#[ORM\Index('cmd_search_index', columns: ['command'])]
#[ORM\Index('sorting_index', columns: ['state', 'priority', 'id'])]
class Job
{
    /** State if job is inserted, but not yet ready to be started. */
    const STATE_NEW = 'new';

    /**
     * State if job is inserted, and might be started.
     *
     * It is important to note that this does not automatically mean that all
     * jobs of this state can actually be started, but you have to check
     * isStartable() to be absolutely sure.
     *
     * In contrast to NEW, jobs of this state at least might be started,
     * while jobs of state NEW never are allowed to be started.
     */
    const STATE_PENDING = 'pending';

    /** State if job was never started, and will never be started. */
    const STATE_CANCELED = 'canceled';

    /** State if job was started and has not exited, yet. */
    const STATE_RUNNING = 'running';

    /** State if job exists with a successful exit code. */
    const STATE_FINISHED = 'finished';

    /** State if job exits with a non-successful exit code. */
    const STATE_FAILED = 'failed';

    /** State if job exceeds its configured maximum runtime. */
    const STATE_TERMINATED = 'terminated';

    /**
     * State if an error occurs in the runner command.
     *
     * The runner command is the command that actually launches the individual
     * jobs. If instead an error occurs in the job command, this will result
     * in a state of FAILED.
     */
    const STATE_INCOMPLETE = 'incomplete';

    /**
     * State if an error occurs in the runner command.
     *
     * The runner command is the command that actually launches the individual
     * jobs. If instead an error occurs in the job command, this will result
     * in a state of FAILED.
     */
    const DEFAULT_QUEUE = 'default';
    const MAX_QUEUE_LENGTH = 50;

    const PRIORITY_LOW = -5;
    const PRIORITY_DEFAULT = 0;
    const PRIORITY_HIGH = 5;

    #[ORM\Id]
    private $id;

    #[ORM\Column(type: 'string', length: 15)]
    private string $state;

    #[ORM\Column(type: 'string', length: Job::MAX_QUEUE_LENGTH)]
    private $queue;

    #[ORM\Column(type: 'smallint')]
    private float|int $priority = 0;

    #[ORM\Column(type: 'datetime', name: 'createdAt')]
    private \DateTime $createdAt;

    #[ORM\Column(type: 'datetime', name: 'startedAt', nullable: true)]
    private ?\DateTime $startedAt = null;

    #[ORM\Column(type: 'datetime', name: 'checkedAt', nullable: true)]
    private ?\DateTime $checkedAt = null;

    #[ORM\Column(type: 'string', name: 'workerName', length: 50, nullable: true)]
    private $workerName;

    #[ORM\Column(type: 'datetime', name: 'executeAfter', nullable: true)]
    private \DateTime $executeAfter;

    #[ORM\Column(type: 'datetime', name: 'closedAt', nullable: true)]
    private ?\DateTime $closedAt = null;

    #[ORM\Column(type: 'string')]
    private $command;

    #[ORM\Column(type: 'json')]
    private array $args;

    #[ORM\ManyToMany(targetEntity: Job::class, fetch: 'EAGER')]
    #[ORM\JoinTable(name: 'jms_job_dependencies', joinColumns: [new ORM\JoinColumn(name: 'source_job_id', referencedColumnName: 'id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'dest_job_id', referencedColumnName: 'id')])]
    private \Doctrine\Common\Collections\ArrayCollection $dependencies;

    #[ORM\Column(type: 'text', nullable: true)]
    private $output;

    #[ORM\Column(type: 'text', name: 'errorOutput', nullable: true)]
    private $errorOutput;

    #[ORM\Column(type: 'smallint', name: 'exitCode', nullable: true, options: ['unsigned' => true])]
    private $exitCode;

    #[ORM\Column(type: 'smallint', name: 'maxRuntime', options: ['unsigned' => true])]
    private int $maxRuntime = 0;

    #[ORM\Column(type: 'smallint', name: 'maxRetries', options: ['unsigned' => true])]
    private int $maxRetries = 0;

    #[ORM\ManyToOne(targetEntity: Job::class, inversedBy: 'retryJobs')]
    #[ORM\JoinColumn(name: 'originalJob_id', referencedColumnName: 'id')]
    private ?\JMS\JobQueueBundle\Entity\Job $originalJob = null;

    #[ORM\OneToMany(targetEntity: Job::class, mappedBy: 'originalJob', cascade: ['persist', 'remove', 'detach', 'refresh'])]
    private \Doctrine\Common\Collections\ArrayCollection $retryJobs;

    #[ORM\Column(type: 'jms_job_safe_object', name: 'stackTrace', nullable: true)]
    private ?FlattenException $stackTrace = null;

    #[ORM\Column(type: 'smallint', nullable: true, options: ['unsigned' => true])]
    private ?int $runtime = null;

    #[ORM\Column(type: 'integer', name: 'memoryUsage', nullable: true, options: ['unsigned' => true])]
    private $memoryUsage;

    #[ORM\Column(type: 'integer', name: 'memoryUsageReal', nullable: true, options: ['unsigned' => true])]
    private $memoryUsageReal;

    /**
     * This may store any entities which are related to this job, and are
     * managed by Doctrine.
     *
     * It is effectively a many-to-any association.
     */
    private \Doctrine\Common\Collections\ArrayCollection $relatedEntities;

    public function __construct(
        $command,
        array $args = array(),
        $confirmed = true,
        $queue = self::DEFAULT_QUEUE,
        $priority = self::PRIORITY_DEFAULT
    ) {
        if (trim($queue) === '') {
            throw new InvalidArgumentException('$queue must not be empty.');
        }
        if (strlen($queue) > self::MAX_QUEUE_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'The maximum queue length is %d, but got "%s" (%d chars).',
                    self::MAX_QUEUE_LENGTH,
                    $queue,
                    strlen($queue)
                )
            );
        }

        $this->command = $command;
        $this->args = $args;
        $this->state = $confirmed ? self::STATE_PENDING : self::STATE_NEW;
        $this->queue = $queue;
        $this->priority = $priority * -1;
        $this->createdAt = new DateTime();
        $this->executeAfter = new DateTime();
        $this->executeAfter = $this->executeAfter->modify('-1 second');
        $this->dependencies = new ArrayCollection();
        $this->retryJobs = new ArrayCollection();
        $this->relatedEntities = new ArrayCollection();
    }

    public static function create(
        $command,
        array $args = array(),
        $confirmed = true,
        $queue = self::DEFAULT_QUEUE,
        $priority = self::PRIORITY_DEFAULT
    ): self {
        return new self($command, $args, $confirmed, $queue, $priority);
    }

    public static function getStates(): array
    {
        return array(
            self::STATE_NEW,
            self::STATE_PENDING,
            self::STATE_CANCELED,
            self::STATE_RUNNING,
            self::STATE_FINISHED,
            self::STATE_FAILED,
            self::STATE_TERMINATED,
            self::STATE_INCOMPLETE
        );
    }

    public function __clone()
    {
        $this->state = self::STATE_PENDING;
        $this->createdAt = new DateTime();
        $this->startedAt = null;
        $this->checkedAt = null;
        $this->closedAt = null;
        $this->workerName = null;
        $this->output = null;
        $this->errorOutput = null;
        $this->exitCode = null;
        $this->stackTrace = null;
        $this->runtime = null;
        $this->memoryUsage = null;
        $this->memoryUsageReal = null;
        $this->relatedEntities = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getWorkerName()
    {
        return $this->workerName;
    }

    public function setWorkerName($workerName): void
    {
        $this->workerName = $workerName;
    }

    public function getPriority(): int|float
    {
        return $this->priority * -1;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function getClosedAt()
    {
        return $this->closedAt;
    }

    public function getExecuteAfter(): \DateTime
    {
        return $this->executeAfter;
    }

    public function setExecuteAfter(DateTime $executeAfter): void
    {
        $this->executeAfter = $executeAfter;
    }

    public function getCommand()
    {
        return $this->command;
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function getRelatedEntities(): \Doctrine\Common\Collections\ArrayCollection
    {
        return $this->relatedEntities;
    }

    public function isClosedNonSuccessful(): bool
    {
        return self::isNonSuccessfulFinalState($this->state);
    }

    public static function isNonSuccessfulFinalState($state): bool
    {
        return in_array(
            $state,
            array(self::STATE_CANCELED, self::STATE_FAILED, self::STATE_INCOMPLETE, self::STATE_TERMINATED),
            true
        );
    }

    public function findRelatedEntity($class): ?object
    {
        foreach ($this->relatedEntities as $entity) {
            if ($entity instanceof $class) {
                return $entity;
            }
        }

        return null;
    }

    public function addRelatedEntity($entity): void
    {
        if (!is_object($entity)) {
            throw new RuntimeException('$entity must be an object.');
        }

        if ($this->relatedEntities->contains($entity)) {
            return;
        }

        $this->relatedEntities->add($entity);
    }

    public function getDependencies()
    {
        return $this->dependencies;
    }

    public function hasDependency(Job $job)
    {
        return $this->dependencies->contains($job);
    }

    public function addDependency(Job $job): void
    {
        if ($this->dependencies->contains($job)) {
            return;
        }

        if ($this->mightHaveStarted()) {
            throw new \LogicException('You cannot add dependencies to a job which might have been started already.');
        }

        $this->dependencies->add($job);
    }

    private function mightHaveStarted(): bool
    {
        if (null === $this->id) {
            return false;
        }

        if (self::STATE_NEW === $this->state) {
            return false;
        }

        if (self::STATE_PENDING === $this->state && !$this->isStartable()) {
            return false;
        }

        return true;
    }

    public function isStartable(): bool
    {
        foreach ($this->dependencies as $dep) {
            if ($dep->getState() !== self::STATE_FINISHED) {
                return false;
            }
        }

        return true;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState($newState): void
    {
        if ($newState === $this->state) {
            return;
        }

        switch ($this->state) {
            case self::STATE_NEW:
                if (!in_array($newState, array(self::STATE_PENDING, self::STATE_CANCELED), true)) {
                    throw new InvalidStateTransitionException(
                        $this,
                        $newState,
                        array(self::STATE_PENDING, self::STATE_CANCELED)
                    );
                }

                if (self::STATE_CANCELED === $newState) {
                    $this->closedAt = new DateTime();
                }

                break;

            case self::STATE_PENDING:
                if (!in_array($newState, array(self::STATE_RUNNING, self::STATE_CANCELED), true)) {
                    throw new InvalidStateTransitionException(
                        $this,
                        $newState,
                        array(self::STATE_RUNNING, self::STATE_CANCELED)
                    );
                }

                if ($newState === self::STATE_RUNNING) {
                    $this->startedAt = new DateTime();
                    $this->checkedAt = new DateTime();
                } else {
                    if ($newState === self::STATE_CANCELED) {
                        $this->closedAt = new DateTime();
                    }
                }

                break;

            case self::STATE_RUNNING:
                if (!in_array(
                    $newState,
                    array(self::STATE_FINISHED, self::STATE_FAILED, self::STATE_TERMINATED, self::STATE_INCOMPLETE)
                )) {
                    throw new InvalidStateTransitionException(
                        $this,
                        $newState,
                        array(self::STATE_FINISHED, self::STATE_FAILED, self::STATE_TERMINATED, self::STATE_INCOMPLETE)
                    );
                }

                $this->closedAt = new DateTime();

                break;

            case self::STATE_FINISHED:
            case self::STATE_FAILED:
            case self::STATE_TERMINATED:
            case self::STATE_INCOMPLETE:
                throw new InvalidStateTransitionException($this, $newState);

            default:
                throw new LogicException('The previous cases were exhaustive. Unknown state: ' . $this->state);
        }

        $this->state = $newState;
    }

    public function getRuntime()
    {
        return $this->runtime;
    }

    public function setRuntime($time): void
    {
        $this->runtime = (integer)$time;
    }

    public function getMemoryUsage()
    {
        return $this->memoryUsage;
    }

    public function getMemoryUsageReal()
    {
        return $this->memoryUsageReal;
    }

    public function addOutput(string $output): void
    {
        $this->output .= $output;
    }

    public function addErrorOutput(string $output): void
    {
        $this->errorOutput .= $output;
    }

    public function getOutput()
    {
        return $this->output;
    }

    public function setOutput($output): void
    {
        $this->output = $output;
    }

    public function getErrorOutput()
    {
        return $this->errorOutput;
    }

    public function setErrorOutput($output): void
    {
        $this->errorOutput = $output;
    }

    public function getExitCode()
    {
        return $this->exitCode;
    }

    public function setExitCode($code): void
    {
        $this->exitCode = $code;
    }

    public function getMaxRuntime()
    {
        return $this->maxRuntime;
    }

    public function setMaxRuntime($time): void
    {
        $this->maxRuntime = (integer)$time;
    }

    public function getStartedAt()
    {
        return $this->startedAt;
    }

    public function getMaxRetries()
    {
        return $this->maxRetries;
    }

    public function setMaxRetries($tries): void
    {
        $this->maxRetries = (integer)$tries;
    }

    public function isRetryAllowed()
    {
        // If no retries are allowed, we can bail out directly, and we
        // do not need to initialize the retryJobs relation.
        if (0 === $this->maxRetries) {
            return false;
        }

        return count($this->retryJobs) < $this->maxRetries;
    }

    public function getOriginalJob()
    {
        if (null === $this->originalJob) {
            return $this;
        }

        return $this->originalJob;
    }

    public function setOriginalJob(Job $job): void
    {
        if (self::STATE_PENDING !== $this->state) {
            throw new \LogicException($this . ' must be in state "PENDING".');
        }

        if (null !== $this->originalJob) {
            throw new \LogicException($this . ' already has an original job set.');
        }

        $this->originalJob = $job;
    }

    public function addRetryJob(Job $job): void
    {
        if (self::STATE_RUNNING !== $this->state) {
            throw new \LogicException('Retry jobs can only be added to running jobs.');
        }

        $job->setOriginalJob($this);
        $this->retryJobs->add($job);
    }

    public function getRetryJobs()
    {
        return $this->retryJobs;
    }

    public function isRetryJob(): bool
    {
        return null !== $this->originalJob;
    }

    public function isRetried(): bool
    {
        foreach ($this->retryJobs as $job) {
            /** @var Job $job */

            if (!$job->isInFinalState()) {
                return true;
            }
        }

        return false;
    }

    public function isInFinalState(): bool
    {
        return !$this->isNew() && !$this->isPending() && !$this->isRunning();
    }

    public function isNew(): bool
    {
        return self::STATE_NEW === $this->state;
    }

    public function isPending(): bool
    {
        return self::STATE_PENDING === $this->state;
    }

    public function isRunning(): bool
    {
        return self::STATE_RUNNING === $this->state;
    }

    public function checked(): void
    {
        $this->checkedAt = new DateTime();
    }

    public function getCheckedAt()
    {
        return $this->checkedAt;
    }

    public function getStackTrace()
    {
        return $this->stackTrace;
    }

    public function setStackTrace(FlattenException $ex): void
    {
        $this->stackTrace = $ex;
    }

    public function getQueue()
    {
        return $this->queue;
    }

    public function isCanceled(): bool
    {
        return self::STATE_CANCELED === $this->state;
    }

    public function isTerminated(): bool
    {
        return self::STATE_TERMINATED === $this->state;
    }

    public function isFailed(): bool
    {
        return self::STATE_FAILED === $this->state;
    }

    public function isFinished(): bool
    {
        return self::STATE_FINISHED === $this->state;
    }

    public function isIncomplete(): bool
    {
        return self::STATE_INCOMPLETE === $this->state;
    }

    public function __toString(): string
    {
        return sprintf('Job(id = %s, command = "%s")', $this->id, $this->command);
    }
}
