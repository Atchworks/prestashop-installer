<?php

namespace Gskema\PrestaShop\Installer\Console;

use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class NewCommand
 * @package PrestaShop\Installer\Console
 */
class NewCommand extends Command
{
    /** @var \Symfony\Component\Filesystem\Filesystem */
    protected $filesystem = null;

    /** @var \GuzzleHttp\Client */
    protected $client = null;

    /** @var string */
    protected $wd = null;

    /**
     * NewCommand constructor.
     *
     * @param Client|null     $client
     * @param Filesystem|null $fs
     * @param string|null     $wd
     */
    public function __construct(Client $client = null, Filesystem $fs = null, $wd = null)
    {
        $this->client     = $client === null ? new Client() : $client;
        $this->filesystem = $fs === null ? new Filesystem() : $fs;
        $this->wd         = $wd === null ? getcwd() : $wd;

        $this->wd = rtrim($this->wd, '/');

        parent::__construct();
    }

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('new')
            ->setDescription('Create a new PrestaShop application.')
            ->addArgument('folder', InputArgument::OPTIONAL)
            ->addOption(
                'release',
                'r',
                InputOption::VALUE_REQUIRED,
                'Specify PrestaShop release version to download. E.g. 1.6.1.3'
            )
            ->addOption(
                'fixture',
                null,
                InputOption::VALUE_REQUIRED,
                'Replaces demo product, category, banner pictures. Available values: [\'starwars\', \'got\', \'tech\']'
            );
    }

    /**
     * Execute the command.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface  $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $folder = $input->getArgument('folder');

        // Disallow absolute paths (for now) and going down ../ directories
        if (strpos($folder, '/') === 0 || strpos($folder, '..') !== false || strpos($folder, '\\') !== false) {
            throw new InvalidArgumentException('Invalid folder argument');
        }

        $directory = $this->wd.'/'.$folder;

        $isFolderEmpty = $this->verifyApplicationDoesNotExist($directory);
        if (!$isFolderEmpty) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'Directory '.$directory.' is not empty. Would you like to install anyway [y/n]?',
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                return;
            }
        }

        $output->writeln('<info>Creating PrestaShop application...</info>');

        $downloadUrl = $this->getDownloadUrl($input->getOption('release'));

        $output->writeln('<info>Downloading from URL: '.$downloadUrl.'</info>');

        $zipFile = $this->makeFilename();
        $tmpFolder = $this->makeFolderName();

        $this->download($zipFile, $downloadUrl);

        $output->writeln('<info>Extracting files to ./'.$folder.'/...</info>');

        $this->extract($zipFile, $tmpFolder);
        $this->moveFiles($tmpFolder, $directory);

        $fixture = $this->getFixtureOption($input);
        if ($fixture) {
            $this->setFixture($fixture, $directory);
        }

        $this->cleanUp($zipFile, $tmpFolder);

        $output->writeln('<comment>PrestaShop is ready to be installed!</comment>');
        $output->writeln('<comment>To proceed with the installation, open the website in your browser or '
            .'run CLI installer script: php ./'.$folder.'/install/index_cli.php</comment>');
    }

    /**
     * Returns fixture option
     *
     * @param InputInterface $input
     * @return string
     */
    protected function getFixtureOption(InputInterface $input)
    {
        $fixture = strtolower(trim($input->getOption('fixture')));

        if (in_array($fixture, array('starwars', 'got', 'tech'))) {
            return $fixture;
        }

        return '';
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param  string  $directory
     * @return bool
     */
    protected function verifyApplicationDoesNotExist($directory)
    {
        if (!$this->filesystem->exists($directory)) {
            return true;
        } elseif (count(scandir($directory)) > 2) {
            return false;
        }

        return true;
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeFilename()
    {
        return $this->wd.'/prestashop_'.md5(time().uniqid()).'.zip';
    }

    /**
     * Generate a random temporary folder.
     *
     * @return string
     */
    protected function makeFolderName()
    {
        return $this->wd.'/prestashop_'.md5(time().uniqid());
    }

    /**
     * Return PrestaShop download link
     *
     * @param string $version
     * @return string
     * @throws RuntimeException
     */
    protected function getDownloadUrl($version)
    {
        // If a specific version is requested, download it
        if (!empty($version)) {
            return sprintf('http://www.prestashop.com/download/releases/prestashop_%s.zip', $version);
        }

        // Else, get the latest version

        // Get official PrestaShop XML containing version info
        $xmlBody = $this->client->get('https://api.prestashop.com/xml/channel.xml')->getBody();

        $xml = simplexml_load_string($xmlBody);

        // Get latest stable version download URL
        $latestVersion = false;
        $latestDownloadUrl = false;
        foreach ($xml->channel as $channel) {
            if ($channel['name'] == 'stable' && $channel['available'] == '1') {
                foreach ($channel->branch as $branch) {
                    if (version_compare((string)$branch->num, $latestVersion) >= 0) {
                        $latestVersion = (string)$branch->num;
                        $latestDownloadUrl = (string)$branch->download->link;
                    }
                }
                break;
            }
        }

        if (empty($latestDownloadUrl)) {
            throw new RuntimeException('Could not find latest PrestaShop version download URL!');
        }

        return $latestDownloadUrl;
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string  $zipFile
     * @param  string  $downloadUrl
     * @return $this
     */
    protected function download($zipFile, $downloadUrl)
    {
        $response = $this->client->get($downloadUrl);

        file_put_contents($zipFile, $response->getBody());

        return $this;
    }

    /**
     * Extract the zip file into the given directory.
     *
     * @param  string  $zipFile
     * @param  string  $directory
     * @return $this
     */
    protected function extract($zipFile, $directory)
    {
        $archive = new ZipArchive();

        $archive->open($zipFile);
        $archive->extractTo($directory);
        $archive->close();

        return $this;
    }

    /**
     * Copies fixture picture to PrestaShop installation
     *
     * @param string $fixture
     * @param string $directory
     * @return $this
     */
    protected function setFixture($fixture, $directory)
    {
        $fixtureDir = __DIR__.'/fixtures/'.$fixture;

        if (is_dir($fixtureDir)) {
            $this->filesystem->mirror($fixtureDir, $directory, null, array('override' => true));
        }

        return $this;
    }

    /**
     * Move extracted PrestaShop files to destination directory
     *
     * @param  string  $tmpDirectory
     * @param  string  $directory
     * @return $this
     */
    protected function moveFiles($tmpDirectory, $directory)
    {
        $this->filesystem->mirror($tmpDirectory.'/prestashop', $directory, null, array('override' => true));

        return $this;
    }

    /**
     * Clean-up the temporary directory and the zip file.
     *
     * @param  string  $zipFile
     * @param  string  $tmpDirectory
     * @return $this
     */
    protected function cleanUp($zipFile, $tmpDirectory)
    {
        $this->filesystem->remove($zipFile);
        $this->filesystem->remove($tmpDirectory);

        return $this;
    }
}
