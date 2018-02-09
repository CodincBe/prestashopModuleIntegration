<?php

namespace Codinc\ModuleUpgrade\Command;

use Codinc\ModuleUpgrade\Adapter\ObjectModelDefinition;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use InvalidArgumentException;
use OutOfBoundsException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Class DatabaseUpgradeCommand.
 *
 * @package Codinc\ModuleUpgrade\Command
 */
class DatabaseUpgradeCommand extends ContainerAwareCommand
{
    /**
     * @return void
     * @throws InvalidArgumentException
     */
    protected function configure()
    {
        $this
            ->setName('codinc:module:upgrade:database')
            ->setDescription('Find and fix database inconsistencies for a given module')
            ->addArgument('module', InputArgument::REQUIRED, 'The module to check');

        $this->addOption('force', null, InputOption::VALUE_NONE, 'Perform the database changes without asking permission');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws DBALException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Trigger autoload
        $moduleName = $input->getArgument('module');
        $rootDir = $this->getContainer()->get('kernel')->getRootDir() . '/../';
        $modulePath = $rootDir . '/modules/' . $moduleName;

        // First of all: retrieve all ObjectModel classes from the module
        $objectModels = $this->fetchObjectModels($output, $modulePath, $moduleName);
        $output->writeln('<info>Detected ' . count($objectModels) . ' objectModels</info>', OutputInterface::VERBOSITY_VERBOSE);
        if (empty($objectModels)) {
            $output->writeln('<info>No ObjectModel classes were found in the module, halting execution</info>');
            return;
        }

        // Now run over each entity, read the metadata
        /** @var Connection $connection */
        $connection = $this->getContainer()->get('doctrine')->getConnection();
        $diff = $this->getSchemaDiff($output, $connection, $objectModels);

        $sqlStatements = $diff->toSaveSql($connection->getDatabasePlatform());
        if (empty($sqlStatements)) {
            $output->writeln("<info>No differences detected.</info>");
            return;
        }
        $output->writeln($sqlStatements);

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        if (
            $input->getOption('force') ||
            $helper->ask($input, $output, new Question('<question>Do you want to apply this on the database?<question> - <comment>(y/n)</comment>: ')) === 'y'
        ) {
            $output->writeln('<info>Executing without deletes or drops</info>');
            $progressBar = new ProgressBar($output, count($sqlStatements));
            $progressBar->start();
            $progressBar->setRedrawFrequency(20);
            foreach ($sqlStatements as $sql) {
                $connection->prepare($sql)->execute();
                $progressBar->advance();
            }
            $progressBar->finish();
            $output->writeln('');
            $output->writeln('<info>Done</info>');
        }
    }

    /**
     *
     * @param OutputInterface $output
     * @param $modulePath
     * @param $moduleName
     * @return ReflectionClass[]
     */
    protected function fetchObjectModels(OutputInterface $output, $modulePath, $moduleName)
    {
        $entities = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modulePath, RecursiveDirectoryIterator::SKIP_DOTS));
        /** @var SplFileInfo $node */
        foreach ($iterator as $node) {
            if (!$node->isFile() || strtolower($node->getExtension()) !== 'php') {
                // We only want php files
                continue;
            }
            // Skip indexes and module definition
            if (in_array(strtolower($node->getFilename()), array('index.php', $moduleName . '.php'))) {
                // We can safely skip these
                continue;
            }

            try {
                $className = $node->getBasename('.' . $node->getExtension());
                $output->write("<comment>Visiting {$className}: </comment>", OutputInterface::VERBOSITY_VERBOSE);
                // Make sure it's loaded
                require_once($node->getRealPath());

                $reflection = new ReflectionClass($className);
                if ($reflection->isSubclassOf('ObjectModel') && $reflection->hasProperty('definition')) {
                    $output->writeln("Added", OutputInterface::VERBOSITY_VERBOSE);
                    $entities[] = $reflection;
                } else {
                    $output->writeln("N/A", OutputInterface::VERBOSITY_VERBOSE);
                }
            } catch (ReflectionException $e) {
                // Could not detect it, skipping for now
                $output->writeln("N/A", OutputInterface::VERBOSITY_VERBOSE);
                $output->writeln("  <comment>Could not read class {$node->getFilename()}: {$e->getMessage()}</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE);
            }
        }

        return $entities;
    }

    /**
     *
     * @param OutputInterface $output
     * @param Connection $connection
     * @param $objectModels
     * @return SchemaDiff
     */
    protected function getSchemaDiff(OutputInterface $output, Connection $connection, $objectModels)
    {
        try {
            $namingStrategy = $this->getContainer()->get('prestashop.database.naming_strategy');
        } catch (ServiceNotFoundException $e) {
            $namingStrategy = new DefaultNamingStrategy();
        }

        $currentSchema = $connection->getSchemaManager()->createSchema();
        $tables = $currentSchema->getTables();

        /** @var ReflectionClass $entity */
        foreach ($objectModels as $entity) {
            try {
                $entityDefinition = ObjectModelDefinition::fromDefinitionCollection(
                    $namingStrategy,
                    $entity->getProperty('definition')->getValue()
                );
                $table = $entityDefinition->getMainTable();
                $tables["{$currentSchema->getName()}.{$table->getName()}"] = $table;
                if ($entityDefinition->getLanguageTable()) {
                    $table = $entityDefinition->getLanguageTable();
                    $tables["{$currentSchema->getName()}.{$table->getName()}"] = $table;
                }
            } catch (OutOfBoundsException $e) {
                $output->writeln("  <error>Could not read definition of {$entity->getFilename()}: {$e->getMessage()}</error>");
            } catch (DBALException $e) {
                $output->writeln("  <error>Could not read definition of {$entity->getFilename()}: {$e->getMessage()}</error>");
            }
        }
        $newSchema = new Schema($tables);

        // The metadata is loaded, time to check the difference with the actual schema.
        return (new Comparator())->compare($currentSchema, $newSchema);
    }

}
