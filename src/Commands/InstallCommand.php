<?php

namespace PrevailExcel\BotManStudioInstaller\Commands;

use PrevailExcel\BotManStudioInstaller\BotmanSetup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class InstallCommand extends Command
{
    protected static $defaultName = 'new';

    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Creates a new BotMan Studio project with any Laravel version of your choice')
            ->addArgument('projectname', InputArgument::REQUIRED, 'The name of the project')
            ->addArgument('laravelVersion', InputArgument::OPTIONAL, 'The Laravel version to install (e.g., 10, 11)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectName = $input->getArgument('projectname');
        $laravelVersion = $input->getArgument('laravelVersion');

        // Validate the project name argument
        if (!$projectName) {
            $output->writeln('<error>Project name is required.</error>');
            return Command::FAILURE;
        }

        // Handle case where project directory exists but is not a valid Laravel project
        if (is_dir($projectName)) {
            if (file_exists("{$projectName}/artisan")) {
                chdir($projectName);
                $laravelVersion = $this->getLaravelVersion($output);

                if ($laravelVersion) {
                    $output->writeln("<info>Laravel {$laravelVersion} is already installed in '{$projectName}'.</info>");
                    $output->writeln("<info>Detected Laravel version: {$laravelVersion}</info>");
                } else {
                    $output->writeln("<error>Unable to detect Laravel version in '{$projectName}'. Exiting...</error>");
                    return Command::FAILURE;
                }
            } else {
                // If the directory is not a Laravel project, ask if the user wants to overwrite it
                $output->writeln("<error>The folder '{$projectName}' exists but does not contain a Laravel project (missing artisan file).</error>");
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion('Do you want to overwrite it and create a new Laravel project? (y/n): ', false);

                if ($helper->ask($input, $output, $question)) {
                    // Delete the existing directory and install Laravel
                    $this->deleteDirectory($projectName);
                    $output->writeln("<info>Existing files deleted. Installing Laravel...</info>");
                    $this->installLaravel($input, $output, $projectName, $laravelVersion);
                    chdir($projectName);
                } else {
                    $output->writeln("<info>Operation cancelled. No changes made to '{$projectName}'.</info>");
                    return Command::SUCCESS;
                }
            }
        } else {
            // If the project directory doesn't exist, install Laravel
            $output->writeln("<info>Installing Laravel...</info>");
            $this->installLaravel($input, $output, $projectName, $laravelVersion);
            chdir($projectName);
        }

        // Install BotMan dependencies
        $output->writeln("Installing BotMan dependencies...");
        exec("composer require botman/botman botman/driver-web botman/driver-telegram prevailexcel/botman-tinker");

        // Set up BotMan configuration
        $output->writeln("Setting up BotMan configuration...");
        try {
            BotmanSetup::start($laravelVersion);
            $output->writeln("<info>BotMan Studio setup completed successfully!</info>");
        } catch (\Exception $e) {
            $output->writeln("<error>Error during BotMan setup: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        // Completion message and opening the project in VSCode
        $output->writeln("<info>BotMan Studio installation complete! Navigate to '{$projectName}' to start building your bot.</info>");
        $this->openProjectInVSCode();

        return Command::SUCCESS;
    }

    // Open the project in VSCode if installed
    protected function openProjectInVSCode()
    {
        $os = strtoupper(substr(PHP_OS, 0, 3));

        if ($os === 'WIN') {
            $vsCodeInstalled = shell_exec('where code');
        } else {
            $vsCodeInstalled = shell_exec('which code');
        }

        if ($vsCodeInstalled)
            shell_exec('code .');
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        if (file_exists(getcwd() . '/composer.phar')) {
            return '"' . PHP_BINARY . '" composer.phar';
        }

        return 'composer';
    }

    // Install Laravel version based on user input
    protected function installLaravel($input, $output, $projectName, $laravelVersion)
    {
        // Prompt for Laravel version if not provided
        if (!$laravelVersion) {
            $output->writeln("No Laravel version provided.");
            $helper = $this->getHelper('question');
            $question = new Question("Enter Laravel version (e.g., 10, 11) or press Enter for the latest: ", 'latest');
            $laravelVersion = $helper->ask($input, $output, $question);
        }

        $output->writeln("Installing Laravel version: {$laravelVersion}");

        // Check if Composer is installed
        $composer = $this->findComposer();
        if (!shell_exec('composer --version')) {
            $output->writeln('<error>Composer not found. Please install Composer before proceeding.</error>');
            return Command::FAILURE;
        }

        // Determine the correct install command
        $installCommand = $laravelVersion === 'latest'
            ? "$composer create-project --prefer-dist laravel/laravel {$projectName}"
            : "$composer create-project --prefer-dist laravel/laravel={$laravelVersion} {$projectName}";

        passthru($installCommand, $resultCode);

        if ($resultCode !== 0) {
            $output->writeln("<error>Failed to install Laravel. Please check Composer and internet connection.</error>");
            return Command::FAILURE;
        }
    }

    // Get the Laravel version from the project
    protected function getLaravelVersion(OutputInterface $output)
    {
        exec('php artisan --version --no-ansi', $outputLines, $resultCode);

        if ($resultCode !== 0 || empty($outputLines)) {
            $output->writeln("<error>Unable to detect Laravel version using artisan.</error>");
            return null;
        }

        if (preg_match('/Laravel Framework (\d+\.\d+\.\d+)/', $outputLines[0], $matches)) {
            return explode('.', $matches[1])[0];
        }

        return null;
    }

    // Delete the specified directory and its contents
    protected function deleteDirectory(string $dir)
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $filePath = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($filePath) ? $this->deleteDirectory($filePath) : unlink($filePath);
        }
        rmdir($dir);
    }
}
