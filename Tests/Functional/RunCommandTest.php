<?php

namespace JMS\JobQueueBundle\Tests\Functional;

use JMS\JobQueueBundle\Entity\Job;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\Output;

class RunCommandTest extends BaseTestCase
{
    private \Symfony\Bundle\FrameworkBundle\Console\Application $app;
    private $em;

    public function testRun(): void
    {
        $a = new Job('adoigjaoisdjfijasodifjoiajsdf');
        $b = new Job('b', array('foo'));
        $b->addDependency($a);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $output = $this->runConsoleCommand(array('--max-runtime' => 5, '--worker-name' => 'test'));
        $expectedOutput = "Started Job(id = 1, command = \"adoigjaoisdjfijasodifjoiajsdf\").\n"
            . "Job(id = 1, command = \"adoigjaoisdjfijasodifjoiajsdf\") finished with exit code 1.\n";
        $this->assertEquals($expectedOutput, $output);
        $this->assertEquals('failed', $a->getState());
        $this->assertEquals('', $a->getOutput());
        $this->assertContains('Command "adoigjaoisdjfijasodifjoiajsdf" is not defined.', $a->getErrorOutput());
        $this->assertEquals('canceled', $b->getState());
    }

    private function runConsoleCommand(array $args = array())
    {
        array_unshift($args, 'jms-job-queue:run');
        $output = new MemoryOutput();

        $_SERVER['SYMFONY_CONSOLE_FILE'] = __DIR__ . '/console';
        $this->app->run(new ArrayInput($args), $output);

        return $output->getOutput();
    }

    public function testExitsAfterMaxRuntime(): void
    {
        $time = time();
        $output = $this->runConsoleCommand(array('--max-runtime' => 1, '--worker-name' => 'test'));
        $this->assertEquals('', $output);

        $runtime = time() - $time;
        $this->assertTrue($runtime >= 2 && $runtime < 8);
    }

    public function testSuccessfulCommand(): void
    {
        $job = new Job('jms-job-queue:successful-cmd');
        $this->em->persist($job);
        $this->em->flush($job);

        $this->runConsoleCommand(array('--max-runtime' => 1, '--worker-name' => 'test'));
        $this->assertEquals('finished', $job->getState());
    }

    /**
     * @group queues
     */
    public function testQueueWithLimitedConcurrentJobs(): void
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'job-output');
        for ($i = 0; $i < 4; $i++) {
            $job = new Job('jms-job-queue:logging-cmd', array('Job' . $i, $outputFile, '--runtime=1'));
            $this->em->persist($job);
        }

        $this->em->flush();

        $this->runConsoleCommand(array('--max-runtime' => 15, '--worker-name' => 'test'));

        $output = file_get_contents($outputFile);
        unlink($outputFile);

        $this->assertEquals(
            <<<OUTPUT
Job0 started
Job0 stopped
Job1 started
Job1 stopped
Job2 started
Job2 stopped
Job3 started
Job3 stopped

OUTPUT
            ,
            $output
        );
    }

    /**
     * @group queues
     */
    public function testQueueWithMoreThanOneConcurrentJob(): void
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'job-output');
        for ($i = 0; $i < 3; $i++) {
            $job = new Job('jms-job-queue:logging-cmd', array('Job' . $i, $outputFile, '--runtime=4'), true, 'foo');
            $this->em->persist($job);
        }
        $this->em->flush();

        $output = $this->runConsoleCommand(array('--max-runtime' => 15, '--worker-name' => 'test'));
        unlink($outputFile);

        $this->assertStringStartsWith(
            <<<OUTPUT
Started Job(id = 1, command = "jms-job-queue:logging-cmd").
Started Job(id = 2, command = "jms-job-queue:logging-cmd").
OUTPUT
            ,
            $output
        );

        $this->assertStringStartsNotWith(
            <<<OUTPUT
Started Job(id = 1, command = "jms-job-queue:logging-cmd").
Started Job(id = 2, command = "jms-job-queue:logging-cmd").
Started Job(id = 3, command = "jms-job-queue:logging-cmd").
OUTPUT
            ,
            $output
        );
    }

    /**
     * @group queues
     */
    public function testSingleRestrictedQueue(): void
    {
        $a = new Job('jms-job-queue:successful-cmd');
        $b = new Job('jms-job-queue:successful-cmd', array(), true, 'other_queue');
        $c = new Job('jms-job-queue:successful-cmd', array(), true, 'yet_another_queue');
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($c);
        $this->em->flush();

        $this->runConsoleCommand(
            array('--max-runtime' => 1, '--queue' => array('other_queue'), '--worker-name' => 'test')
        );
        $this->assertEquals(Job::STATE_PENDING, $a->getState());
        $this->assertEquals(Job::STATE_FINISHED, $b->getState());
        $this->assertEquals(Job::STATE_PENDING, $c->getState());
    }

    /**
     * @group queues
     */
    public function testMultipleRestrictedQueues(): void
    {
        $a = new Job('jms-job-queue:successful-cmd');
        $b = new Job('jms-job-queue:successful-cmd', array(), true, 'other_queue');
        $c = new Job('jms-job-queue:successful-cmd', array(), true, 'yet_another_queue');
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($c);
        $this->em->flush();

        $this->runConsoleCommand(
            array(
                '--max-runtime' => 1,
                '--queue' => array('other_queue', 'yet_another_queue'),
                '--worker-name' => 'test'
            )
        );
        $this->assertEquals(Job::STATE_PENDING, $a->getState());
        $this->assertEquals(Job::STATE_FINISHED, $b->getState());
        $this->assertEquals(Job::STATE_FINISHED, $c->getState());
    }

    /**
     * @group queues
     */
    public function testNoRestrictedQueue(): void
    {
        $a = new Job('jms-job-queue:successful-cmd');
        $b = new Job('jms-job-queue:successful-cmd', array(), true, 'other_queue');
        $c = new Job('jms-job-queue:successful-cmd', array(), true, 'yet_another_queue');
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($c);
        $this->em->flush();

        $this->runConsoleCommand(array('--max-runtime' => 1, '--worker-name' => 'test'));
        $this->assertEquals(Job::STATE_FINISHED, $a->getState());
        $this->assertEquals(Job::STATE_FINISHED, $b->getState());
        $this->assertEquals(Job::STATE_FINISHED, $c->getState());
    }

    /**
     * @group retry
     */
    public function testRetry(): void
    {
        $job = new Job('jms-job-queue:sometimes-failing-cmd', array(time()));
        $job->setMaxRetries(5);
        $this->em->persist($job);
        $this->em->flush($job);

        $this->runConsoleCommand(array('--max-runtime' => 12, '--verbose' => null, '--worker-name' => 'test'));

        $this->assertEquals('finished', $job->getState());
        $this->assertGreaterThan(0, count($job->getRetryJobs()));
        $this->assertEquals(1, $job->getExitCode());
    }

    public function testJobIsTerminatedIfMaxRuntimeIsExceeded(): void
    {
        $job = new Job('jms-job-queue:never-ending');
        $job->setMaxRuntime(1);
        $this->em->persist($job);
        $this->em->flush($job);

        $this->runConsoleCommand(array('--max-runtime' => 1, '--worker-name' => 'test'));
        $this->assertEquals('terminated', $job->getState());
    }

    /**
     * @group priority
     */
    public function testJobsWithHigherPriorityAreStartedFirst(): void
    {
        $job = new Job('jms-job-queue:successful-cmd');
        $this->em->persist($job);

        $job = new Job('jms-job-queue:successful-cmd', array(), true, Job::DEFAULT_QUEUE, Job::PRIORITY_HIGH);
        $this->em->persist($job);
        $this->em->flush();

        $output = $this->runConsoleCommand(array('--max-runtime' => 4, '--worker-name' => 'test'));

        $this->assertEquals(
            <<<OUTPUT
Started Job(id = 2, command = "jms-job-queue:successful-cmd").
Job(id = 2, command = "jms-job-queue:successful-cmd") finished with exit code 0.
Started Job(id = 1, command = "jms-job-queue:successful-cmd").
Job(id = 1, command = "jms-job-queue:successful-cmd") finished with exit code 0.

OUTPUT
            ,
            $output
        );
    }

    /**
     * @group priority
     */
    public function testJobsAreStartedInCreationOrderWhenPriorityIsEqual(): void
    {
        $job = new Job('jms-job-queue:successful-cmd', array(), true, Job::DEFAULT_QUEUE, Job::PRIORITY_HIGH);
        $this->em->persist($job);

        $job = new Job('jms-job-queue:successful-cmd', array(), true, Job::DEFAULT_QUEUE, Job::PRIORITY_HIGH);
        $this->em->persist($job);
        $this->em->flush();

        $output = $this->runConsoleCommand(array('--max-runtime' => 4, '--worker-name' => 'test'));

        $this->assertEquals(
            <<<OUTPUT
Started Job(id = 1, command = "jms-job-queue:successful-cmd").
Job(id = 1, command = "jms-job-queue:successful-cmd") finished with exit code 0.
Started Job(id = 2, command = "jms-job-queue:successful-cmd").
Job(id = 2, command = "jms-job-queue:successful-cmd") finished with exit code 0.

OUTPUT
            ,
            $output
        );
    }

    /**
     * @group exception
     */
    public function testExceptionStackTraceIsSaved(): void
    {
        $job = new Job('jms-job-queue:throws-exception-cmd');
        $this->em->persist($job);
        $this->em->flush($job);

        $this->assertNull($job->getStackTrace());
        $this->assertNull($job->getMemoryUsage());
        $this->assertNull($job->getMemoryUsageReal());

        $this->runConsoleCommand(array('--max-runtime' => 1, '--worker-name' => 'test'));

        $this->assertNotNull($job->getStackTrace());
        $this->assertNotNull($job->getMemoryUsage());
        $this->assertNotNull($job->getMemoryUsageReal());
    }

    protected function setUp(): void
    {
        $this->createClient(array('config' => 'persistent_db.yml'));

        if (is_file($databaseFile = self::$kernel->getCacheDir() . '/database.sqlite')) {
            unlink($databaseFile);
        }

        $this->importDatabaseSchema();

        $this->app = new Application(self::$kernel);
        $this->app->setAutoExit(false);
        $this->app->setCatchExceptions(false);

        $this->em = self::$kernel->getContainer()->get('doctrine')->getManagerForClass(Job::class);
    }
}

class MemoryOutput extends Output
{
    private $output;

    public function getOutput()
    {
        return $this->output;
    }

    protected function doWrite($message, $newline): void
    {
        $this->output .= $message;

        if ($newline) {
            $this->output .= "\n";
        }
    }
}
