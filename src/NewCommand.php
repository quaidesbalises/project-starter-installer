<?php

namespace Qdb\StarterInstaller\Console;


use mysqli;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NewCommand extends Command
{

    protected $input;
    protected $output;
    protected $database;
    protected $name;
    protected $url;
    protected $directory;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new starter project')
            ->addArgument('name', InputArgument::OPTIONAL)
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }


    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $io = new SymfonyStyle($input, $output);
        $this->input = $input;
        $this->output = $output;
        $this->name = $input->getArgument('name');
        $this->directory = getcwd();

        $project = $io->ask('What is the full name of the projet ?');
        $output->writeln('<info>Creating the projet :</info>');

        $this->installProject();
        $this->initProject($project);

        $output->writeln('<comment>Project is ready to start !</comment>');
        $output->writeln('<info>'.$this->url.'</info>');

        return 0;
    }



    /**
     * Run the given commands
     *
     * @param array $commands
     * @return void
     */
    private function runCommands($commands, $quiet = true)
    {
        $input = $this->input;
        $output = $this->output;

        if ($input->getOption('no-ansi')) {
            $commands = array_map(function ($value) {
                return $value.' --no-ansi';
            }, $commands);
        }

        if ($input->getOption('quiet') || $quiet) {
            $commands = array_map(function ($value) {
                return $value.' --quiet';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), $this->directory, null, null, null);

        $process->run(function ($type, $line) use ($output) {
            $output->write($line);
        });
    }


    /**
     * Install Laravel starter project from the repo
     *
     * @return void
     */
    private function installProject()
    {
        $commands = [
            'git clone https://github.com/quaidesbalises/project-starter '.$this->name,
            'cd '.$this->name,
            'composer install --no-scripts',
            'composer run-script post-root-package-install',
            'composer run-script post-create-project-cmd',
            'composer run-script post-autoload-dump',
        ];

        $this->output->writeln('Clone and install the starter ...');
        $this->runCommands($commands);
    }

    /**
     * Init the project : Set env variables and the database
     *
     * @param string $project
     * @return void
     */
    private function initProject($project)
    {
        // Create database
        $this->output->writeln('Create the database ...');
        $this->createDatabase();

        // Update env file :
        $this->url = sprintf('http://%s.test', $this->name);
        $env = sprintf('%s/%s/.env', getcwd(), $this->name);

        $values = [
            'APP_NAME' => $project,
            'DB_DATABASE' => $this->database,
            'APP_URL' => $this->url,
            'MAIL_FROM_ADDRESS' => sprintf('contact@%s.fr', $this->name),
        ];

        $lines = file_get_contents($env);
        
        foreach($values as $key => $value) 
        {
            $pattern = strpos($value, ' ') !== false ? '%s="%s"' : '%s=%s';
            $line = sprintf($pattern, $key, $value);
            $lines = str_replace("$key=", $line, $lines);
        }

        file_put_contents($env, $lines);


        // Npm
        $commands = [
            'cd '.$this->name,
            'npm install &>/dev/null'
        ];

        $this->output->writeln('Install npm dependencies ...');
        $this->runCommands($commands);

        
        // Artisan
        $commands = [
            'cd '.$this->name,
            'php artisan storage:link',
            'php artisan migrate:fresh --seed'
        ];

        $this->output->writeln('Lauch migrations and seedings ...');
        $this->runCommands($commands);


        // Git
        $commands = [
            'cd '.$this->name,
            'rm -rf .git',
            'git init',
            'git add .',
            'git commit -m "First commit"'
        ];

        $this->output->writeln('Git init ...');
        $this->runCommands($commands, false);
    }

    /**
     * Create the database
     *
     * @return void
     */
    private function createDatabase()
    {
        $client = new mysqli('localhost', 'root', '');

        if ($client->connect_error) {
            $this->output->writeln('Connection failed: '.$client->connect_error);
        }

        $name = $this->database ?: $this->name;
        $this->database = str_replace('-', '_', $name);
        $sql = sprintf("CREATE DATABASE %s", $this->database);

        if ($client->query($sql) != TRUE) {
            
            if($client->errno == "1007") {
                $this->database = sprintf('%s_%s', $this->name, time());
                return $this->createDatabase();
            }

            $this->output->writeln('Error creating database: '.$client->error);
        }

        $client->close();
    }
}
